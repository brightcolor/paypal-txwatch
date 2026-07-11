<?php

namespace Tests\Feature;

use App\Models\ErrorLogEntry;
use App\Support\ErrorLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ErrorLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_errors_are_recorded(): void
    {
        ErrorLogger::record(new RuntimeException('Kaputt'));

        $this->assertSame(1, ErrorLogEntry::count());
        $entry = ErrorLogEntry::first();
        $this->assertSame('Kaputt', $entry->message);
        $this->assertSame(500, $entry->status_code);
        $this->assertSame(1, $entry->occurrences);
        $this->assertNotNull($entry->fingerprint);
        $this->assertNotNull($entry->trace);
    }

    public function test_client_errors_are_not_recorded(): void
    {
        ErrorLogger::record(new NotFoundHttpException('nope'));

        $this->assertSame(0, ErrorLogEntry::count());
        $this->assertFalse(ErrorLogger::shouldRecord(new NotFoundHttpException()));
        $this->assertTrue(ErrorLogger::shouldRecord(new RuntimeException()));
    }

    public function test_repeated_identical_error_increments_occurrences_not_rows(): void
    {
        $make = fn () => new RuntimeException('Immer der gleiche Fehler');

        // Same file/line/message -> same fingerprint. Build them on one line so
        // getLine() matches across calls.
        ErrorLogger::record($make());
        ErrorLogger::record($make());
        ErrorLogger::record($make());

        $this->assertSame(1, ErrorLogEntry::count());
        $this->assertSame(3, ErrorLogEntry::first()->occurrences);
    }

    public function test_query_exceptions_are_not_written_to_the_db(): void
    {
        // A DB-layer failure must not try to persist itself (recursion/again-down).
        $e = new \Illuminate\Database\QueryException('conn', 'select 1', [], new RuntimeException('down'));

        ErrorLogger::record($e);

        $this->assertSame(0, ErrorLogEntry::count());
    }

    public function test_secret_keys_are_redacted(): void
    {
        $clean = ErrorLogger::redact([
            'email' => 'a@b.de',
            'password' => 'geheim',
            'client_secret' => 'xyz',
            'nested' => ['api_token' => 'abc', 'keep' => 'ok'],
        ]);

        $this->assertSame('a@b.de', $clean['email']);
        $this->assertSame('[redacted]', $clean['password']);
        $this->assertSame('[redacted]', $clean['client_secret']);
        $this->assertSame('[redacted]', $clean['nested']['api_token']);
        $this->assertSame('ok', $clean['nested']['keep']);
    }
}
