<?php

namespace Tests\Feature\Bank;

use App\Models\EnableBankingConnection;
use App\Services\EnableBanking\EnableBankingException;
use App\Services\EnableBanking\Sync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A FAILED PULL MUST NOT DISABLE THE NEXT ONE.
 *
 * WHAT HAPPENED (21.08. - 26.08.2026, six days without a single transaction): the
 * pull was gated on `status === active`, which records how the LAST run went. A
 * network timeout on the host set the status to `error`, and every following run
 * then refused before touching the network - the refusal writing the very status it
 * was refusing on, and overwriting the original message while it was at it. The
 * session at the bank was AUTHORIZED the whole time and the consent ran until
 * February.
 *
 * Nothing about that was visible: the command wrote one console line per run and
 * raised no warning, because it only warns about expired consent and truncated
 * pulls.
 */
class EnableBankingRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function connection(array $overrides = []): EnableBankingConnection
    {
        return EnableBankingConnection::create(array_merge([
            'aspsp_name' => 'Sparkasse Musterstadt',
            'aspsp_country' => 'DE',
            'session_id' => 'sess-123',
            'accounts' => [['uid' => 'acc-1']],
            'iban' => 'DE23100000001234567890',
            'access_valid_until' => now()->addMonths(5),
            'status' => EnableBankingConnection::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * THE REGRESSION: an errored connection with a live session may pull again.
     *
     * Turn this back to `isActive()` and the six-day outage returns.
     */
    public function test_a_failed_pull_does_not_lock_out_the_next_one(): void
    {
        $connection = $this->connection([
            'status' => EnableBankingConnection::STATUS_ERROR,
            'last_error' => 'cURL error 28: Timeout was reached',
            'failed_since' => now()->subDays(6),
            'failure_count' => 24,
        ]);

        $this->assertFalse($connection->isActive(), 'Der letzte Lauf ist fehlgeschlagen – das bleibt wahr.');
        $this->assertTrue(
            $connection->canPull(),
            'Ein fehlgeschlagener Lauf sagt nichts darüber, ob der nächste gelingen kann.',
        );
    }

    /** An expired consent is the one state a retry cannot fix. */
    public function test_an_expired_consent_still_blocks(): void
    {
        $this->assertFalse(
            $this->connection(['access_valid_until' => now()->subDay()])->canPull(),
        );

        $this->assertFalse(
            $this->connection(['status' => EnableBankingConnection::STATUS_EXPIRED])->canPull(),
        );
    }

    /** Without a session there is nothing to pull with. */
    public function test_without_a_session_there_is_no_pull(): void
    {
        $this->assertFalse($this->connection(['session_id' => null])->canPull());
        $this->assertFalse($this->connection(['status' => EnableBankingConnection::STATUS_NEW])->canPull());
    }

    /**
     * THE FIRST CAUSE SURVIVES THE FOLLOW-UP ERRORS.
     *
     * The outage left behind "Keine aktive Bankverbindung", which described the
     * lockout rather than the timeout that caused it - because every run overwrote
     * the message of the one before.
     */
    public function test_the_first_error_of_a_streak_is_kept(): void
    {
        $connection = $this->connection();
        $sync = $this->syncThatFailsWith(['Der wahre Grund: Zeitüberschreitung', 'Folgefehler eins', 'Folgefehler zwei']);

        $sync->syncSafely($connection);
        $sync->syncSafely($connection->fresh());
        $result = $sync->syncSafely($connection->fresh());

        $connection->refresh();

        $this->assertSame('Der wahre Grund: Zeitüberschreitung', $connection->first_error);
        $this->assertSame('Der wahre Grund: Zeitüberschreitung', $connection->rootError());
        $this->assertSame('Folgefehler zwei', $connection->last_error, 'Die neueste Meldung bleibt daneben stehen.');
        $this->assertSame(3, $connection->failure_count);
        $this->assertNotNull($connection->failed_since);
        $this->assertTrue($connection->isFailing());

        // Handed up, so the command can raise the alarm instead of logging a line.
        $this->assertSame(3, $result['failure_count']);
        $this->assertSame('Der wahre Grund: Zeitüberschreitung', $result['first_error']);
    }

    /** A successful pull ends the streak - and only a successful one does. */
    public function test_a_successful_pull_clears_the_streak(): void
    {
        $connection = $this->connection([
            'status' => EnableBankingConnection::STATUS_ERROR,
            'failed_since' => now()->subDays(6),
            'failure_count' => 24,
            'first_error' => 'Zeitüberschreitung',
            'last_error' => 'Keine aktive Bankverbindung über Enable Banking.',
        ]);

        $connection->forceFill([
            'status' => EnableBankingConnection::STATUS_ACTIVE,
            'last_synced_at' => now(),
            'last_error' => null,
            'failed_since' => null,
            'failure_count' => 0,
            'first_error' => null,
        ])->save();

        $connection->refresh();

        $this->assertFalse($connection->isFailing());
        $this->assertSame(0, $connection->failure_count);
        $this->assertNull($connection->rootError());
    }

    /**
     * THE COMMAND RAISES THE ALARM - not on the first blip, but on the second.
     *
     * The omission that made a six-day outage invisible.
     */
    public function test_a_repeated_failure_warns_the_admins(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        $admin->assignRole(\Spatie\Permission\Models\Role::findByName('admin'));

        $connection = $this->connection();
        $this->app->instance(Sync::class, $this->syncThatFailsWith(['Zeitüberschreitung', 'Zeitüberschreitung']));

        $this->artisan('enablebanking:sync')->assertSuccessful();

        $this->assertSame(1, $connection->fresh()->failure_count);
        $this->assertSame(0, \DB::table('notifications')->count(), 'Ein einzelner Aussetzer ist noch kein Alarm.');

        // Second failure in a row - no longer a blip.
        $this->artisan('enablebanking:sync')->assertSuccessful();

        $this->assertSame(2, $connection->fresh()->failure_count);
        $this->assertSame(1, \DB::table('notifications')->count());

        // Decoded first: the column holds JSON, where every umlaut is a \uXXXX
        // escape - comparing raw UTF-8 against that fails on correct output.
        $daten = json_decode((string) \DB::table('notifications')->value('data'), true);
        $text = json_encode($daten, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Bankabruf schlägt fehl', $text);
        $this->assertStringContainsString('Versuche in Folge', $text);
        // The FIRST cause has to be in the warning - that is what was missing.
        $this->assertStringContainsString('Zeitüberschreitung', $text);
    }

    /**
     * A Sync whose pull always throws, one message per call.
     *
     * Built by subclassing rather than mocking so `syncSafely()` - the part under
     * test - runs for real.
     */
    private function syncThatFailsWith(array $messages): Sync
    {
        return new class($messages, app(\App\Services\EnableBanking\Client::class),
            app(\App\Services\EnableBanking\TransactionMapper::class),
            app(\App\Services\Bank\BankStatementImporter::class),
            app(\App\Services\EnableBanking\JournalWriter::class),
            app(\App\Services\EnableBanking\JournalPaymentReporter::class)) extends Sync
        {
            private int $call = 0;

            public function __construct(private array $messages, ...$args)
            {
                parent::__construct(...$args);
            }

            public function sync(EnableBankingConnection $connection): array
            {
                throw new EnableBankingException($this->messages[$this->call++] ?? 'weiterer Fehler');
            }

            public function tooSoon(EnableBankingConnection $connection): ?string
            {
                return null;
            }
        };
    }
}
