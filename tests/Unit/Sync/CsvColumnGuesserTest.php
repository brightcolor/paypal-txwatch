<?php

namespace Tests\Unit\Sync;

use App\Services\Sync\CsvColumnGuesser;
use PHPUnit\Framework\TestCase;

class CsvColumnGuesserTest extends TestCase
{
    public function test_it_guesses_english_paypal_activity_download_headers(): void
    {
        $headers = ['Date', 'Time', 'Name', 'Type', 'Status', 'Currency', 'Gross', 'Fee', 'Net',
            'From Email Address', 'Transaction ID', 'Invoice Number', 'Custom Number', 'Subject', 'Note'];

        $mapping = CsvColumnGuesser::guess($headers);

        $this->assertSame('Transaction ID', $mapping['transaction_id']);
        $this->assertSame('Date', $mapping['date']);
        $this->assertSame('Gross', $mapping['gross']);
        $this->assertSame('Fee', $mapping['fee']);
        $this->assertSame('Net', $mapping['net']);
        $this->assertSame('Custom Number', $mapping['custom_field']);
        $this->assertSame('Invoice Number', $mapping['invoice_id']);
        $this->assertSame('From Email Address', $mapping['email']);
    }

    public function test_it_guesses_german_paypal_activity_download_headers(): void
    {
        $headers = ['Datum', 'Zeit', 'Name', 'Status', 'Währung', 'Brutto', 'Gebühr', 'Netto',
            'Transaktionscode', 'Rechnungsnummer', 'Benutzerdefinierte Nummer'];

        $mapping = CsvColumnGuesser::guess($headers);

        $this->assertSame('Transaktionscode', $mapping['transaction_id']);
        $this->assertSame('Brutto', $mapping['gross']);
        $this->assertSame('Benutzerdefinierte Nummer', $mapping['custom_field']);
    }

    public function test_unmatched_fields_are_null(): void
    {
        $mapping = CsvColumnGuesser::guess(['Foo', 'Bar']);

        $this->assertNull($mapping['transaction_id']);
        $this->assertNull($mapping['gross']);
    }
}
