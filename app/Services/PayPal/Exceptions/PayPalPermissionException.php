<?php

namespace App\Services\PayPal\Exceptions;

/**
 * Credentials are valid but the app/account is missing the
 * "Transaction Search" permission in the PayPal developer dashboard.
 */
class PayPalPermissionException extends PayPalApiException
{
}
