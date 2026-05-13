<?php

namespace Tests\Unit;

use App\Domain\ValueObjects\TimeRange;
use App\Exceptions\InvalidDateRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeRangeTest extends TestCase
{
    public function test_accepts_valid_range(): void
    {
        $r = TimeRange::fromInstants('2026-05-09T09:00:00Z', '2026-05-09T12:00:00Z');
        $this->assertSame(3.0, $r->durationHours());
    }

    public function test_rejects_end_equal_to_start(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        TimeRange::fromInstants('2026-05-09T09:00:00Z', '2026-05-09T09:00:00Z');
    }

    public function test_rejects_end_before_start(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        TimeRange::fromInstants('2026-05-09T12:00:00Z', '2026-05-09T09:00:00Z');
    }

    public function test_multi_day_range(): void
    {
        $r = TimeRange::fromInstants('2026-05-09T08:00:00Z', '2026-05-11T18:00:00Z');
        $this->assertSame(58.0, $r->durationHours());
    }

    public function test_identical_ranges_overlap(): void
    {
        $a = TimeRange::fromInstants('2026-05-09T09:00:00Z', '2026-05-09T12:00:00Z');
        $b = TimeRange::fromInstants('2026-05-09T09:00:00Z', '2026-05-09T12:00:00Z');
        $this->assertTrue($a->overlaps($b));
    }

    public function test_back_to_back_handover_does_not_overlap(): void
    {
        $a = TimeRange::fromInstants('2026-05-09T09:00:00Z', '2026-05-09T12:00:00Z');
        $after = TimeRange::fromInstants('2026-05-09T12:00:00Z', '2026-05-09T15:00:00Z');
        $before = TimeRange::fromInstants('2026-05-09T06:00:00Z', '2026-05-09T09:00:00Z');
        $this->assertFalse($a->overlaps($after));
        $this->assertFalse($a->overlaps($before));
    }

    #[DataProvider('overlappingCases')]
    public function test_overlap_detection(string $start, string $end): void
    {
        $a = TimeRange::fromInstants('2026-05-09T09:00:00Z', '2026-05-09T12:00:00Z');
        $b = TimeRange::fromInstants($start, $end);
        $this->assertTrue($a->overlaps($b));
    }

    public static function overlappingCases(): array
    {
        return [
            'partial overlap at start' => ['2026-05-09T08:00:00Z', '2026-05-09T10:00:00Z'],
            'partial overlap at end' => ['2026-05-09T11:00:00Z', '2026-05-09T13:00:00Z'],
            'fully contained' => ['2026-05-09T00:00:00Z', '2026-05-09T23:59:59Z'],
            'inside existing' => ['2026-05-09T10:00:00Z', '2026-05-09T11:00:00Z'],
        ];
    }

    public function test_multi_day_overlaps_one_hour_inside(): void
    {
        $multi = TimeRange::fromInstants('2026-05-09T00:00:00Z', '2026-05-12T00:00:00Z');
        $hour = TimeRange::fromInstants('2026-05-10T15:00:00Z', '2026-05-10T17:00:00Z');
        $this->assertTrue($multi->overlaps($hour));
    }

    public function test_two_adjacent_multi_day_ranges_do_not_overlap(): void
    {
        $a = TimeRange::fromInstants('2026-05-09T00:00:00Z', '2026-05-12T00:00:00Z');
        $b = TimeRange::fromInstants('2026-05-12T00:00:00Z', '2026-05-15T00:00:00Z');
        $this->assertFalse($a->overlaps($b));
    }

    public function test_invalid_date_string_throws(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        TimeRange::fromInstants('not-a-date', '2026-05-09T12:00:00Z');
    }
}
