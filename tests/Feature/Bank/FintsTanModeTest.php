<?php

namespace Tests\Feature\Bank;

use App\Models\FintsConnection;
use App\Services\Bank\FintsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the TAN method ID arrives from the form/DB as a string, but
 * phpFinTS rejects anything that is not a real int ("tanMode must be an int or
 * a TanMode") - and it does so BEFORE any network access, so the login died
 * immediately. The client must cast it.
 */
class FintsTanModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_string_tan_mode_is_accepted_by_the_library(): void
    {
        // Only meaningful where phpFinTS is actually installed (CI/container).
        // Without this guard the test would pass vacuously: the missing class
        // throws a different error, which the assertion below would happily
        // accept.
        if (! class_exists(\Fhp\FinTs::class)) {
            $this->markTestSkipped('phpFinTS ist in dieser Umgebung nicht installiert.');
        }

        $connection = FintsConnection::current();
        $connection->update([
            'bank_code' => '14051000',
            // Refused instantly - we only care about getting PAST selectTanMode.
            'fints_url' => 'https://127.0.0.1:1/fints',
            'product_id' => 'TEST',
            'product_version' => '1.0',
            'username' => 'testuser',
            'pin' => 'geheim',
            'tan_mode' => '923', // string, exactly as the form stores it
        ]);

        try {
            (new FintsClient($connection))->beginLogin();
            $this->fail('Expected the connection to the dummy URL to fail.');
        } catch (\Throwable $e) {
            // Any transport error is fine - the argument error must be gone.
            $this->assertStringNotContainsString('tanMode must be an int', $e->getMessage());
        }
    }
}
