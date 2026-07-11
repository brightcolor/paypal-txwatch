<?php

namespace App\Support;

use App\Models\ErrorLogEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Persists server-side errors (HTTP 5xx / unhandled exceptions) into the
 * error_log_entries table so they can be reviewed in the panel or via the
 * `errors:recent` command - without SSH access to the raw laravel.log.
 *
 * Deliberately defensive: it is called from Laravel's exception handler, so it
 * must never throw or recurse. A DB-layer failure (the very thing we might be
 * logging) is swallowed and left to the file logger.
 */
class ErrorLogger
{
    /** Keys whose values are redacted from captured request input. */
    private const REDACT = ['password', 'password_confirmation', 'secret', 'client_secret',
        'api_token', 'token', 'webhook_secret', 'authorization', 'cookie', '_token', 'two_factor'];

    /** Only 5xx (and non-HTTP throwables, treated as 500) are worth persisting. */
    public static function shouldRecord(Throwable $e): bool
    {
        return static::statusFor($e) >= 500;
    }

    public static function record(Throwable $e): void
    {
        try {
            if (! static::shouldRecord($e)) {
                return;
            }

            // If the DB itself is the problem, don't try to write to it - that
            // would recurse or throw inside the exception handler.
            if ($e instanceof QueryException || $e instanceof \PDOException) {
                static::persist($e, dbSafe: false);

                return;
            }

            static::persist($e, dbSafe: true);
        } catch (Throwable $inner) {
            // Last resort: never let error logging break request handling.
            Log::error('ErrorLogger failed: ' . $inner->getMessage());
        }
    }

    private static function persist(Throwable $e, bool $dbSafe): void
    {
        if (! $dbSafe) {
            // A DB error can't be safely written to the DB; the file log already
            // has it. Skip the table write rather than risk a nested failure.
            return;
        }

        $status = static::statusFor($e);
        $fingerprint = static::fingerprint($e, $status);
        $now = now();

        $payload = [
            'exception_class' => get_class($e),
            'message' => Str::limit($e->getMessage(), 2000),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'status_code' => $status,
            'method' => request()?->method(),
            'url' => request()?->fullUrl(),
            'route' => optional(request()?->route())->getName(),
            'user_id' => Auth::id(),
            'app_version' => config('version.number'),
            'context' => static::context(),
            'trace' => Str::limit($e->getTraceAsString(), 15000),
            'last_seen_at' => $now,
        ];

        // Atomic upsert-by-fingerprint: bump the counter for a known bug,
        // otherwise insert a fresh row and alert admins about the new problem.
        $existing = ErrorLogEntry::query()->where('fingerprint', $fingerprint)->first();

        if ($existing) {
            $existing->forceFill($payload);
            $existing->occurrences = $existing->occurrences + 1;
            $existing->resolved = false; // it happened again -> reopen
            $existing->save();

            return;
        }

        $entry = ErrorLogEntry::create(array_merge($payload, [
            'fingerprint' => $fingerprint,
            'first_seen_at' => $now,
            'occurrences' => 1,
        ]));

        static::notify($entry);
    }

    private static function statusFor(Throwable $e): int
    {
        return $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
    }

    /**
     * Group by the stable signature of a bug (class + location + shape of the
     * message) so different IDs/values in the message don't split one bug into
     * many rows.
     */
    private static function fingerprint(Throwable $e, int $status): string
    {
        $normalized = preg_replace(
            ['/\d+/', '/[0-9a-f]{8}-[0-9a-f\-]{27}/i'],
            ['#', '#uuid#'],
            (string) $e->getMessage()
        );

        return hash('sha256', implode('|', [
            get_class($e), $e->getFile(), $e->getLine(), $status, $normalized,
        ]));
    }

    private static function context(): array
    {
        $request = request();

        if (! $request || app()->runningInConsole()) {
            return ['channel' => app()->runningInConsole() ? 'console' : 'unknown'];
        }

        return array_filter([
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255),
            'input' => static::redact($request->except(['password', 'password_confirmation'])),
            'referer' => $request->header('referer'),
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /** Recursively redact secret-looking keys before we store request input. */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = static::redact($value);

                continue;
            }

            foreach (self::REDACT as $needle) {
                if (stripos((string) $key, $needle) !== false) {
                    $data[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $data;
    }

    private static function notify(ErrorLogEntry $entry): void
    {
        try {
            AdminNotifier::warn(
                'Neuer Fehler (500)',
                $entry->shortClass() . ': ' . Str::limit($entry->message, 120),
                url('/admin/error-log-entries/' . $entry->getKey()),
            );
        } catch (Throwable) {
            // Notification is best-effort; the row is already saved.
        }
    }
}
