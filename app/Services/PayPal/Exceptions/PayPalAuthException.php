<?php

namespace App\Services\PayPal\Exceptions;

/**
 * Invalid client id/secret, or the app lost API access. Not retryable
 * without the user fixing credentials.
 */
class PayPalAuthException extends PayPalApiException
{
}
