<?php

declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GranularityTest extends TestCase
{
    public function test_auto_thresholds(): void
    {
        $today = CarbonImmutable::parse('2026-10-29', 'Africa/Cairo');

        // <= 31 days -> day
        $p1 = new Period($today, $today);
        $this->assertSame(1, $p1->days());
        $this->assertSame(Granularity::day, Granularity::auto($p1));

        $p31 = new Period($today->subDays(30), $today);
        $this->assertSame(31, $p31->days());
        $this->assertSame(Granularity::day, Granularity::auto($p31));

        // 32..182 days -> week
        $p32 = new Period($today->subDays(31), $today);
        $this->assertSame(32, $p32->days());
        $this->assertSame(Granularity::week, Granularity::auto($p32));

        $p182 = new Period($today->subDays(181), $today);
        $this->assertSame(182, $p182->days());
        $this->assertSame(Granularity::week, Granularity::auto($p182));

        // > 182 days -> month
        $p183 = new Period($today->subDays(182), $today);
        $this->assertSame(183, $p183->days());
        $this->assertSame(Granularity::month, Granularity::auto($p183));

        $pYear = new Period($today->subDays(364), $today);
        $this->assertSame(Granularity::month, Granularity::auto($pYear));
    }

    public function test_bucket_start_day(): void
    {
        $date = CarbonImmutable::parse('2026-10-29 15:45:00', 'Africa/Cairo');
        $bucket = Granularity::day->bucketStart($date);

        $this->assertSame('2026-10-29', $bucket->toDateString());
        $this->assertSame('00:00:00', $bucket->toTimeString());
    }

    public function test_bucket_start_week_is_saturday(): void
    {
        // 2026-10-24 is Saturday, 2026-10-30 is Friday
        $saturday = CarbonImmutable::parse('2026-10-24', 'Africa/Cairo');
        $this->assertSame('2026-10-24', Granularity::week->bucketStart($saturday)->toDateString());

        $sunday = CarbonImmutable::parse('2026-10-25', 'Africa/Cairo');
        $this->assertSame('2026-10-24', Granularity::week->bucketStart($sunday)->toDateString());

        $thursday = CarbonImmutable::parse('2026-10-29', 'Africa/Cairo');
        $this->assertSame('2026-10-24', Granularity::week->bucketStart($thursday)->toDateString());

        $friday = CarbonImmutable::parse('2026-10-30', 'Africa/Cairo');
        $this->assertSame('2026-10-24', Granularity::week->bucketStart($friday)->toDateString());

        // Next Saturday starts next bucket
        $nextSaturday = CarbonImmutable::parse('2026-10-31', 'Africa/Cairo');
        $this->assertSame('2026-10-31', Granularity::week->bucketStart($nextSaturday)->toDateString());
    }

    public function test_bucket_start_month(): void
    {
        $date = CarbonImmutable::parse('2026-10-29 18:00:00', 'Africa/Cairo');
        $bucket = Granularity::month->bucketStart($date);

        $this->assertSame('2026-10-01', $bucket->toDateString());
        $this->assertSame('00:00:00', $bucket->toTimeString());
    }

    public function test_arabic_labels(): void
    {
        $this->assertSame('يومي', Granularity::day->label());
        $this->assertSame('أسبوعي', Granularity::week->label());
        $this->assertSame('شهري', Granularity::month->label());
    }
}
