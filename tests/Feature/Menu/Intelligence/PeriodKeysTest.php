<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\PeriodKeys;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

// Pure date math plus config: no database, so no RefreshDatabase.
class PeriodKeysTest extends TestCase
{
    private function today(string $date = '2026-11-07'): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $range
     */
    private function assertRange(string $from, string $to, ?array $range): void
    {
        $this->assertNotNull($range);
        $this->assertSame([$from, $to], [$range[0]->toDateString(), $range[1]->toDateString()]);
    }

    public function test_for_build_lists_complete_months_the_current_month_and_rolling_90d(): void
    {
        $this->assertSame(['2026-08', '2026-09', '2026-10', '2026-11', 'rolling_90d'], PeriodKeys::forBuild($this->today()));
    }

    public function test_complete_month_range_covers_the_whole_month(): void
    {
        $this->assertRange('2026-10-01', '2026-10-31', PeriodKeys::range('2026-10', $this->today()));
    }

    public function test_current_month_range_ends_yesterday(): void
    {
        $this->assertRange('2026-11-01', '2026-11-06', PeriodKeys::range('2026-11', $this->today()));
    }

    public function test_rolling_90d_range_is_the_90_days_before_today(): void
    {
        $this->assertRange('2026-08-09', '2026-11-06', PeriodKeys::range('rolling_90d', $this->today()));
    }

    public function test_previous_is_the_prior_calendar_month_and_null_for_rolling(): void
    {
        $this->assertRange('2026-09-01', '2026-09-30', PeriodKeys::previous('2026-10', $this->today()));
        $this->assertNull(PeriodKeys::previous('rolling_90d', $this->today()));
    }

    public function test_previous_crosses_the_year_boundary(): void
    {
        $this->assertRange('2025-12-01', '2025-12-31', PeriodKeys::previous('2026-01', $this->today()));
    }

    public function test_first_of_month_skips_the_empty_current_month(): void
    {
        $today = $this->today('2026-11-01');
        $keys = PeriodKeys::forBuild($today);

        $this->assertSame(['2026-08', '2026-09', '2026-10', 'rolling_90d'], $keys);

        foreach ($keys as $key) {
            [$from, $to] = PeriodKeys::range($key, $today);
            $this->assertTrue($from->lessThanOrEqualTo($to), "{$key} range is inverted");
        }

        $this->expectException(InvalidArgumentException::class);
        PeriodKeys::range('2026-11', $today);
    }

    public function test_unknown_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeriodKeys::range('2026-13', $this->today());
    }
}
