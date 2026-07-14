<?php

namespace App\Services\Bank;

/**
 * Thrown when the bank demands strong authentication (a TAN) for an action that
 * the unattended sync cannot answer. The connection is then flipped to
 * "needs_reauth" so the operator re-authorises via the FinTS settings page.
 */
class FintsNeedsTanException extends \RuntimeException
{
}
