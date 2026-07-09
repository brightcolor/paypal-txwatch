<?php

namespace App\Services\PayPal\Exceptions;

/**
 * The requested window/page combination exceeds PayPal's 10,000 record
 * limit per search. Callers should split the window and retry.
 */
class PayPalResultSetTooLargeException extends PayPalApiException
{
}
