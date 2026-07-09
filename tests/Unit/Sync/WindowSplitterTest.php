<?php

namespace Tests\Unit\Sync;

use App\Services\Sync\WindowSplitter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class WindowSplitterTest extends TestCase
{
    public function test_it_does_not_split_a_window_under_the_limit(): void
    {
        $windows = WindowSplitter::splitToMaxDays(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-10'), 31);

        $this->assertCount(1, $windows);
        $this->assertTrue($windows[0][0]->equalTo(Carbon::parse('2026-06-01')));
        $this->assertTrue($windows[0][1]->equalTo(Carbon::parse('2026-06-10')));
    }

    public function test_it_splits_a_year_into_31_day_windows_with_no_gaps_or_overlaps(): void
    {
        $start = Carbon::parse('2026-01-01');
        $end = Carbon::parse('2027-01-01');

        $windows = WindowSplitter::splitToMaxDays($start, $end, 31);

        $this->assertGreaterThanOrEqual(12, count($windows));

        // No gaps: each window's end must equal the next window's start.
        for ($i = 0; $i < count($windows) - 1; $i++) {
            $this->assertTrue($windows[$i][1]->equalTo($windows[$i + 1][0]));
        }

        // Fully covers the requested range.
        $this->assertTrue($windows[0][0]->equalTo($start));
        $this->assertTrue(end($windows)[1]->equalTo($end));

        // No window exceeds the 31-day limit.
        foreach ($windows as [$s, $e]) {
            $this->assertLessThanOrEqual(31, $s->diffInDays($e));
        }
    }

    public function test_it_splits_by_iso_duration_for_resultset_too_large_shrinking(): void
    {
        $windows = WindowSplitter::splitByIsoDuration(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-06'), 'P1D');

        $this->assertCount(5, $windows);
        foreach ($windows as [$s, $e]) {
            $this->assertEquals(1, $s->diffInDays($e));
        }
    }

    public function test_it_clamps_the_last_hourly_window_to_the_exact_end(): void
    {
        $windows = WindowSplitter::splitByIsoDuration(Carbon::parse('2026-06-01 00:00'), Carbon::parse('2026-06-01 02:30'), 'PT1H');

        $this->assertCount(3, $windows);
        $this->assertTrue($windows[2][1]->equalTo(Carbon::parse('2026-06-01 02:30')));
        $this->assertEquals(30, $windows[2][0]->diffInMinutes($windows[2][1]));
    }
}
