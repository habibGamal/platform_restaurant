<?php

declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\PeriodResolver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PeriodResolverTest extends TestCase
{
    private CarbonImmutable $fixedThursday;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-10-29 is a Thursday.
        // Egyptian week starts Saturday 2026-10-24, ends Friday 2026-10-30.
        $this->fixedThursday = CarbonImmutable::parse('2026-10-29', 'Africa/Cairo');
    }

    public function test_all_presets_on_fixed_date(): void
    {
        $today = $this->fixedThursday;
        $nowTime = '16:30';

        // 1. today
        $todayPeriod = PeriodResolver::resolve(PeriodPreset::today, $today, nowTime: $nowTime);
        $this->assertSame('2026-10-29', $todayPeriod->from->toDateString());
        $this->assertSame('2026-10-29', $todayPeriod->to->toDateString());
        $this->assertSame('16:30', $todayPeriod->cutoffTime);

        // 2. yesterday
        $yesterdayPeriod = PeriodResolver::resolve(PeriodPreset::yesterday, $today, nowTime: $nowTime);
        $this->assertSame('2026-10-28', $yesterdayPeriod->from->toDateString());
        $this->assertSame('2026-10-28', $yesterdayPeriod->to->toDateString());
        $this->assertNull($yesterdayPeriod->cutoffTime);

        // 3. this_week (Saturday 2026-10-24 .. today 2026-10-29)
        $thisWeekPeriod = PeriodResolver::resolve(PeriodPreset::this_week, $today, nowTime: $nowTime);
        $this->assertSame('2026-10-24', $thisWeekPeriod->from->toDateString());
        $this->assertSame('2026-10-29', $thisWeekPeriod->to->toDateString());
        $this->assertSame('16:30', $thisWeekPeriod->cutoffTime);

        // 4. last_week (Saturday 2026-10-17 .. Friday 2026-10-23)
        $lastWeekPeriod = PeriodResolver::resolve(PeriodPreset::last_week, $today, nowTime: $nowTime);
        $this->assertSame('2026-10-17', $lastWeekPeriod->from->toDateString());
        $this->assertSame('2026-10-23', $lastWeekPeriod->to->toDateString());
        $this->assertNull($lastWeekPeriod->cutoffTime);

        // 5. this_month (2026-10-01 .. today 2026-10-29)
        $thisMonthPeriod = PeriodResolver::resolve(PeriodPreset::this_month, $today, nowTime: $nowTime);
        $this->assertSame('2026-10-01', $thisMonthPeriod->from->toDateString());
        $this->assertSame('2026-10-29', $thisMonthPeriod->to->toDateString());
        $this->assertSame('16:30', $thisMonthPeriod->cutoffTime);

        // 6. last_month (2026-09-01 .. 2026-09-30)
        $lastMonthPeriod = PeriodResolver::resolve(PeriodPreset::last_month, $today, nowTime: $nowTime);
        $this->assertSame('2026-09-01', $lastMonthPeriod->from->toDateString());
        $this->assertSame('2026-09-30', $lastMonthPeriod->to->toDateString());
        $this->assertNull($lastMonthPeriod->cutoffTime);

        // 7. this_quarter (2026-10-01 .. today 2026-10-29)
        $thisQuarterPeriod = PeriodResolver::resolve(PeriodPreset::this_quarter, $today, nowTime: $nowTime);
        $this->assertSame('2026-10-01', $thisQuarterPeriod->from->toDateString());
        $this->assertSame('2026-10-29', $thisQuarterPeriod->to->toDateString());
        $this->assertSame('16:30', $thisQuarterPeriod->cutoffTime);

        // 8. this_year (2026-01-01 .. today 2026-10-29)
        $thisYearPeriod = PeriodResolver::resolve(PeriodPreset::this_year, $today, nowTime: $nowTime);
        $this->assertSame('2026-01-01', $thisYearPeriod->from->toDateString());
        $this->assertSame('2026-10-29', $thisYearPeriod->to->toDateString());
        $this->assertSame('16:30', $thisYearPeriod->cutoffTime);

        // 9. last_30_days (today - 29 days = 2026-09-30 .. today 2026-10-29)
        $last30DaysPeriod = PeriodResolver::resolve(PeriodPreset::last_30_days, $today, nowTime: $nowTime);
        $this->assertSame('2026-09-30', $last30DaysPeriod->from->toDateString());
        $this->assertSame('2026-10-29', $last30DaysPeriod->to->toDateString());
        $this->assertSame(30, $last30DaysPeriod->days());
        $this->assertSame('16:30', $last30DaysPeriod->cutoffTime);

        // 10. last_8_weeks (Saturday 7 weeks before 2026-10-24 is 2026-09-05 .. today 2026-10-29)
        $last8WeeksPeriod = PeriodResolver::resolve(PeriodPreset::last_8_weeks, $today, nowTime: $nowTime);
        $this->assertSame('2026-09-05', $last8WeeksPeriod->from->toDateString());
        $this->assertSame('2026-10-29', $last8WeeksPeriod->to->toDateString());
        $this->assertSame('16:30', $last8WeeksPeriod->cutoffTime);

        // 11. custom
        $customPeriod = PeriodResolver::resolve(
            PeriodPreset::custom,
            $today,
            CarbonImmutable::parse('2026-05-01', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-05-10', 'Africa/Cairo'),
            nowTime: $nowTime,
        );
        $this->assertSame('2026-05-01', $customPeriod->from->toDateString());
        $this->assertSame('2026-05-10', $customPeriod->to->toDateString());
        $this->assertNull($customPeriod->cutoffTime);

        // custom ending on today keeps cutoffTime
        $customEndingToday = PeriodResolver::resolve(
            PeriodPreset::custom,
            $today,
            CarbonImmutable::parse('2026-10-15', 'Africa/Cairo'),
            $today,
            nowTime: $nowTime,
        );
        $this->assertSame('16:30', $customEndingToday->cutoffTime);
    }

    public function test_custom_requires_both_from_and_to(): void
    {
        $today = $this->fixedThursday;

        $this->expectException(InvalidArgumentException::class);
        PeriodResolver::resolve(PeriodPreset::custom, $today, null, null);
    }

    public function test_saturday_week_start_behavior(): void
    {
        // When today is Saturday 2026-10-24
        $saturday = CarbonImmutable::parse('2026-10-24', 'Africa/Cairo');
        $thisWeekOnSat = PeriodResolver::resolve(PeriodPreset::this_week, $saturday);
        $this->assertSame('2026-10-24', $thisWeekOnSat->from->toDateString());
        $this->assertSame('2026-10-24', $thisWeekOnSat->to->toDateString());

        // When today is Friday 2026-10-30
        $friday = CarbonImmutable::parse('2026-10-30', 'Africa/Cairo');
        $thisWeekOnFri = PeriodResolver::resolve(PeriodPreset::this_week, $friday);
        $this->assertSame('2026-10-24', $thisWeekOnFri->from->toDateString());
        $this->assertSame('2026-10-30', $thisWeekOnFri->to->toDateString());
        $this->assertSame(7, $thisWeekOnFri->days());

        // last_week on Saturday 2026-10-24
        $lastWeekOnSat = PeriodResolver::resolve(PeriodPreset::last_week, $saturday);
        $this->assertSame('2026-10-17', $lastWeekOnSat->from->toDateString());
        $this->assertSame('2026-10-23', $lastWeekOnSat->to->toDateString());
    }

    public function test_default_comparison(): void
    {
        $this->assertSame(ComparisonMode::same_weekday_last_week, PeriodResolver::defaultComparison(PeriodPreset::today));
        $this->assertSame(ComparisonMode::same_weekday_last_week, PeriodResolver::defaultComparison(PeriodPreset::yesterday));
        $this->assertSame(ComparisonMode::same_weekday_last_week, PeriodResolver::defaultComparison(PeriodPreset::this_week));
        $this->assertSame(ComparisonMode::same_weekday_last_week, PeriodResolver::defaultComparison(PeriodPreset::last_week));

        $this->assertSame(ComparisonMode::same_period_last_year, PeriodResolver::defaultComparison(PeriodPreset::this_quarter));
        $this->assertSame(ComparisonMode::same_period_last_year, PeriodResolver::defaultComparison(PeriodPreset::this_year));

        $this->assertSame(ComparisonMode::previous, PeriodResolver::defaultComparison(PeriodPreset::this_month));
        $this->assertSame(ComparisonMode::previous, PeriodResolver::defaultComparison(PeriodPreset::last_month));
        $this->assertSame(ComparisonMode::previous, PeriodResolver::defaultComparison(PeriodPreset::last_30_days));
        $this->assertSame(ComparisonMode::previous, PeriodResolver::defaultComparison(PeriodPreset::last_8_weeks));
        $this->assertSame(ComparisonMode::previous, PeriodResolver::defaultComparison(PeriodPreset::custom));
    }

    public function test_comparison_mode_none(): void
    {
        $current = PeriodResolver::resolve(PeriodPreset::today, $this->fixedThursday);
        $comp = PeriodResolver::comparison($current, ComparisonMode::none, PeriodPreset::today);
        $this->assertNull($comp);
    }

    public function test_comparison_mode_same_weekday_last_week(): void
    {
        $current = PeriodResolver::resolve(PeriodPreset::this_week, $this->fixedThursday, nowTime: '11:00');
        $comp = PeriodResolver::comparison($current, ComparisonMode::same_weekday_last_week, PeriodPreset::this_week);

        $this->assertNotNull($comp);
        $this->assertSame('2026-10-17', $comp->from->toDateString());
        $this->assertSame('2026-10-22', $comp->to->toDateString());
        $this->assertSame('11:00', $comp->cutoffTime);
    }

    public function test_comparison_mode_same_period_last_year(): void
    {
        // 364 days shift aligns weekdays perfectly
        $current = PeriodResolver::resolve(PeriodPreset::today, $this->fixedThursday, nowTime: '15:00');
        $comp = PeriodResolver::comparison($current, ComparisonMode::same_period_last_year, PeriodPreset::today);

        $this->assertNotNull($comp);
        $this->assertSame('2025-10-30', $comp->from->toDateString());
        $this->assertSame('2025-10-30', $comp->to->toDateString());
        $this->assertSame('15:00', $comp->cutoffTime);

        // Verify weekday alignment: both should be Thursday
        $this->assertSame('Thursday', $current->from->format('l'));
        $this->assertSame('Thursday', $comp->from->format('l'));
    }

    public function test_comparison_previous_month_end_clipping_non_leap(): void
    {
        // On March 31, 2026 (non-leap year, Feb 2026 has 28 days)
        $mar31 = CarbonImmutable::parse('2026-03-31', 'Africa/Cairo');
        $thisMonth = PeriodResolver::resolve(PeriodPreset::this_month, $mar31, nowTime: '12:00');
        $comp = PeriodResolver::comparison($thisMonth, ComparisonMode::previous, PeriodPreset::this_month);

        $this->assertNotNull($comp);
        $this->assertSame('2026-02-01', $comp->from->toDateString());
        $this->assertSame('2026-02-28', $comp->to->toDateString());
        $this->assertSame('12:00', $comp->cutoffTime);
    }

    public function test_comparison_previous_month_end_clipping_leap_year_2028(): void
    {
        // On March 31, 2028 (leap year, Feb 2028 has 29 days)
        $mar31_2028 = CarbonImmutable::parse('2028-03-31', 'Africa/Cairo');
        $thisMonth = PeriodResolver::resolve(PeriodPreset::this_month, $mar31_2028, nowTime: '14:20');
        $comp = PeriodResolver::comparison($thisMonth, ComparisonMode::previous, PeriodPreset::this_month);

        $this->assertNotNull($comp);
        $this->assertSame('2028-02-01', $comp->from->toDateString());
        $this->assertSame('2028-02-29', $comp->to->toDateString());
        $this->assertSame('14:20', $comp->cutoffTime);
    }

    public function test_comparison_previous_this_month_mid_month(): void
    {
        // this_month on Oct 14 -> Sep 1..Sep 14
        $oct14 = CarbonImmutable::parse('2026-10-14', 'Africa/Cairo');
        $thisMonth = PeriodResolver::resolve(PeriodPreset::this_month, $oct14);
        $comp = PeriodResolver::comparison($thisMonth, ComparisonMode::previous, PeriodPreset::this_month);

        $this->assertNotNull($comp);
        $this->assertSame('2026-09-01', $comp->from->toDateString());
        $this->assertSame('2026-09-14', $comp->to->toDateString());
    }

    public function test_comparison_previous_last_month(): void
    {
        // last_month in October 2026 is September (2026-09-01 .. 2026-09-30)
        // previous unit before it is August (2026-08-01 .. 2026-08-31)
        $octToday = CarbonImmutable::parse('2026-10-15', 'Africa/Cairo');
        $lastMonth = PeriodResolver::resolve(PeriodPreset::last_month, $octToday);
        $comp = PeriodResolver::comparison($lastMonth, ComparisonMode::previous, PeriodPreset::last_month);

        $this->assertNotNull($comp);
        $this->assertSame('2026-08-01', $comp->from->toDateString());
        $this->assertSame('2026-08-31', $comp->to->toDateString());
    }

    public function test_comparison_previous_last_week(): void
    {
        // last_week on fixedThursday (2026-10-17 .. 2026-10-23)
        // full previous unit before it is (2026-10-10 .. 2026-10-16)
        $lastWeek = PeriodResolver::resolve(PeriodPreset::last_week, $this->fixedThursday);
        $comp = PeriodResolver::comparison($lastWeek, ComparisonMode::previous, PeriodPreset::last_week);

        $this->assertNotNull($comp);
        $this->assertSame('2026-10-10', $comp->from->toDateString());
        $this->assertSame('2026-10-16', $comp->to->toDateString());
    }

    public function test_comparison_previous_otherwise_arbitrary_range(): void
    {
        // Custom or last_30_days uses immediately preceding range of equal length
        $period = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-14', 'Africa/Cairo'),
            '10:00',
        );
        $this->assertSame(5, $period->days());

        $comp = PeriodResolver::comparison($period, ComparisonMode::previous, PeriodPreset::custom);
        $this->assertNotNull($comp);
        $this->assertSame('2026-10-05', $comp->from->toDateString());
        $this->assertSame('2026-10-09', $comp->to->toDateString());
        $this->assertSame(5, $comp->days());
        $this->assertSame('10:00', $comp->cutoffTime);
    }

    public function test_labels_for_presets_and_comparisons(): void
    {
        $this->assertSame('اليوم', PeriodPreset::today->label());
        $this->assertSame('أمس', PeriodPreset::yesterday->label());
        $this->assertSame('هذا الأسبوع', PeriodPreset::this_week->label());
        $this->assertSame('الأسبوع الماضي', PeriodPreset::last_week->label());
        $this->assertSame('هذا الشهر', PeriodPreset::this_month->label());
        $this->assertSame('الشهر الماضي', PeriodPreset::last_month->label());
        $this->assertSame('هذا الربع', PeriodPreset::this_quarter->label());
        $this->assertSame('هذا العام', PeriodPreset::this_year->label());
        $this->assertSame('آخر 30 يوم', PeriodPreset::last_30_days->label());
        $this->assertSame('آخر 8 أسابيع', PeriodPreset::last_8_weeks->label());
        $this->assertSame('فترة مخصصة', PeriodPreset::custom->label());

        $this->assertSame('الفترة السابقة', ComparisonMode::previous->label());
        $this->assertSame('نفس الأيام الأسبوع الماضي', ComparisonMode::same_weekday_last_week->label());
        $this->assertSame('نفس الفترة العام الماضي', ComparisonMode::same_period_last_year->label());
        $this->assertSame('بدون مقارنة', ComparisonMode::none->label());
    }
}
