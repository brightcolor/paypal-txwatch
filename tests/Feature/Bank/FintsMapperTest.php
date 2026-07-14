<?php

namespace Tests\Feature\Bank;

use App\Services\Bank\FintsMapper;
use Fhp\Model\StatementOfAccount\Transaction as FinTsTransaction;
use Tests\TestCase;

class FintsMapperTest extends TestCase
{
    /** A lightweight stand-in exposing the phpFinTS Transaction getters. */
    private function tx(array $o): object
    {
        return new class($o) {
            public function __construct(private array $o)
            {
            }

            public function getAmount(): float
            {
                return $this->o['amount'];
            }

            public function getCreditDebit(): string
            {
                return $this->o['cd'];
            }

            public function getBookingDate(): ?\DateTime
            {
                return $this->o['booked'] ?? null;
            }

            public function getValutaDate(): ?\DateTime
            {
                return $this->o['valued'] ?? null;
            }

            public function getDate(): ?\DateTime
            {
                return $this->o['booked'] ?? null;
            }

            public function getMainDescription(): string
            {
                return $this->o['purpose'] ?? '';
            }

            public function getName(): string
            {
                return $this->o['name'] ?? '';
            }

            public function getAccountNumber(): string
            {
                return $this->o['iban'] ?? '';
            }

            public function getEndToEndID(): string
            {
                return $this->o['eref'] ?? '';
            }
        };
    }

    public function test_credit_is_positive_and_debit_is_negative(): void
    {
        $entries = (new FintsMapper())->map([
            $this->tx([
                'amount' => 87.89, 'cd' => FinTsTransaction::CD_CREDIT,
                'booked' => new \DateTime('2026-07-13'), 'valued' => new \DateTime('2026-07-13'),
                'purpose' => 'Bestellung AC-FRIENDS-2026-QKMQR', 'name' => 'Jannin Herkner',
                'iban' => 'DE12500105170648489890', 'eref' => 'PP-E2E-1',
            ]),
            $this->tx([
                'amount' => 25.00, 'cd' => FinTsTransaction::CD_DEBIT,
                'booked' => new \DateTime('2026-07-12'),
                'purpose' => 'Miete', 'name' => 'Vermieter',
            ]),
        ]);

        $this->assertCount(2, $entries);

        $credit = $entries[0];
        $this->assertSame(87.89, $credit['amount']);
        $this->assertSame('2026-07-13', $credit['booked_on']);
        $this->assertSame('Bestellung AC-FRIENDS-2026-QKMQR', $credit['purpose']);
        $this->assertSame('Jannin Herkner', $credit['counterparty_name']);
        $this->assertSame('DE12500105170648489890', $credit['counterparty_iban']);
        $this->assertSame('PP-E2E-1', $credit['end_to_end_id']);
        $this->assertSame('fints', $credit['source_format']);

        $debit = $entries[1];
        $this->assertSame(-25.00, $debit['amount']);
        $this->assertNull($debit['end_to_end_id']);
        // valued falls back to booked when the bank omits a value date.
        $this->assertSame('2026-07-12', $debit['valued_on']);
    }
}
