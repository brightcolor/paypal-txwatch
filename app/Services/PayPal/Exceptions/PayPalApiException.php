<?php

namespace App\Services\PayPal\Exceptions;

use Exception;

/**
 * Base class for all PayPal API failures. Carries the PayPal error "name"
 * (e.g. RESULTSET_TOO_LARGE, RATE_LIMIT_REACHED) and the raw response body
 * so callers can log/inspect without ever leaking secrets.
 */
class PayPalApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $paypalErrorName = null,
        public readonly int $statusCode = 0,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(int $status, array $body, string $fallbackMessage): static
    {
        $name = $body['name'] ?? null;
        $message = $body['message'] ?? $fallbackMessage;

        return new static($message, $name, $status, $body);
    }
}
