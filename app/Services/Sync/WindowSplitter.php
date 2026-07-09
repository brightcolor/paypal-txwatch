<?php

namespace App\Services\Sync;

use Carbon\CarbonInterface;
use DateInterval;
use Illuminate\Support\Carbon;

/**
 * Splits a date range into PayPal-API-compatible chunks.
 */
class WindowSplitter
{
    /**
     * Split [start, end] into consecutive windows of at most $maxDays each.
     * Used to satisfy PayPal's 31-day-per-request limit for backfills.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    public static function splitToMaxDays(CarbonInterface $start, CarbonInterface $end, int $maxDays = 31): array
    {
        $windows = [];
        $cursor = Carbon::instance($start->clone());
        $end = Carbon::instance($end->clone());

        while ($cursor->lt($end)) {
            $windowEnd = $cursor->clone()->addDays($maxDays);
            if ($windowEnd->gt($end)) {
                $windowEnd = $end->clone();
            }

            $windows[] = [$cursor->clone(), $windowEnd->clone()];
            $cursor = $windowEnd;
        }

        return $windows;
    }

    /**
     * Split [start, end] into consecutive windows of exactly $isoDuration
     * (e.g. "P7D", "P1D", "PT6H", "PT1H"). Used when PayPal answers
     * RESULTSET_TOO_LARGE and the window needs to shrink further.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    public static function splitByIsoDuration(CarbonInterface $start, CarbonInterface $end, string $isoDuration): array
    {
        $interval = new DateInterval($isoDuration);
        $windows = [];
        $cursor = Carbon::instance($start->clone());
        $end = Carbon::instance($end->clone());

        while ($cursor->lt($end)) {
            $windowEnd = $cursor->clone()->add($interval);
            if ($windowEnd->gt($end)) {
                $windowEnd = $end->clone();
            }

            $windows[] = [$cursor->clone(), $windowEnd->clone()];
            $cursor = $windowEnd;
        }

        return $windows;
    }
}
