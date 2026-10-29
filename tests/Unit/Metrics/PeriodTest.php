<?php

declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Domain\Metrics\Period\Period;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    public function test_constructor_accepts_valid_range(): void
    {
        $from = CarbonImmutable::parse('2026-10-01 10:00:00', 'Africa/Cairo');
        $to = CarbonImmutable::parse('2026-10-05 23:59:59', 'Africa/Cairo');

        $period = new Period($from, $to, '14:30');

        $this->assertSame('2026-10-01', $period->from->toDateString());
        $this->assertSame('2026-10-05', $period->to->toDateString());
        $this->assertSame('14:30', $period->cutoffTime);
    }

    public function test_constructor_accepts_single_day(): void
    {
        $d = CarbonImmutable::parse('2026-10-15', 'Africa/Cairo');
        $period = new Period($d, $d);

        $this->assertSame('2026-10-15', $period->from->toDateString());
        $this->assertSame('2026-10-15', $period->to->toDateString());
        $this->assertNull($period->cutoffTime);
    }

    public function test_constructor_rejects_from_after_to(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $from = CarbonImmutable::parse('2026-10-10', 'Africa/Cairo');
        $to = CarbonImmutable::parse('2026-10-05', 'Africa/Cairo');

        new Period($from, $to);
    }

    public function test_days_calculation(): void
    {
        $d1 = CarbonImmutable::parse('2026-10-01', 'Africa/Cairo');
        $d7 = CarbonImmutable::parse('2026-10-07', 'Africa/Cairo');

        $singleDay = new Period($d1, $d1);
        $this->assertSame(1, $singleDay->days());

        $week = new Period($d1, $d7);
        $this->assertSame(7, $week->days());

        $monthPeriod = new Period(
            CarbonImmutable::parse('2026-10-01', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-31', 'Africa/Cairo'),
        );
        $this->assertSame(31, $monthPeriod->days());

        // Across leap year day
        $leapPeriod = new Period(
            CarbonImmutable::parse('2028-02-28', 'Africa/Cairo'),
            CarbonImmutable::parse('2028-03-01', 'Africa/Cairo'),
        );
        $this->assertSame(3, $leapPeriod->days());
    }

    public function test_contains_date(): void
    {
        $period = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-20', 'Africa/Cairo'),
        );

        $this->assertTrue($period->contains(CarbonImmutable::parse('2026-10-10 00:00:00', 'Africa/Cairo')));
        $this->assertTrue($period->contains(CarbonImmutable::parse('2026-10-15 12:30:00', 'Africa/Cairo')));
        $this->assertTrue($period->contains(CarbonImmutable::parse('2026-10-20 23:59:59', 'Africa/Cairo')));

        $this->assertFalse($period->contains(CarbonImmutable::parse('2026-10-09 23:59:59', 'Africa/Cairo')));
        $this->assertFalse($period->contains(CarbonImmutable::parse('2026-10-21 00:00:00', 'Africa/Cairo')));
    }

    public function test_dates_generator(): void
    {
        $period = new Period(
            CarbonImmutable::parse('2026-10-01', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-04', 'Africa/Cairo'),
        );

        $dates = $period->dates();
        $this->assertCount(4, $dates);
        $this->assertSame('2026-10-01', $dates[0]->toDateString());
        $this->assertSame('2026-10-02', $dates[1]->toDateString());
        $this->assertSame('2026-10-03', $dates[2]->toDateString());
        $this->assertSame('2026-10-04', $dates[3]->toDateString());
    }

    public function test_shift(): void
    {
        $period = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-15', 'Africa/Cairo'),
            '12:00',
        );

        $shiftedForward = $period->shift(7);
        $this->assertSame('2026-10-17', $shiftedForward->from->toDateString());
        $this->assertSame('2026-10-22', $shiftedForward->to->toDateString());
        $this->assertSame('12:00', $shiftedForward->cutoffTime);

        $shiftedBackward = $period->shift(-7);
        $this->assertSame('2026-10-03', $shiftedBackward->from->toDateString());
        $this->assertSame('2026-10-08', $shiftedBackward->to->toDateString());
        $this->assertSame('12:00', $shiftedBackward->cutoffTime);
    }

    public function test_equals(): void
    {
        $p1 = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-15', 'Africa/Cairo'),
            '12:00',
        );
        $p2 = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-15', 'Africa/Cairo'),
            '12:00',
        );
        $p3 = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-15', 'Africa/Cairo'),
            '13:00',
        );
        $p4 = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-16', 'Africa/Cairo'),
            '12:00',
        );

        $this->assertTrue($p1->equals($p2));
        $this->assertFalse($p1->equals($p3));
        $this->assertFalse($p1->equals($p4));
    }

    public function test_to_array(): void
    {
        $period = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-15', 'Africa/Cairo'),
            '14:00',
        );

        $this->assertSame([
            'from' => '2026-10-10',
            'to' => '2026-10-15',
            'cutoff' => '14:00',
        ], $period->toArray());
    }
}
