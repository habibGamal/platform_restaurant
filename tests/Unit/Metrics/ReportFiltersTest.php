<?php

declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReportFiltersTest extends TestCase
{
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::parse('2026-10-29', 'Africa/Cairo');
    }

    public function test_defaults(): void
    {
        $filters = ReportFilters::fromArray([], $this->today);

        $this->assertSame(PeriodPreset::today, $filters->preset);
        $this->assertSame('2026-10-29', $filters->period->from->toDateString());
        $this->assertSame('2026-10-29', $filters->period->to->toDateString());
        $this->assertSame(ComparisonMode::same_weekday_last_week, $filters->comparisonMode);
        $this->assertNotNull($filters->comparison);
        $this->assertSame('2026-10-22', $filters->comparison->from->toDateString());
        $this->assertSame('2026-10-22', $filters->comparison->to->toDateString());
        $this->assertNull($filters->branchIds);
        $this->assertNull($filters->branchSetId);
        $this->assertNull($filters->restaurantId);
        $this->assertFalse($filters->likeForLike);
        $this->assertNull($filters->channel);
        $this->assertSame(Granularity::day, $filters->granularity);

        // toArray on default object omits all default/null values
        $this->assertSame([], $filters->toArray());
    }

    public function test_branches_parsing(): void
    {
        // Comma-separated string
        $filters = ReportFilters::fromArray(['branches' => '3,7,9'], $this->today);
        $this->assertSame([3, 7, 9], $filters->branchIds);

        // Comma string with whitespace
        $filtersSpaces = ReportFilters::fromArray(['branches' => ' 3 , 7 , 9 '], $this->today);
        $this->assertSame([3, 7, 9], $filtersSpaces->branchIds);

        // Int array
        $filtersArray = ReportFilters::fromArray(['branches' => [3, 7, 9]], $this->today);
        $this->assertSame([3, 7, 9], $filtersArray->branchIds);

        // String array
        $filtersStrArray = ReportFilters::fromArray(['branches' => ['3', '7', '9']], $this->today);
        $this->assertSame([3, 7, 9], $filtersStrArray->branchIds);

        // Invalid or empty strings
        $this->assertNull(ReportFilters::fromArray(['branches' => ''], $this->today)->branchIds);
        $this->assertNull(ReportFilters::fromArray(['branches' => 'abc,def'], $this->today)->branchIds);
        $this->assertNull(ReportFilters::fromArray(['branches' => []], $this->today)->branchIds);
    }

    public function test_round_trip(): void
    {
        $input = [
            'preset' => 'last_month',
            'compare' => 'same_period_last_year',
            'branches' => [3, 7, 9],
            'set' => 2,
            'restaurant' => 5,
            'lfl' => '1',
            'channel' => 'dine-in',
            'granularity' => 'week',
        ];

        $filters = ReportFilters::fromArray($input, $this->today);
        $array = $filters->toArray();

        $this->assertSame($input, $array);

        // Round trip: reconstruct from array
        $restored = ReportFilters::fromArray($array, $this->today);
        $this->assertSame(PeriodPreset::last_month, $restored->preset);
        $this->assertSame(ComparisonMode::same_period_last_year, $restored->comparisonMode);
        $this->assertSame([3, 7, 9], $restored->branchIds);
        $this->assertSame(2, $restored->branchSetId);
        $this->assertSame(5, $restored->restaurantId);
        $this->assertTrue($restored->likeForLike);
        $this->assertSame('dine-in', $restored->channel);
        $this->assertSame(Granularity::week, $restored->granularity);
        $this->assertSame($array, $restored->toArray());
    }

    public function test_custom_preset_round_trip(): void
    {
        $input = [
            'preset' => 'custom',
            'from' => '2026-05-01',
            'to' => '2026-05-15',
        ];

        $filters = ReportFilters::fromArray($input, $this->today);
        $this->assertSame(PeriodPreset::custom, $filters->preset);
        $this->assertSame('2026-05-01', $filters->period->from->toDateString());
        $this->assertSame('2026-05-15', $filters->period->to->toDateString());

        $array = $filters->toArray();
        $this->assertSame('custom', $array['preset']);
        $this->assertSame('2026-05-01', $array['from']);
        $this->assertSame('2026-05-15', $array['to']);

        $restored = ReportFilters::fromArray($array, $this->today);
        $this->assertSame(PeriodPreset::custom, $restored->preset);
        $this->assertSame('2026-05-01', $restored->period->from->toDateString());
        $this->assertSame('2026-05-15', $restored->period->to->toDateString());
    }

    public function test_invalid_input_fallback_never_throws(): void
    {
        $badInput = [
            'preset' => 'invalid_preset_value',
            'compare' => 'nonexistent_mode',
            'granularity' => 'hourly_which_doesnt_exist',
            'branches' => 'not,valid,numbers',
            'set' => 'non_numeric',
            'restaurant' => -5,
            'lfl' => 'unrecognized',
            'channel' => '',
        ];

        $filters = ReportFilters::fromArray($badInput, $this->today);

        // Fallbacks
        $this->assertSame(PeriodPreset::today, $filters->preset);
        $this->assertSame(ComparisonMode::same_weekday_last_week, $filters->comparisonMode);
        $this->assertSame(Granularity::day, $filters->granularity);
        $this->assertNull($filters->branchIds);
        $this->assertNull($filters->branchSetId);
        $this->assertNull($filters->restaurantId);
        $this->assertFalse($filters->likeForLike);
        $this->assertNull($filters->channel);
    }

    public function test_custom_without_valid_dates_falls_back_to_today(): void
    {
        // Missing from and to
        $f1 = ReportFilters::fromArray(['preset' => 'custom'], $this->today);
        $this->assertSame(PeriodPreset::today, $f1->preset);

        // Invalid date format
        $f2 = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => 'not-a-date',
            'to' => '2026-10-15',
        ], $this->today);
        $this->assertSame(PeriodPreset::today, $f2->preset);

        // from > to
        $f3 = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-20',
            'to' => '2026-10-10',
        ], $this->today);
        $this->assertSame(PeriodPreset::today, $f3->preset);

        // Invalid calendar date (e.g. Feb 31)
        $f4 = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-02-31',
            'to' => '2026-03-05',
        ], $this->today);
        $this->assertSame(PeriodPreset::today, $f4->preset);
    }
}
