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
            self::entry(['amount' => -5.95, 'purpose' => 'Entgelt Debitkarte']),
            self::entry(['amount' => 120.00, 'purpose' => 'Ticketzahlung', 'bank_ref' => 'REF-2']),
        ]);

        $this->assertSame(2, $written['recorded']);
        $this->assertSame(0, $written['known']);
        $this->assertSame(2, EnableBankingJournalEntry::count());

        // THE ASSERTION THAT MATTERS: nothing reached the books.
        $this->assertSame(0, BankTransaction::count());
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
    private static function entry(array $overrides): array
    {
        return array_replace([
            'booked_on' => '2026-05-20',
            'valued_on' => '2026-05-20',
            'amount' => -5.95,
            'currency' => 'EUR',
            'purpose' => 'Entgelt Ausgabe Debitkarte',
            'counterparty_name' => null,
            'counterparty_iban' => null,
            'end_to_end_id' => null,
            'bank_ref' => 'REF-1',
            'source_format' => 'enablebanking',
        ], $overrides);
    }
}
