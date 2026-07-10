<?php

namespace App\Services;

/**
 * Parses PayPal custom_field values that follow pretix' "Order <event>-<nr>"
 * scheme, e.g. "Order GAG-WISMAR-2026-SC3HR":
 *   - eventReference() -> "GAG-WISMAR-2026" (event short code / Verwendungszweck)
 *   - orderNumber()    -> "SC3HR"           (the pretix order number)
 *
 * The leading literal "Order" label and the trailing order number are stripped
 * positionally: the order number is always the last dash-separated segment
 * (it is alphanumeric, not digits-only, so it cannot be found by character
 * class), and the event reference is everything before it.
 */
class CustomFieldParser
{
    private static function normalize(?string $customField): ?string
    {
        if ($customField === null) {
            return null;
        }

        $value = trim($customField);
        // Strip a leading literal "Order" label (word-boundary, so "Ordernumber"
        // is left intact) plus any following spaces/colons - including the case
        // where "Order" is all there is.
        $value = trim((string) preg_replace('/^order\b[\s:]*/i', '', $value));

        return $value === '' ? null : $value;
    }

    /**
     * Event short code / Verwendungszweck: everything between "Order" and the
     * trailing order number. For a value without a dash (no separable order
     * number) the whole normalized value is returned.
     */
    public static function eventReference(?string $customField): ?string
    {
        $value = self::normalize($customField);

        if ($value === null) {
            return null;
        }

        $segments = explode('-', $value);

        if (count($segments) > 1) {
            array_pop($segments);
            $prefix = trim(implode('-', $segments));

            if ($prefix !== '') {
                return $prefix;
            }
        }

        return $value;
    }

    /**
     * pretix order number: the last dash-separated segment.
     */
    public static function orderNumber(?string $customField): ?string
    {
        $value = self::normalize($customField);

        if ($value === null) {
            return null;
        }

        $segments = explode('-', $value);
        $last = trim((string) array_pop($segments));

        return $last === '' ? $value : $last;
    }
}
