<?php

namespace App\Services\Auth;

/** Outcome of an unlock PIN entry. */
enum PinCheck
{
    /** Correct; the count of wrong entries starts over. */
    case Accepted;

    /** Wrong, attempts are left. */
    case Rejected;

    /** No PIN set, or the wrong entries reached the limit. */
    case Locked;
}
