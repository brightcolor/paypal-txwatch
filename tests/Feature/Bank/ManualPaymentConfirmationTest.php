<?php

namespace Tests\Feature\Bank;

use App\Filament\Resources\BankTransactionResource\Pages\ListBankTransactions;
use App\Filament\Resources\EnableBankingJournalResource\Pages\ListEnableBankingJournalEntries;
use App\Models\AuditLogEntry;
use App\Models\BankTransaction;
use App\Models\EnableBankingJournalEntry;
use App\Models\Event;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\PretixPaymentConfirmation;
use App\Models\User;
use App\Services\Bank\BankPretixReporter;
use App\Services\EnableBanking\JournalPaymentReporter;
use App\Services\EnableBanking\JournalWriter;
use App\Services\EnableBanking\PurposeMatcher;
use App\Services\Pretix\PaymentEvidence;
use App\Services\Pretix\PaymentMarker;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Marking a transfer-paid order as paid BY HAND.
 *
 * The automation needs three things the real cases keep failing to provide: an
 * event that exists, its switch turned on, and an order code the recognition found
 * in the purpose text by itself. Where any one is missing the entry sat in "Zu tun"
 * with no way to finish it - the guest had paid, and TxWatch could only say why it
 * would not act.
 *
 * WHAT IS HELD HERE, beyond that the button works:
 *  - the same checks still run; a click skips none of them,
 *  - the one thing a person may do that the automation may not is an accepted
 *    amount deviation - and the automation cannot reach it even by asking,
 *  - every attempt names who made it, in the record AND in the journal protocol,
 *  - "pretix answered 200" is not accepted as proof; pretix is asked again.
 */
class ManualPaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private PretixConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = PretixConnection::create([
            'name' => 'Verein', 'base_url' => 'https://pretix.eu', 'organizer_slug' => 'verein',
            'api_token' => 'tok', 'is_active' => true,
        ]);
    }

    private function person(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['name' => 'Rita Kassenwart']);
        $user->assignRole(Role::findByName('admin'));
        $this->actingAs($user);

        return $user;
    }

    private function event(bool $an): Event
    {
        return Event::create([
            'name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest',
            'is_active' => true, 'auto_mark_paid' => $an,
        ]);
    }

    private function order(string $code, float $total, string $status = 'n'): PretixOrder
    {
        return PretixOrder::create([
            'pretix_connection_id' => $this->connection->id, 'event_slug' => 'sommerfest',
            'order_code' => $code, 'status' => $status, 'payment_provider' => 'banktransfer',
            'total' => $total, 'currency' => 'EUR', 'url' => 'https://x/', 'raw_payload' => [],
        ]);
    }

    /**
     * pretix holds an open transfer payment, accepts the confirmation and reports
     * the order as paid afterwards.
     *
     * The order of the patterns matters: Http::fake ADDS rules and the FIRST match
     * answers, so the catch-all has to come last.
     */
    private function fakePretix(string $code, string $amount, string $afterwards = 'p', int $localId = 1): void
    {
        Http::fake([
            "*/orders/{$code}/payments/" => Http::response(['results' => [
                ['local_id' => $localId, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => $amount],
            ]], 200),
            "*/payments/{$localId}/confirm/" => Http::response([], 200),
            "*/orders/{$code}/" => Http::response(['code' => $code, 'status' => $afterwards], 200),
            '*' => Http::response([], 200),
        ]);
    }

    private function pull(float $amount, string $purpose, string $ref = 'R1'): EnableBankingJournalEntry
    {
        app(JournalWriter::class)->record([
            EnableBankingJournalTest::entry(['amount' => $amount, 'purpose' => $purpose, 'bank_ref' => $ref]),
        ]);

        return EnableBankingJournalEntry::query()->where('bank_ref', $ref)->firstOrFail();
    }

    /**
     * THE CASE THE REQUEST WAS ABOUT: the guest transferred, the automation is off,
     * and a person finishes it.
     */
    public function test_a_person_reports_an_entry_although_the_switch_is_off(): void
    {
        $this->fakePretix('HANDX', '25.00');
        $user = $this->person();
        $this->event(false);
        $this->order('HANDX', 25.00);

        $entry = $this->pull(25.00, 'Ueberweisung HANDX');

        $confirmation = app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $this->assertTrue($confirmation->succeeded(), 'Der Schalter gilt der Automatik, nicht dem Menschen.');
        $this->assertSame('p', PretixOrder::first()->status);
        $this->assertSame('p', $entry->fresh()->pretix_order_status);
        $this->assertFalse($confirmation->automatic);
        $this->assertSame($user->id, $confirmation->user_id, 'Wer es war, muss am Nachweis stehen.');
    }

    /** The journal protocol names the person - in the sentence, not only in a field. */
    public function test_the_journal_protocol_names_the_person(): void
    {
        $this->fakePretix('WHOXX', '25.00');
        $this->person();
        $this->event(false);
        $this->order('WHOXX', 25.00);

        $entry = $this->pull(25.00, 'Zahlung WHOXX');
        app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $messages = $entry->fresh()->events->pluck('message')->implode(' ');

        $this->assertStringContainsString('Von Hand durch Rita Kassenwart', $messages);
        $this->assertStringContainsString('auf BEZAHLT gesetzt', $messages);
        $this->assertStringContainsString('WHOXX', $messages);
    }

    /** A refused attempt is protocolled too - a click must never be silent. */
    public function test_a_refused_attempt_is_protocolled_with_the_person(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->person();
        $this->event(false);
        $this->order('SHUTX', 25.00, status: 'p');

        $entry = $this->pull(25.00, 'Zahlung SHUTX');
        $confirmation = app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $this->assertFalse($confirmation->succeeded());
        $this->assertSame(PretixPaymentConfirmation::REASON_NOT_OPEN, $confirmation->reason);

        $messages = $entry->fresh()->events->pluck('message')->implode(' ');
        $this->assertStringContainsString('Von Hand durch Rita Kassenwart', $messages);
        $this->assertStringContainsString('Nicht an pretix gemeldet', $messages);
    }

    /**
     * TWO CLICKS ARE TWO RECORDS.
     *
     * The de-duplication exists against a rule that repeats itself four times a day.
     * Folding a second person's attempt into the first one's row would attribute it
     * to whoever clicked first.
     */
    public function test_every_hand_triggered_attempt_is_recorded(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->person();
        $this->event(false);
        $this->order('TWICE', 25.00, status: 'c');

        $entry = $this->pull(25.00, 'Zahlung TWICE');

        app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);
        app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $this->assertSame(2, PretixPaymentConfirmation::count());
    }

    /** Someone writing into a guest's order also appears in the audit trail. */
    public function test_the_attempt_lands_in_the_audit_trail(): void
    {
        $this->fakePretix('AUDIT', '25.00');
        $user = $this->person();
        $this->event(false);
        $this->order('AUDIT', 25.00);

        $entry = $this->pull(25.00, 'Zahlung AUDIT');
        app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $log = AuditLogEntry::query()
            ->where('subject_type', PretixPaymentConfirmation::class)
            ->latest('id')->first();

        $this->assertNotNull($log, 'Eine Meldung von Hand gehört ins Prüfprotokoll.');
        $this->assertSame($user->id, $log->causer_id);
        $this->assertStringContainsString('AUDIT', $log->description);
        $this->assertSame('AUDIT', $log->properties['order_code']);
    }

    /** The automation writes no audit entry - it has nobody to name. */
    public function test_the_automation_does_not_fill_the_audit_trail(): void
    {
        $this->fakePretix('AUTOX', '25.00');
        $this->person();
        $this->event(true);
        $this->order('AUTOX', 25.00);

        $this->pull(25.00, 'Zahlung AUTOX');
        app(JournalPaymentReporter::class)->report();

        $this->assertSame(0, AuditLogEntry::query()
            ->where('subject_type', PretixPaymentConfirmation::class)->count());
    }

    /**
     * HTTP 200 IS NOT PROOF. pretix can accept the confirmation and leave the order
     * pending - and a "gemeldet" row for a guest without tickets is the failure
     * nobody goes looking for.
     */
    public function test_an_order_that_stays_open_after_the_confirmation_is_a_failure(): void
    {
        $this->fakePretix('LIEDX', '25.00', afterwards: 'n');
        $this->person();
        $this->event(false);
        $this->order('LIEDX', 25.00);

        $entry = $this->pull(25.00, 'Zahlung LIEDX');
        $confirmation = app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $this->assertSame(PretixPaymentConfirmation::OUTCOME_FAILED, $confirmation->outcome);
        $this->assertSame(PretixPaymentConfirmation::REASON_NOT_CONFIRMED, $confirmation->reason);
        $this->assertSame('n', PretixOrder::first()->status, 'Die lokale Kopie darf die Lüge nicht übernehmen.');
    }

    /** And a check that DID run says so, in the record. */
    public function test_a_verified_confirmation_says_that_it_was_verified(): void
    {
        $this->fakePretix('CHECK', '25.00');
        $this->person();
        $this->event(false);
        $this->order('CHECK', 25.00);

        $entry = $this->pull(25.00, 'Zahlung CHECK');
        $confirmation = app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $this->assertTrue($confirmation->succeeded());
        $this->assertStringContainsString('nachgeprüft', $confirmation->message);
    }

    /** A check that could not run is never silently read as a confirmation. */
    public function test_an_unreachable_check_is_named_as_such(): void
    {
        Http::fake([
            '*/orders/QUIET/payments/' => Http::response(['results' => [
                ['local_id' => 7, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => '25.00'],
            ]], 200),
            '*/payments/7/confirm/' => Http::response([], 200),
            '*/orders/QUIET/' => Http::response([], 500),
            '*' => Http::response([], 200),
        ]);

        $this->person();
        $this->event(false);
        $this->order('QUIET', 25.00);

        $entry = $this->pull(25.00, 'Zahlung QUIET');
        $confirmation = app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $this->assertTrue($confirmation->succeeded());
        $this->assertStringContainsString('Nachprüfung war nicht möglich', $confirmation->message);
    }

    /** By default a deviating amount stops a hand-triggered report as well. */
    public function test_a_deviating_amount_is_refused_by_default(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->person();
        $this->event(false);
        $this->order('PARTS', 60.00);

        $entry = $this->pull(25.00, 'Anzahlung PARTS');
        $confirmation = app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false);

        $this->assertSame(PretixPaymentConfirmation::REASON_AMOUNT, $confirmation->reason);
        Http::assertNothingSent();
    }

    /** Accepted deliberately, it goes through - under its own reason, with both numbers. */
    public function test_a_deviating_amount_can_be_accepted_by_hand(): void
    {
        $this->fakePretix('FEEXX', '60.00');
        $this->person();
        $this->event(false);
        $this->order('FEEXX', 60.00);

        $entry = $this->pull(58.50, 'Ueberweisung FEEXX abzueglich Gebuehr');

        $confirmation = app(JournalPaymentReporter::class)
            ->reportEntry($entry, automatic: false, allowAmountMismatch: true);

        $this->assertTrue($confirmation->succeeded());
        $this->assertSame(PretixPaymentConfirmation::REASON_FORCED_AMOUNT, $confirmation->reason);
        $this->assertStringContainsString('60,00', $confirmation->message);
        $this->assertStringContainsString('58,50', $confirmation->message);
        $this->assertStringContainsString('Von Hand', $confirmation->reasonText());
    }

    /**
     * THE AUTOMATION CAN NEVER FORCE IT, not even by asking for it.
     *
     * A rule that could accept a deviation would accept every deviation it ever
     * meets. Disarmed inside the decision itself, so no caller can arm it.
     */
    public function test_the_automation_can_never_accept_a_deviating_amount(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->event(true);
        $this->order('NEVER', 60.00);

        $confirmation = app(PaymentMarker::class)->mark(
            new PaymentEvidence(
                source: PretixPaymentConfirmation::SOURCE_JOURNAL,
                orderCode: 'NEVER',
                amount: 25.00,
            ),
            automatic: true,
            allowAmountMismatch: true,
        );

        $this->assertSame(PretixPaymentConfirmation::OUTCOME_SKIPPED, $confirmation->outcome);
        $this->assertSame(PretixPaymentConfirmation::REASON_AMOUNT, $confirmation->reason);
        Http::assertNothingSent();
    }

    /** An order already reported once is not reported again, click or no click. */
    public function test_an_already_confirmed_order_is_refused_by_hand_too(): void
    {
        $this->fakePretix('ONCEX', '25.00');
        $this->person();
        $this->event(false);
        $this->order('ONCEX', 25.00);

        $first = $this->pull(25.00, 'Zahlung ONCEX', 'R1');
        $this->assertTrue(app(JournalPaymentReporter::class)->reportEntry($first, automatic: false)->succeeded());

        // A second credit carrying the same code arrives.
        $second = $this->pull(25.00, 'Nochmal ONCEX', 'R2');
        PretixOrder::first()->forceFill(['status' => 'n'])->save();

        $confirmation = app(JournalPaymentReporter::class)->reportEntry($second, automatic: false);

        $this->assertFalse($confirmation->succeeded());
        $this->assertSame(PretixPaymentConfirmation::REASON_ALREADY, $confirmation->reason);
    }

    /**
     * THE PROPOSAL THAT NOBODY COULD ACCEPT UNTIL NOW.
     *
     * The recognition finds a code one character off and deliberately does not
     * assign it. Someone can now say "yes, that one" - and the entry keeps that
     * assignment.
     */
    public function test_a_person_can_name_the_order_the_recognition_only_proposed(): void
    {
        $this->fakePretix('R9YBQ', '25.00');
        $this->person();
        $this->event(false);
        $this->order('R9YBQ', 25.00);

        // The guest wrote an S where a 9 belongs - a real, measured case.
        $entry = $this->pull(25.00, 'Ticket RSYBQ Sommerfest');
        $this->assertNull($entry->pretix_order_code, 'Ein Vorschlag ordnet nicht zu.');

        $confirmation = app(JournalPaymentReporter::class)
            ->reportEntry($entry, automatic: false, orderCode: 'r9ybq');

        $this->assertTrue($confirmation->succeeded());

        $entry->refresh();
        $this->assertSame('R9YBQ', $entry->pretix_order_code);
        $this->assertSame(PurposeMatcher::MANUAL, $entry->match_method);
        $this->assertSame('p', $entry->pretix_order_status);

        $messages = $entry->events->pluck('message')->implode(' ');
        $this->assertStringContainsString('Von Hand der Bestellung R9YBQ zugeordnet', $messages);
    }

    /** A mistyped code confirms nothing - and leaves no assignment behind. */
    public function test_a_wrong_code_does_not_stick_to_the_entry(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->person();
        $this->event(false);
        $this->order('R9YBQ', 25.00);

        $entry = $this->pull(25.00, 'Ticket ohne Nummer');

        $confirmation = app(JournalPaymentReporter::class)
            ->reportEntry($entry, automatic: false, orderCode: 'TIPPO');

        $this->assertSame(PretixPaymentConfirmation::REASON_NO_ORDER, $confirmation->reason);
        $this->assertNull($entry->fresh()->pretix_order_code, 'Ein Vertipper darf kein Befund werden.');
    }

    /**
     * A LATER PULL DOES NOT TAKE A HAND ASSIGNMENT BACK.
     *
     * The matcher would re-read the very purpose text that did not carry the code -
     * find nothing, overwrite the method and file a protocol line claiming the
     * picture had changed.
     */
    public function test_a_later_pull_leaves_a_hand_assignment_alone(): void
    {
        $this->fakePretix('R9YBQ', '25.00');
        $this->person();
        $this->event(false);
        $this->order('R9YBQ', 25.00);

        $entry = $this->pull(25.00, 'Ticket RSYBQ Sommerfest');
        app(JournalPaymentReporter::class)->reportEntry($entry, automatic: false, orderCode: 'R9YBQ');

        $lines = $entry->fresh()->events()->count();

        // The same transaction comes down again - three days of overlap per pull.
        $this->pull(25.00, 'Ticket RSYBQ Sommerfest');

        $entry->refresh();
        $this->assertSame('R9YBQ', $entry->pretix_order_code);
        $this->assertSame(PurposeMatcher::MANUAL, $entry->match_method);
        $this->assertSame($lines, $entry->events()->count(), 'Ein erneuter Abruf schreibt hier nichts nach.');
    }

    /** The button in the journal does all of the above, through the screen. */
    public function test_the_button_in_the_journal_reports_the_payment(): void
    {
        $this->fakePretix('CLICK', '25.00');
        $this->person();
        $this->event(false);
        $this->order('CLICK', 25.00);

        $entry = $this->pull(25.00, 'Zahlung CLICK');

        Livewire::test(ListEnableBankingJournalEntries::class)
            ->callTableAction('als_bezahlt_melden', $entry, [
                'order_code' => 'CLICK',
                'allow_amount_mismatch' => false,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('p', PretixOrder::first()->status);
        $this->assertTrue(PretixPaymentConfirmation::first()->succeeded());
    }

    /**
     * A REFUSED CLICK SAYS SO ON THE SCREEN.
     *
     * The refusal names what to change before the next attempt - it is the half of
     * this feature that gets used on a bad day, and it must not disappear silently.
     */
    public function test_the_button_reports_a_refusal_back_to_the_screen(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->person();
        $this->event(false);
        $this->order('SHUTX', 60.00);

        $entry = $this->pull(25.00, 'Anzahlung SHUTX');

        Livewire::test(ListEnableBankingJournalEntries::class)
            ->callTableAction('als_bezahlt_melden', $entry, [
                'order_code' => 'SHUTX',
                'allow_amount_mismatch' => false,
            ])
            ->assertNotified('Nicht gemeldet');

        $this->assertSame('n', PretixOrder::first()->status);
    }

    /** A settled entry offers no button - there is nothing left to report. */
    public function test_the_button_is_hidden_where_it_would_mean_nothing(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->person();
        $this->event(false);
        $this->order('DONEX', 25.00, status: 'p');

        $settled = $this->pull(25.00, 'Zahlung DONEX', 'R1');
        $refund = $this->pull(-25.00, 'Erstattung DONEX', 'R2');

        Livewire::test(ListEnableBankingJournalEntries::class)
            ->assertTableActionHidden('als_bezahlt_melden', $settled)
            ->assertTableActionHidden('als_bezahlt_melden', $refund);
    }

    /**
     * THE SAME BY HAND ON AN IMPORTED STATEMENT LINE.
     *
     * The matcher proposes only where amount AND code line up exactly, so the
     * transfer that most needs a person - a typo in the purpose - never became a
     * proposal and had no button at all.
     */
    public function test_a_person_can_name_the_order_on_a_bank_transaction(): void
    {
        $this->fakePretix('BANKX', '25.00');
        $this->person();
        $this->event(false);
        $this->order('BANKX', 25.00);

        $bank = BankTransaction::create([
            'booked_on' => '2026-05-20', 'valued_on' => '2026-05-20', 'amount' => 25.00,
            'currency' => 'EUR', 'purpose' => 'Ticket ohne brauchbare Nummer',
            'source_format' => 'camt', 'import_hash' => 'h1',
            'reconciliation_status' => BankTransaction::STATUS_UNMATCHED,
            'pretix_report_status' => BankTransaction::REPORT_NONE,
        ]);

        $result = app(BankPretixReporter::class)->confirmManually($bank, orderCode: 'bankx');

        $this->assertTrue($result['success'], $result['message']);

        $bank->refresh();
        $this->assertSame(BankTransaction::REPORT_REPORTED, $bank->pretix_report_status);
        $this->assertSame('BANKX', $bank->pretix_order_code);
        $this->assertSame('sommerfest', $bank->pretix_event_slug);
        $this->assertSame($this->connection->id, $bank->pretix_connection_id);

        $confirmation = PretixPaymentConfirmation::first();
        $this->assertFalse($confirmation->automatic);
        $this->assertNotNull($confirmation->user_id);
    }

    /** The same button on the Kontoumsätze list, through the screen. */
    public function test_the_button_on_the_bank_list_reports_the_payment(): void
    {
        $this->fakePretix('LISTX', '25.00');
        $this->person();
        $this->event(false);
        $this->order('LISTX', 25.00);

        $bank = BankTransaction::create([
            'booked_on' => '2026-05-20', 'valued_on' => '2026-05-20', 'amount' => 25.00,
            'currency' => 'EUR', 'purpose' => 'Ticket ohne brauchbare Nummer',
            'source_format' => 'camt', 'import_hash' => 'h3',
            'reconciliation_status' => BankTransaction::STATUS_UNMATCHED,
            'pretix_report_status' => BankTransaction::REPORT_NONE,
        ]);

        Livewire::test(ListBankTransactions::class)
            ->callTableAction('reportPretix', $bank, [
                'order_code' => 'LISTX',
                'allow_amount_mismatch' => false,
            ])
            ->assertNotified('An pretix gemeldet');

        $this->assertSame(BankTransaction::REPORT_REPORTED, $bank->fresh()->pretix_report_status);
    }

    /** An unknown code changes nothing on the statement line. */
    public function test_an_unknown_code_leaves_the_bank_row_untouched(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->person();

        $bank = BankTransaction::create([
            'booked_on' => '2026-05-20', 'valued_on' => '2026-05-20', 'amount' => 25.00,
            'currency' => 'EUR', 'purpose' => 'Irgendwas', 'source_format' => 'camt', 'import_hash' => 'h2',
            'reconciliation_status' => BankTransaction::STATUS_UNMATCHED,
            'pretix_report_status' => BankTransaction::REPORT_NONE,
        ]);

        $result = app(BankPretixReporter::class)->confirmManually($bank, orderCode: 'NIXDA');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('NIXDA', $result['message']);

        $bank->refresh();
        $this->assertNull($bank->pretix_order_code);
        $this->assertSame(BankTransaction::REPORT_NONE, $bank->pretix_report_status,
            'Eine beantwortete Frage ist kein Fehler an der Zeile.');
    }
}
