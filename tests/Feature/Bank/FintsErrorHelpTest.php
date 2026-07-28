<?php

namespace Tests\Feature\Bank;

use App\Services\Bank\FintsErrorHelp;
use Tests\TestCase;

class FintsErrorHelpTest extends TestCase
{
    /** The real message from the first failed login attempt. */
    private const REAL_CREDENTIALS_ERROR = 'FinTS errors: 9050 (global): Die Nachricht enthält Fehler. '
        . '9800 (global): Dialog abgebrochen 9010 (global): Initialisierung fehlgeschlagen, Auftrag nicht bearbeitet. '
        . '9010 (wrt seg 4): PIN/TAN Prüfung fehlgeschlagen 9931 (wrt seg 4): Anmeldename oder PIN ist falsch.';

    /** The real message once the credentials were correct. */
    private const REAL_REGISTRATION_ERROR = 'FinTS errors: 9050 (global): Die Nachricht enthält Fehler. '
        . '9800 (global): Dialog abgebrochen 9010 (global): Initialisierung fehlgeschlagen, Auftrag nicht bearbeitet. '
        . '9078 (wrt seg 4): Dialog abgebrochen - Banking-Programm ist nicht registriert. '
        . '9010 (wrt seg 4): Der Auftrag wurde nicht ausgeführt.';

    public function test_specific_code_wins_over_generic_envelope_codes(): void
    {
        // 9050/9800/9010 come first in the text but say nothing useful - the
        // hint must be about the credentials (9931).
        $hint = FintsErrorHelp::explain(self::REAL_CREDENTIALS_ERROR);

        $this->assertStringContainsString('Legitimations-ID', $hint);
        $this->assertStringNotContainsString('Registrierungsnummer', $hint);
    }

    public function test_registration_error_is_explained(): void
    {
        $hint = FintsErrorHelp::explain(self::REAL_REGISTRATION_ERROR);

        $this->assertStringContainsString('Registrierungsnummer', $hint);
    }

    public function test_bank_wording_is_kept_below_the_hint(): void
    {
        $decorated = FintsErrorHelp::decorate(self::REAL_REGISTRATION_ERROR);

        $this->assertStringContainsString('Registrierungsnummer', $decorated);
        $this->assertStringContainsString('Banking-Programm ist nicht registriert', $decorated);
    }

    public function test_unknown_code_falls_back_to_its_range_meaning(): void
    {
        $this->assertStringContainsString(
            'Fehler abgebrochen',
            FintsErrorHelp::explain('9411 (wrt seg 4): Irgendein unbekannter Bankfehler'),
        );

        $this->assertStringContainsString(
            'Hinweis',
            FintsErrorHelp::explain('3811 (wrt seg 4): Irgendein unbekannter Hinweis'),
        );
    }

    public function test_messages_without_codes_pass_through_unchanged(): void
    {
        $this->assertNull(FintsErrorHelp::explain('Verbindung zum Server fehlgeschlagen'));
        $this->assertSame(
            'Verbindung zum Server fehlgeschlagen',
            FintsErrorHelp::decorate('Verbindung zum Server fehlgeschlagen'),
        );
    }

    public function test_known_informational_codes_are_marked_as_harmless(): void
    {
        $this->assertStringContainsString('kein Fehler', FintsErrorHelp::explain('3010: Es liegen keine Einträge vor.'));
    }
}
