<?php

namespace Tests\Feature\Bank;

use App\Models\EnableBankingJournalEntry;
use App\Models\Event;
use App\Models\PretixConnection;
use App\Models\PretixOrder;
use App\Models\PretixPaymentConfirmation;
use App\Services\EnableBanking\JournalPaymentReporter;
use App\Services\EnableBanking\JournalWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Money arrives from the bank pull and settles its order in pretix.
 *
 * THE PATH THE REQUEST WAS ABOUT: "automatically mark paid on incoming payment".
 * It works in journal mode, without anything being booked - marking an order paid
 * and booking money into the accounts are two different things, and only the first
 * one was asked for.
 *
 * Every test here also holds the other half: that nothing happens without the
 * event's switch.
 */
class JournalPaymentReporterTest extends TestCase
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

    /** Pretix holds an open bank payment and accepts the confirmation. */
    private function fakePretix(string $code, string $amount, int $localId = 1): void
    {
        Http::fake([
            "*/orders/{$code}/payments/" => Http::response(['results' => [
                ['local_id' => $localId, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => $amount],
            ]], 200),
            "*/payments/{$localId}/confirm/" => Http::response([], 200),
        ]);
    }

    private function pull(float $amount, string $purpose, string $ref = 'R1'): void
    {
        app(JournalWriter::class)->record([
            EnableBankingJournalTest::entry(['amount' => $amount, 'purpose' => $purpose, 'bank_ref' => $ref]),
        ]);
    }

    /**
     * THE WHOLE POINT: a transfer arrives, the order is settled, nobody typed.
     *
     * And the books stay untouched - `bank_transactions` is still empty afterwards,
     * because journal mode books nothing.
     */
    public function test_an_incoming_transfer_settles_its_order(): void
    {
        $this->fakePretix('ABCDE', '25.00');
        $this->event(true);
        $this->order('ABCDE', 25.00);

        $this->pull(25.00, 'Sommerfest Bestellung ABCDE');

        $counts = app(JournalPaymentReporter::class)->report();

        $this->assertSame(1, $counts['confirmed']);
        $this->assertSame('p', PretixOrder::first()->status, 'Die lokale Bestellung muss der Meldung folgen.');
        $this->assertSame(0, \App\Models\BankTransaction::count(), 'Im Journal-Modus wird nichts gebucht.');

        $confirmation = PretixPaymentConfirmation::first();
        $this->assertTrue($confirmation->succeeded());
        $this->assertSame(PretixPaymentConfirmation::SOURCE_JOURNAL, $confirmation->source);
        $this->assertSame('ABCDE', $confirmation->order_code);
    }

    /**
     * THE RECORD ANSWERS "WHY", not just "when".
     *
     * The evidence has to still be readable in a year: the amount, the purpose that
     * carried the code, and which switch allowed it.
     */
    public function test_the_record_carries_the_evidence(): void
    {
        $this->fakePretix('WHYME', '31.50');
        $event = $this->event(true);
        $this->order('WHYME', 31.50);

        $this->pull(31.50, 'Ueberweisung WHYME Sommerfest');
        app(JournalPaymentReporter::class)->report();

        $c = PretixPaymentConfirmation::first();

        $this->assertSame('Ueberweisung WHYME Sommerfest', $c->purpose);
        $this->assertSame('31.50', (string) $c->amount);
        $this->assertSame($event->id, $c->event_id, 'Der Schalter, der es erlaubt hat, muss benennbar sein.');
        $this->assertTrue($c->automatic);
        $this->assertNull($c->user_id, 'Die Automatik hat keinen Menschen als Urheber.');
        $this->assertStringContainsString('Betrag', $c->reasonText());

        // And the journal entry says so too - that is where someone looks first.
        $messages = EnableBankingJournalEntry::first()->events->pluck('message')->implode(' ');
        $this->assertStringContainsString('An pretix gemeldet', $messages);
        $this->assertStringContainsString('WHYME', $messages);
    }

    /** With the switch off nothing is written to pretix - and the refusal is recorded. */
    public function test_without_the_switch_nothing_is_reported(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->event(false);
        $this->order('QUIET', 25.00);

        $this->pull(25.00, 'Bestellung QUIET');

        $counts = app(JournalPaymentReporter::class)->report();

        $this->assertSame(0, $counts['confirmed']);
        $this->assertSame('n', PretixOrder::first()->status);
        Http::assertNothingSent();

        $c = PretixPaymentConfirmation::first();
        $this->assertSame(PretixPaymentConfirmation::REASON_SWITCH_OFF, $c->reason);
        $this->assertStringContainsString('ausgeschaltet', $c->reasonText());
    }

    /**
     * FOUR PULLS A DAY MUST NOT PRODUCE FOUR IDENTICAL RECORDS.
     *
     * With three days of overlap every unconfirmable entry would otherwise write a
     * dozen "the switch is off" rows a day and bury the ones that say something.
     */
    public function test_an_unchanged_answer_is_not_recorded_again(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->event(false);
        $this->order('AGAIN', 25.00);
        $this->pull(25.00, 'Bestellung AGAIN');

        app(JournalPaymentReporter::class)->report();
        app(JournalPaymentReporter::class)->report();
        app(JournalPaymentReporter::class)->report();

        $this->assertSame(1, PretixPaymentConfirmation::count());

        // The journal protocol must not fill up either.
        $this->assertSame(
            1,
            EnableBankingJournalEntry::first()->events()
                ->where('message', 'like', 'Nicht an pretix gemeldet%')->count(),
        );
    }

    /** Turning the switch on later gets the entry confirmed on the next pull. */
    public function test_switching_it_on_later_confirms_on_the_next_run(): void
    {
        /*
         * BOTH PATTERNS IN ONE CALL, specific first. Http::fake() ADDS rules, it does
         * not replace them, and the first matching one answers - a later fake behind
         * a '*' catch-all is simply never reached. Written as two calls this test
         * fails with "no open payment", which looks like a bug in the code.
         */
        Http::fake([
            '*/orders/LATER/payments/' => Http::response(['results' => [
                ['local_id' => 5, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => '25.00'],
            ]], 200),
            '*/payments/5/confirm/' => Http::response([], 200),
            '*' => Http::response([], 200),
        ]);

        $event = $this->event(false);
        $this->order('LATER', 25.00);
        $this->pull(25.00, 'Bestellung LATER');

        app(JournalPaymentReporter::class)->report();
        $this->assertSame(0, PretixPaymentConfirmation::query()
            ->where('outcome', PretixPaymentConfirmation::OUTCOME_CONFIRMED)->count());

        $event->update(['auto_mark_paid' => true]);

        $counts = app(JournalPaymentReporter::class)->report();

        $this->assertSame(1, $counts['confirmed']);
        // The changed answer IS recorded - two rows now, not one.
        $this->assertSame(2, PretixPaymentConfirmation::count());
    }

    /**
     * A REFUND NEVER SETTLES ANYTHING.
     *
     * It carries the same order code, and confirming on one would mark an order paid
     * at the very moment the money went back.
     */
    public function test_a_refund_does_not_confirm(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->event(true);
        $this->order('BACKK', 25.00);

        $this->pull(-25.00, 'Erstattung BACKK');

        $this->assertSame(0, app(JournalPaymentReporter::class)->report()['confirmed']);
        $this->assertSame('n', PretixOrder::first()->status);
        Http::assertNothingSent();
    }

    /** A credit whose amount does not match the order is refused, with the numbers. */
    public function test_a_wrong_amount_is_refused_with_both_numbers(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->event(true);
        $this->order('PARTS', 60.00);

        $this->pull(25.00, 'Anzahlung PARTS');

        $this->assertSame(0, app(JournalPaymentReporter::class)->report()['confirmed']);
        Http::assertNothingSent();

        $c = PretixPaymentConfirmation::first();
        $this->assertSame(PretixPaymentConfirmation::REASON_AMOUNT, $c->reason);
        $this->assertStringContainsString('60,00', $c->message);
        $this->assertStringContainsString('25,00', $c->message);
    }

    /**
     * A SECOND CREDIT ON A SETTLED ORDER IS NOT CONFIRMED AGAIN.
     *
     * The double-payment case: the money is real, the order is not owed twice.
     */
    public function test_a_second_credit_does_not_confirm_again(): void
    {
        $this->fakePretix('DOUBL', '25.00');
        $this->event(true);
        $this->order('DOUBL', 25.00);

        $this->pull(25.00, 'Zahlung DOUBL', 'R1');
        app(JournalPaymentReporter::class)->report();

        // The order is settled now; a second transfer arrives for it.
        PretixOrder::first()->forceFill(['status' => 'n'])->save();
        $this->pull(25.00, 'Nochmal DOUBL', 'R2');
        EnableBankingJournalEntry::query()->update(['pretix_order_status' => 'n']);

        app(JournalPaymentReporter::class)->report();

        $this->assertSame(1, PretixPaymentConfirmation::query()
            ->where('outcome', PretixPaymentConfirmation::OUTCOME_CONFIRMED)->count());

        $this->assertSame(
            PretixPaymentConfirmation::REASON_ALREADY,
            PretixPaymentConfirmation::query()->latest('id')->first()->reason,
        );
    }

    /** pretix refusing is a failure, and it is recorded as one. */
    public function test_a_pretix_refusal_is_recorded_as_a_failure(): void
    {
        Http::fake([
            '*/orders/NOPRM/payments/' => Http::response(['results' => [
                ['local_id' => 3, 'provider' => 'banktransfer', 'state' => 'pending', 'amount' => '25.00'],
            ]], 200),
            '*/payments/3/confirm/' => Http::response(['detail' => 'forbidden'], 403),
        ]);

        $this->event(true);
        $this->order('NOPRM', 25.00);
        $this->pull(25.00, 'Bestellung NOPRM');

        $counts = app(JournalPaymentReporter::class)->report();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame('n', PretixOrder::first()->status, 'Ohne Bestätigung bleibt die Bestellung offen.');

        $c = PretixPaymentConfirmation::first();
        $this->assertSame(PretixPaymentConfirmation::OUTCOME_FAILED, $c->outcome);
        $this->assertStringContainsString('Bestellungen ändern', $c->message);
    }
}
