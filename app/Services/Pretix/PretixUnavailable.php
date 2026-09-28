<?php

namespace App\Services\Pretix;

use App\Models\PretixConnection;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * A pretix call that failed outside TxWatch: host unreachable, timeout, token
 * refused or an error answer. userMessage() tells the operator what happened
 * and what to do; the exception message adds the cause for the error log.
 * The API token appears in neither.
 */
final class PretixUnavailable extends RuntimeException
{
    public const UNREACHABLE = 'unreachable';
    public const TIMEOUT = 'timeout';
    public const DENIED = 'denied';
    public const NOT_FOUND = 'not_found';
    public const RATE_LIMITED = 'rate_limited';
    public const REJECTED = 'rejected';
    public const SERVER_ERROR = 'server_error';

    /** cURL's code for an expired timeout (CURLE_OPERATION_TIMEDOUT). */
    private const CURL_TIMEOUT = 28;

    private function __construct(
        public readonly string $reason,
        public readonly ?int $status,
        private readonly string $connectionName,
        string $logMessage,
        ConnectionException|RequestException $previous,
    ) {
        parent::__construct($logMessage, 0, $previous);
    }

    public static function from(ConnectionException|RequestException $e, PretixConnection $connection): self
    {
        $status = $e instanceof RequestException ? $e->response->status() : null;

        $reason = match (true) {
            $status === null => self::isTimeout($e) ? self::TIMEOUT : self::UNREACHABLE,
            $status === 401, $status === 403 => self::DENIED,
            $status === 404 => self::NOT_FOUND,
            $status === 429 => self::RATE_LIMITED,
            $status >= 500 => self::SERVER_ERROR,
            default => self::REJECTED,
        };

        // A response may echo the token; the log gets the cause without it.
        $cause = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));
        $token = (string) $connection->api_token;

        if ($token !== '') {
            $cause = str_replace($token, '[Token entfernt]', $cause);
        }

        $logMessage = sprintf('pretix-Verbindung „%s“ (ID %s): %s %s Ursache: %s',
            $connection->name, $connection->getKey(), self::what($reason, $status), self::advise($reason), $cause);

        return new self($reason, $status, (string) $connection->name, $logMessage, $e);
    }

    /** Which connection and what happened, with the status code as an addition. */
    public function summary(): string
    {
        return "pretix-Verbindung „{$this->connectionName}“: " . self::what($this->reason, $this->status);
    }

    /** What the operator can do about it. */
    public function advice(): string
    {
        return self::advise($this->reason);
    }

    public function userMessage(): string
    {
        return $this->summary() . ' ' . $this->advice();
    }

    private static function what(string $reason, ?int $status): string
    {
        return match ($reason) {
            self::UNREACHABLE => 'pretix ist nicht erreichbar.',
            self::TIMEOUT => 'pretix hat nicht rechtzeitig geantwortet.',
            self::DENIED => "pretix hat den Zugang abgelehnt (HTTP {$status}).",
            self::NOT_FOUND => "pretix findet den Veranstalter oder eine Veranstaltung nicht (HTTP {$status}).",
            self::RATE_LIMITED => "pretix begrenzt gerade die Zahl der Anfragen (HTTP {$status}).",
            self::SERVER_ERROR => "pretix meldet einen Fehler (HTTP {$status}).",
            default => "pretix hat die Anfrage abgewiesen (HTTP {$status}).",
        };
    }

    private static function advise(string $reason): string
    {
        return match ($reason) {
            self::UNREACHABLE => 'Später erneut versuchen. Hält das an, unter „pretix-Verbindungen“ die Basis-URL prüfen.',
            self::DENIED => 'Unter „pretix-Verbindungen“ das API-Token prüfen und bei Bedarf ein neues eintragen.',
            self::NOT_FOUND => 'Unter „pretix-Verbindungen“ Basis-URL und Organizer-Slug prüfen.',
            self::REJECTED => 'Unter „pretix-Verbindungen“ die Angaben der Verbindung prüfen.',
            default => 'Später erneut versuchen.',
        };
    }

    private static function isTimeout(ConnectionException|RequestException $e): bool
    {
        $previous = $e->getPrevious();
        $errno = $previous instanceof ConnectException ? ($previous->getHandlerContext()['errno'] ?? null) : null;

        return $errno !== null
            ? (int) $errno === self::CURL_TIMEOUT
            : str_contains($e->getMessage(), 'cURL error ' . self::CURL_TIMEOUT . ':');
    }
}
