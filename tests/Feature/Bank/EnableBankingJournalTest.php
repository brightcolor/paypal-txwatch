<?php

namespace Tests\Feature\Bank;

use App\Models\BankTransaction;
use App\Models\EnableBankingConnection;
use App\Models\EnableBankingJournalEntry;
use App\Services\EnableBanking\JournalWriter;
use App\Services\EnableBanking\Sync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The journal stage: record, and book nothing.
 *
 * The point of this stage is that no figure in the books moves because of a bank
 * pull. These tests hold exactly that - not "the journal has rows", but
 * "bank_transactions is still empty afterwards".
 */
class EnableBankingJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_journal_mode_records_and_books_nothing(): void
    {
        config(['bank.enablebanking.mode' => 'journal']);

        $written = app(JournalWriter::class)->record([
            self::entry(['amount' => 120.00, 'purpose' => 'Ticketzahlung', 'bank_ref' => 'REF-2']),
        ]);

        $this->assertSame(1, $written['recorded']);
        $this->assertSame(0, $written['known']);
        $this->assertSame(1, EnableBankingJournalEntry::count());

        // THE ASSERTION THAT MATTERS: nothing reached the books.
        $this->assertSame(0, BankTransaction::count());
    }

    /**
     * MONEY OUT WITHOUT AN ORDER CODE DOES NOT EVEN GET RECORDED.
     *
     * A card fee, a petrol station, a standing order - this application watches
     * ticket money, and recording the rest would bury the one entry that matters
     * under hundreds that do not.
     */
    public function test_debits_without_an_order_code_are_dropped(): void
    {
        $written = app(JournalWriter::class)->record([
            self::entry(['amount' => -5.95, 'purpose' => 'Entgelt Ausgabe Debitkarte', 'bank_ref' => 'R1']),
            self::entry(['amount' => -68.40, 'purpose' => 'TANKSTELLE MUSTERSTADT//KARTENZAHLUNG', 'bank_ref' => 'R2']),
            self::entry(['amount' => 120.00, 'purpose' => 'Ticketzahlung', 'bank_ref' => 'R3']),
        ]);

        $this->assertSame(2, $written['dropped']);
        $this->assertSame(1, $written['recorded']);
        $this->assertSame(1, EnableBankingJournalEntry::count());
        // Only the credit survived.
        $this->assertSame('120.00', EnableBankingJournalEntry::query()->value('amount'));
    }

    /**
     * A debit WITH an order code is a refund and stays.
     *
     * Dropping it would leave a settled order looking paid while the money went
     * back - the error that only shows up when the books no longer add up.
     */
    public function test_a_debit_with_an_order_code_is_kept_as_a_refund(): void
    {
        $order = $this->pendingOrder('ABCDE');

        $written = app(JournalWriter::class)->record([
            self::entry([
                'amount' => -120.00,
                'purpose' => 'Erstattung Bestellung ABCDE',
                'bank_ref' => 'R9',
            ]),
        ]);

        $this->assertSame(0, $written['dropped']);
        $this->assertSame(1, $written['recorded']);
        $this->assertSame(1, $written['refunds']);
        $this->assertSame(1, $written['with_order']);
        $this->assertSame($order, EnableBankingJournalEntry::query()->value('pretix_order_code'));
    }

    /**
     * In import mode the books get the SAME set the journal kept.
     *
     * Handing the importer everything would put petrol stations into the
     * reconciliation while the journal claims they were dropped: one screen saying
     * one thing, the books another.
     */
    public function test_import_mode_only_books_what_the_journal_kept(): void
    {
        config(['bank.enablebanking.mode' => 'import']);

        $written = app(JournalWriter::class)->record([
            self::entry(['amount' => -68.40, 'purpose' => 'TANKSTELLE MUSTERSTADT', 'bank_ref' => 'R1']),
            self::entry(['amount' => 120.00, 'purpose' => 'Ticketzahlung', 'bank_ref' => 'R2']),
        ]);

        app(\App\Services\Bank\BankStatementImporter::class)->importEntries($written['kept']);

        $this->assertSame(1, BankTransaction::count());
        $this->assertSame('120.00', BankTransaction::query()->value('amount'));
    }

    /**
     * A pending bank-transfer order whose code can appear in a purpose.
     *
     * Built the same way BankPretixReporterTest does it - a connection first,
     * because pretix_orders has a foreign key on it. Guessing the required columns
     * one error message at a time is how the first version of this fixture went.
     */
    private function pendingOrder(string $code, string $status = 'n', float $total = 120.00): string
    {
        $connection = \App\Models\PretixConnection::create([
            'name' => 'Verein',
            'base_url' => 'https://pretix.eu',
            'organizer_slug' => 'verein',
            'api_token' => 'tok',
            'is_active' => true,
        ]);

        \App\Models\PretixOrder::create([
            'pretix_connection_id' => $connection->id,
            'event_slug' => 'musterevent',
            'order_code' => $code,
            'status' => $status,
            'payment_provider' => 'banktransfer',
            'total' => $total,
            'currency' => 'EUR',
            'url' => 'https://pretix.eu/control/order/musterevent/' . $code . '/',
            'raw_payload' => [],
        ]);

        return $code;
    }

    /**
     * A second pull over the same days does not duplicate anything.
     *
     * Four pulls a day with three days of overlap means every transaction is seen
     * around a dozen times. Without the unique hash the journal would grow twelve
     * rows per booking.
     */
    public function test_a_repeated_pull_creates_no_duplicates(): void
    {
        $writer = app(JournalWriter::class);
        $entries = [self::entry([])];

        $first = $writer->record($entries);
        $second = $writer->record($entries);

        $this->assertSame(1, $first['recorded']);
        $this->assertSame(0, $second['recorded']);
        $this->assertSame(1, $second['known']);
        $this->assertSame(1, EnableBankingJournalEntry::count());
    }

    /**
     * THE HAND-MAINTAINED PART: the journal hash has to equal the importer's.
     *
     * JournalWriter::hash() is a copy of BankStatementImporter::hash(). If the
     * importer ever changes its recipe, a promoted journal entry becomes a second
     * row for a booking that is already there - a duplicate in the books, arriving
     * silently. This test is the only thing standing between those two copies.
     */
    public function test_the_journal_hash_equals_the_importers(): void
    {
        $entry = self::entry(['purpose' => 'Rechnung RE-2026-0004', 'counterparty_iban' => 'DE23100000001234567890']);

        // The importer's hash, read out of the row it writes.
        app(\App\Services\Bank\BankStatementImporter::class)->importEntries([$entry]);
        $fromImporter = BankTransaction::query()->value('import_hash');

        $this->assertNotNull($fromImporter);
        $this->assertSame(
            $fromImporter,
            JournalWriter::hash($entry),
            'JournalWriter::hash() weicht von BankStatementImporter::hash() ab – eine übernommene '
            . 'Journalzeile würde damit eine Dublette in den Kontoumsätzen erzeugen.',
        );
    }

    /**
     * Without pretix orders no order code is invented.
     *
     * The lookup searches for codes that actually exist and are actually open. A
     * regular expression over the purpose would have matched "MV070" in a
     * Sparkasse fee reference and claimed a ticket payment that never existed.
     */
    public function test_no_order_code_is_invented(): void
    {
        $written = app(JournalWriter::class)->record([
            self::entry(['purpose' => 'Rechnung SPARKASSE MECKLENBURG-NORDWEST 20260520-MV070-00014971092']),
        ]);

        $this->assertSame(0, $written['with_order']);
        $this->assertNull(EnableBankingJournalEntry::query()->value('pretix_order_code'));
    }

    /**
     * The quota guard: PSD2 grants four unattended accesses a day.
     *
     * A restarted scheduler fires again on the next tick; without this the quota
     * would be spent and the bank would refuse until midnight.
     */
    public function test_a_pull_too_soon_is_refused(): void
    {
        config(['bank.enablebanking.min_hours_between_pulls' => 5]);

        $connection = EnableBankingConnection::current();
        $sync = app(Sync::class);

        // Never pulled - allowed.
        $this->assertNull($sync->tooSoon($connection));

        $connection->forceFill(['last_synced_at' => now()->subHours(2)])->save();
        $reason = $sync->tooSoon($connection);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('vier Abrufe', $reason);

        // Six hours later the way is clear again - that is the scheduler's rhythm.
        $connection->forceFill(['last_synced_at' => now()->subHours(6)])->save();
        $this->assertNull($sync->tooSoon($connection));
    }

    /** With the gap switched off nothing is held back. */
    public function test_the_guard_can_be_switched_off(): void
    {
        config(['bank.enablebanking.min_hours_between_pulls' => 0]);

        $connection = EnableBankingConnection::current();
        $connection->forceFill(['last_synced_at' => now()->subMinute()])->save();

        $this->assertNull(app(Sync::class)->tooSoon($connection));
    }

    /** The scheduler really runs four times a day, not once. */
    public function test_the_schedule_runs_every_six_hours(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'enablebanking:sync'));

        $this->assertCount(1, $events, 'Der Abruf ist nicht genau einmal eingeplant.');
        $this->assertSame('36 */6 * * *', $events->first()->expression);
    }

    /** @param array<string, mixed> $overrides */
    /** Shared with EnableBankingJournalTableTest - one fixture recipe, not two. */
    public static function entry(array $overrides): array
    {
        return array_replace([
            'booked_on' => '2026-05-20',
            'valued_on' => '2026-05-20',
            'amount' => 120.00,
            'currency' => 'EUR',
            'purpose' => 'Ticketzahlung Musterveranstaltung',
            'counterparty_name' => null,
            'counterparty_iban' => null,
            'end_to_end_id' => null,
            'bank_ref' => 'REF-1',
            'source_format' => 'enablebanking',
        ], $overrides);
    }
    /**
     * A SETTLED ORDER IS RECOGNISED TOO - and marked as needing nothing.
     *
     * This is the case that used to vanish: 986 of 1025 orders are already paid, so
     * almost every incoming transfer came out as "nothing recognised" and looked
     * exactly like a real gap.
     */
    public function test_a_settled_order_is_recognised_but_not_actionable(): void
    {
        $this->pendingOrder('PAIDX', 'p', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 63.80, 'purpose' => 'GAG-WISMAR-2026-PAIDX', 'bank_ref' => 'S1']),
        ]);

        $e = EnableBankingJournalEntry::first();

        $this->assertSame('PAIDX', $e->pretix_order_code);
        $this->assertSame('p', $e->pretix_order_status);
        $this->assertTrue($e->isSettled());
        $this->assertFalse($e->isActionable(), 'Eine bezahlte Bestellung ist keine Arbeit.');
    }

    /** An open order is work, and says so. */
    public function test_an_open_order_is_actionable(): void
    {
        $this->pendingOrder('OPENX', 'n', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 63.80, 'purpose' => 'GAG-WISMAR-2026-OPENX', 'bank_ref' => 'S2']),
        ]);

        $e = EnableBankingJournalEntry::first();

        $this->assertSame('n', $e->pretix_order_status);
        $this->assertTrue($e->isActionable());
        $this->assertSame('offen – zu buchen', $e->stateLabel());
        $this->assertFalse((bool) $e->possible_double_payment);
    }

    /**
     * A SECOND CREDIT ON ONE ORDER IS FLAGGED - on both entries.
     *
     * Measured against 166 real credits, this fires exactly once: order XDUBJ with
     * 5,00 and 58,30 EUR on the same day against a total of 58,30.
     */
    public function test_a_second_credit_on_one_order_is_flagged(): void
    {
        $this->pendingOrder('XDUBJ', 'p', 58.30);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 58.30, 'purpose' => 'Zahlung XDUBJ', 'bank_ref' => 'S3a']),
            self::entry(['amount' => 5.00, 'purpose' => 'Nachzahlung XDUBJ', 'bank_ref' => 'S3b']),
        ]);

        $entries = EnableBankingJournalEntry::query()->orderBy('id')->get();

        $this->assertCount(2, $entries);

        // BOTH, not just the second: a pair is only readable as a pair.
        foreach ($entries as $e) {
            $this->assertTrue(
                (bool) $e->possible_double_payment,
                'Beide Umsätze des Paares müssen markiert sein, nicht nur der zweite.',
            );
            $this->assertSame('mögliche Doppelzahlung', $e->stateLabel());
            $this->assertTrue($e->isActionable(), 'Eine Doppelzahlung ist Arbeit, auch wenn die Bestellung bezahlt ist.');
        }

        // The protocol names both amounts, otherwise the warning cannot be checked.
        $messages = $entries->first()->events->pluck('message')->implode(' ');
        $this->assertStringContainsString('58,30', $messages);
        $this->assertStringContainsString('5,00', $messages);
    }

    /**
     * THE NORMAL CASE IS NOT A DOUBLE PAYMENT - the guard against 140 false alarms.
     *
     * The first criterion asked whether the order was already paid and the amount
     * fitted. That is what a SUCCESSFUL payment looks like: the order is marked paid
     * precisely because this transfer arrived. Against the 166 real credits it
     * flagged 140. Without this test the mistake comes back the moment someone finds
     * the state field and thinks it is enough.
     */
    public function test_a_single_payment_on_a_settled_order_is_no_double_payment(): void
    {
        $this->pendingOrder('ONEPY', 'p', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 63.80, 'purpose' => 'Zahlung ONEPY', 'bank_ref' => 'S3c']),
        ]);

        $e = EnableBankingJournalEntry::first();

        $this->assertSame('ONEPY', $e->pretix_order_code);
        $this->assertFalse(
            (bool) $e->possible_double_payment,
            'Bezahlte Bestellung plus passender Betrag ist der Normalfall einer erfolgreichen Zahlung.',
        );
        $this->assertSame('bereits bezahlt', $e->stateLabel());
        $this->assertFalse($e->isActionable());
    }

    /**
     * A refund alongside the payment is not a second credit.
     *
     * Same code, same amount, opposite direction - and the pair is the RESOLUTION of
     * the payment, not a duplicate of it.
     */
    public function test_a_refund_alongside_the_payment_is_no_double_payment(): void
    {
        $this->pendingOrder('BACKP', 'p', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 63.80, 'purpose' => 'Zahlung BACKP', 'bank_ref' => 'S3d']),
            self::entry(['amount' => -63.80, 'purpose' => 'Erstattung BACKP', 'bank_ref' => 'S3e']),
        ]);

        $this->assertCount(2, EnableBankingJournalEntry::all());
        $this->assertSame(
            0,
            EnableBankingJournalEntry::query()->where('possible_double_payment', true)->count(),
        );
    }

    /** A refund of a settled order is recorded and carries no warning. */
    public function test_a_refund_is_not_a_double_payment(): void
    {
        $this->pendingOrder('REFND', 'p', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => -63.80, 'purpose' => 'Erstattung REFND', 'bank_ref' => 'S4']),
        ]);

        $e = EnableBankingJournalEntry::first();

        $this->assertNotNull($e, 'Eine Abbuchung mit Bestellnummer muss aufgezeichnet werden.');
        $this->assertFalse((bool) $e->possible_double_payment);
    }

    /**
     * A credit quoting a settled order with a DIFFERENT amount is not a double
     * payment either - far more likely a different payment that happens to carry the
     * code.
     */
    public function test_a_wrong_amount_is_no_double_payment(): void
    {
        $this->pendingOrder('OTHER', 'p', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 500.00, 'purpose' => 'Sammelzahlung OTHER und weitere', 'bank_ref' => 'S5']),
        ]);

        $e = EnableBankingJournalEntry::first();

        $this->assertSame('OTHER', $e->pretix_order_code);
        $this->assertFalse((bool) $e->possible_double_payment);
    }

    /** An open order beats a settled one when both codes occur. */
    public function test_an_open_order_wins_over_a_settled_one(): void
    {
        $this->pendingOrder('PAIDA', 'p', 63.80);
        $this->pendingOrder('OPENB', 'n', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 63.80, 'purpose' => 'Sammel PAIDA und OPENB', 'bank_ref' => 'S6']),
        ]);

        $this->assertSame('OPENB', EnableBankingJournalEntry::first()->pretix_order_code);
    }

    /** The protocol says what follows, not merely that something was found. */
    public function test_the_protocol_says_what_follows(): void
    {
        $this->pendingOrder('OPENC', 'n', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 63.80, 'purpose' => 'GAG-2026-OPENC', 'bank_ref' => 'S7']),
        ]);

        $messages = EnableBankingJournalEntry::first()->events->pluck('message')->implode(' | ');

        $this->assertStringContainsString('OPENC', $messages);
        $this->assertStringContainsString('offen', $messages);
    }
    /**
     * THE PROTOCOL VIEW ACTUALLY RENDERS - for every state.
     *
     * It only ever appears in a modal, so no other test touches it: a Blade error in
     * there would surface when a human clicks "Protokoll", not in CI. Rendered once
     * per state because the template branches on all three.
     */
    public function test_the_protocol_view_renders_for_every_state(): void
    {
        $this->pendingOrder('OPEND', 'n', 63.80);
        $this->pendingOrder('PAIDD', 'p', 63.80);

        app(JournalWriter::class)->record([
            self::entry(['amount' => 63.80, 'purpose' => 'GAG-2026-OPEND', 'bank_ref' => 'V1']),
            self::entry(['amount' => 63.80, 'purpose' => 'Zahlung PAIDD', 'bank_ref' => 'V2']),
            self::entry(['amount' => 63.80, 'purpose' => 'Zahlung OPENE', 'bank_ref' => 'V3']),
            self::entry(['amount' => 12.00, 'purpose' => 'PayPal Auszahlung', 'bank_ref' => 'V4']),
        ]);

        $states = [];

        foreach (EnableBankingJournalEntry::with('events')->get() as $entry) {
            $html = view('filament.bank.journal-protocol', [
                'entry' => $entry,
                'events' => $entry->events,
            ])->render();

            $this->assertStringContainsString($entry->stateLabel(), $html);
            $states[] = $entry->stateLabel();
        }

        // Guard against the fixture quietly collapsing into one state - the test would
        // still pass while covering a single branch.
        $this->assertGreaterThanOrEqual(3, count(array_unique($states)), 'Zu wenige Zustände abgedeckt: ' . implode(', ', $states));
    }
}
