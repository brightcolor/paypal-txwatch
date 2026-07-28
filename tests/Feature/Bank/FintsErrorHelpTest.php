<?php

namespace Tests\Feature\Bank;

use App\Services\Bank\FintsErrorHelp;
use Tests\TestCase;

class FintsErrorHelpTest extends TestCase
{
    public function test_explains_unregistered_product(): void
    {
        $raw = '9078 (wrt seg 4): Dialog abgebrochen - Banking-Programm ist nicht registriert.';

        $this->assertStringContainsString('Registrierungsnummer', FintsErrorHelp::explain($raw));
        // The bank's own wording stays visible below the hint.
        $this->assertStringContainsString($raw, FintsErrorHelp::decorate($raw));
    }

    public function test_explains_wrong_credentials(): void
    {
        $this->assertStringContainsString(
            'Legitimations-ID',
            FintsErrorHelp::explain('9931 (wrt seg 4): Anmeldename oder PIN ist falsch.'),
        );
    }

    public function test_unknown_codes_pass_through_unchanged(): void
    {
        $this->assertNull(FintsErrorHelp::explain('Irgendein anderer Fehler'));
        $this->assertSame('Irgendein anderer Fehler', FintsErrorHelp::decorate('Irgendein anderer Fehler'));
    }
}
