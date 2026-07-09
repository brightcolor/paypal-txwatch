<?php

namespace App\Services\PayPal\Exceptions;

/**
 * HTTP 429 / RATE_LIMIT_REACHED. Retryable after backing off.
 */
class PayPalRateLimitException extends PayPalApiException
{
}
