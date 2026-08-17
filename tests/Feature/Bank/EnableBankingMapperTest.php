<?php

namespace Tests\Feature\Bank;

use App\Services\EnableBanking\TransactionMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The mapper is where the books can tip over, so every rule gets its own case.
 *
 * No real data anywhere: Muster Farben, Max Mustermann, DE23100000001234567890.
 */
class EnableBankingMapperTest extends TestCase
{
    private function map(array $transaction): ?array
    {
        return (new TransactionMapper())->map([$transaction])['entries'][0] ?? null;
    }

    /**
     * Money in is positive, money out negative - decided by the indicator, never
     * by the sign that happens to be in the amount.
     */
    public function test_credit_is_positive_and_debit_negative(): void
    {
        $in = $this->map(self::transaction(['credit_debit_indicator' => 'CRDT']));
        $out = $this->map(self::transaction(['credit_debit_indicator' => 'DBIT']));

        $this->assertSame(12.34, $in['amount']);
        $this->assertSame(-12.34, $out['amount']);
    }

    /**
     * AN ALREADY-SIGNED AMOUNT MUST NOT BE FLIPPED BACK.
     *
     * Some banks send "-12.34" AND credit_debit_indicator=DBIT. Applying the
     * indicator to the negative number would yield +12.34 - an expense booked as
     * income, which inflates revenue and is the error that survives a review,
     * because nobody questions money arriving.
     */
    public function test_signed_amount_plus_debit_indicator_stays_negative(): void
    {
        $entry = $this->map(self::transaction([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['amount' => '-12.34', 'currency' => 'EUR'],
        ]));

        $this->assertSame(-12.34, $entry['amount']);
    }

    /** Without an indicator it is an expense - never income. */
    public function test_missing_indicator_defaults_to_debit(): void
    {
        $entry = $this->map(self::transaction(['credit_debit_indicator' => null]));

        $this->assertSame(-12.34, $entry['amount']);
    }

    /**
     * The counterparty is the debtor on the way in, the creditor on the way out.
     *
     * Reading whichever field is filled would put the account holder's own name
     * into the counterparty column of half the rows.
     */
    public function test_counterparty_follows_the_direction(): void
    {
        $base = [
            'debtor' => ['name' => 'Max Mustermann'],
            'debtor_account' => ['iban' => 'DE23100000001234567890'],
            'creditor' => ['name' => 'Muster Farben GmbH'],
            'creditor_account' => ['iban' => 'DE02120300000000202051'],
        ];

        $in = $this->map(self::transaction($base + ['credit_debit_indicator' => 'CRDT']));
        $out = $this->map(self::transaction($base + ['credit_debit_indicator' => 'DBIT']));

        $this->assertSame('Max Mustermann', $in['counterparty_name']);
        $this->assertSame('DE23100000001234567890', $in['counterparty_iban']);

        $this->assertSame('Muster Farben GmbH', $out['counterparty_name']);
        $this->assertSame('DE02120300000000202051', $out['counterparty_iban']);
    }

    /**
     * Pending rows stay out - and are counted, not silently dropped.
     *
     * A PDNG entry still changes amount, text or vanishes. Importing it creates a
     * row that later mutates under a reconciliation which already matched it.
     */
    public function test_pending_transactions_are_skipped_and_counted(): void
    {
        $result = (new TransactionMapper())->map([
            self::transaction(['status' => 'BOOK']),
            self::transaction(['status' => 'PDNG']),
            self::transaction(['status' => 'PDNG']),
        ]);

        $this->assertCount(1, $result['entries']);
        $this->assertSame(2, $result['skipped_pending']);
    }

    /**
     * A missing status counts as booked.
     *
     * Not every bank sends the field. Treating "absent" as pending would import
     * nothing at all from those banks - while the pull reports success.
     */
    public function test_missing_status_is_treated_as_booked(): void
    {
        $result = (new TransactionMapper())->map([self::transaction(['status' => null])]);

        $this->assertCount(1, $result['entries']);
        $this->assertSame(0, $result['skipped_pending']);
    }

    /**
     * The purpose is a list of lines and has to survive as text.
     *
     * The reconciler searches it for invoice numbers; a naive cast yields "Array"
     * and the automatic match is gone.
     */
    #[DataProvider('purposes')]
    public function test_remittance_information_becomes_one_line(mixed $raw, ?string $expected): void
    {
        $entry = $this->map(self::transaction(['remittance_information' => $raw]));

        $this->assertSame($expected, $entry['purpose']);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function purposes(): iterable
    {
        yield 'Liste' => [['Rechnung RE-2026-0004', 'Danke'], 'Rechnung RE-2026-0004 Danke'];
        yield 'einzelne Zeichenkette' => ['Rechnung RE-2026-0004', 'Rechnung RE-2026-0004'];
        yield 'Liste mit Leerzeilen' => [['Rechnung RE-2026-0004', '', '  '], 'Rechnung RE-2026-0004'];
        yield 'leer' => [[], null];
        yield 'fehlt' => [null, null];
    }

    /** The value date carries; booking date is the fallback, and vice versa. */
    public function test_dates_fall_back_to_each_other(): void
    {
        $onlyBooking = $this->map(self::transaction(['booking_date' => '2026-08-14', 'value_date' => null]));
        $this->assertSame('2026-08-14', $onlyBooking['booked_on']);
        $this->assertSame('2026-08-14', $onlyBooking['valued_on']);

        $both = $this->map(self::transaction(['booking_date' => '2026-08-14', 'value_date' => '2026-08-15']));
        $this->assertSame('2026-08-14', $both['booked_on']);
        $this->assertSame('2026-08-15', $both['valued_on']);
    }

    /** An unparseable date does not cost the whole row. */
    public function test_unparseable_date_falls_through_to_the_next_key(): void
    {
        $entry = $this->map(self::transaction([
            'booking_date' => 'übermorgen',
            'value_date' => '2026-08-15',
        ]));

        $this->assertSame('2026-08-15', $entry['booked_on']);
    }

    /** The shape has to match what BankStatementImporter expects. */
    public function test_entry_has_the_shared_shape(): void
    {
        $entry = $this->map(self::transaction([]));

        $this->assertSame([
            'booked_on', 'valued_on', 'amount', 'currency', 'purpose',
            'counterparty_name', 'counterparty_iban', 'end_to_end_id', 'bank_ref', 'source_format',
        ], array_keys($entry));

        $this->assertSame('enablebanking', $entry['source_format']);
    }

    /** A row without a usable amount is dropped rather than booked as zero. */
    public function test_transaction_without_amount_is_dropped(): void
    {
        $result = (new TransactionMapper())->map([
            self::transaction(['transaction_amount' => ['currency' => 'EUR']]),
        ]);

        $this->assertSame([], $result['entries']);
    }

    /** @param array<string, mixed> $overrides */
    private static function transaction(array $overrides): array
    {
        return array_replace([
            'entry_reference' => 'REF-1',
            'status' => 'BOOK',
            'credit_debit_indicator' => 'CRDT',
            'transaction_amount' => ['amount' => '12.34', 'currency' => 'EUR'],
            'booking_date' => '2026-08-14',
            'value_date' => '2026-08-14',
            'remittance_information' => ['Rechnung RE-2026-0004'],
            'debtor' => ['name' => 'Max Mustermann'],
            'debtor_account' => ['iban' => 'DE23100000001234567890'],
        ], $overrides);
    }
}
