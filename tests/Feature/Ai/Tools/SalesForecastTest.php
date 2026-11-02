<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\Finance\SalesForecastTool;
use App\Domain\Forecast\SalesForecast;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ReportFilters;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\MenuFixture;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class SalesForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_weeks_of_history_are_insufficient(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $fixture = TwoBranchesTwoWeeks::create();
        $result = (new SalesForecastTool($fixture->owner))->handle(new Request([
            'preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-31', 'compare' => 'none',
        ]));
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('insufficient_data', $decoded['metrics']['status']);
        $this->assertLessThan(28, $decoded['metrics']['complete_days_used']);
        $this->assertNotEmpty($decoded['summary']);
    }

    public function test_forecast_matches_weekday_medians_and_clamped_trend_from_56_complete_days(): void
    {
        $fixture = MenuFixture::create();
        $filters = ReportFilters::fromArray([
            'preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-11-16', 'compare' => 'none',
        ], CarbonImmutable::parse('2026-10-17'));
        $query = MetricsQuery::for($fixture->owner, $filters);
        $result = app(SalesForecast::class)->forecast($query, $filters->period->from, $filters->period->to);

        $history = DB::table('agg_branch_daily')->whereIn('branch_id', [$fixture->branchA->id, $fixture->branchB->id])
            ->whereBetween('business_date', ['2026-08-22', MenuFixture::TO])
            ->selectRaw('business_date, SUM(net_sales_after_returns) AS sales')
            ->groupBy('business_date')->orderBy('business_date')->get();
        $daily = $history->pluck('sales')->map(fn ($sales): float => (float) $sales)->all();
        $trend = max(0.85, min(1.15, array_sum(array_slice($daily, -28)) / array_sum(array_slice($daily, 0, 28))));
        $base = [];
        foreach ($history as $row) {
            $base[CarbonImmutable::parse($row->business_date)->dayOfWeek][] = (float) $row->sales;
        }
        $medians = [];
        foreach ($base as $weekday => $values) {
            $values = array_slice($values, -4);
            sort($values);
            $medians[$weekday] = ($values[1] + $values[2]) / 2;
        }
        $expected = 0.0;
        foreach ($filters->period->dates() as $date) {
            $expected += $medians[$date->dayOfWeek] * $trend;
        }

        $this->assertSame('ok', $result['status']);
        $this->assertSame(56, $result['complete_days_used']);
        $this->assertEqualsWithDelta(round($expected, 2), $result['expected'], 0.01);
        $this->assertLessThan($result['expected'], $result['low']);
        $this->assertGreaterThan($result['expected'], $result['high']);
        $this->assertEqualsWithDelta($result['expected'], array_sum(array_column($result['by_week'], 'expected')), 0.05);
    }

    public function test_incomplete_branch_days_are_excluded_and_older_complete_days_backfill_history(): void
    {
        $fixture = MenuFixture::create();
        DB::table('agg_branch_daily')->where('branch_id', $fixture->branchB->id)
            ->whereBetween('business_date', ['2026-10-12', MenuFixture::TO])
            ->update(['is_reconciled' => false, 'net_sales_after_returns' => 9999999]);
        $filters = ReportFilters::fromArray([
            'preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-23', 'compare' => 'none',
        ], CarbonImmutable::parse('2026-10-17'));
        $forecast = app(SalesForecast::class);
        $owner = $forecast->forecast(MetricsQuery::for($fixture->owner, $filters), $filters->period->from, $filters->period->to);
        $manager = $forecast->forecast(MetricsQuery::for($fixture->managerA, $filters), $filters->period->from, $filters->period->to);

        $this->assertSame(55, $owner['complete_days_used']);
        $this->assertSame(56, $manager['complete_days_used']);
        $this->assertLessThan(100000, $owner['expected']);
        $this->assertSame('2026-10-11', $owner['history_to']);
        $this->assertSame(MenuFixture::TO, $manager['history_to']);

        $toolResult = json_decode((new SalesForecastTool($fixture->owner))->handle(new Request([
            'preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-23', 'compare' => 'none',
        ])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('2026-10-16', implode(' ', $toolResult['completeness']['warnings']));
        $this->assertSame('2026-10-16', $toolResult['metrics']['history_cutoff']);
    }

    public function test_history_never_uses_target_days_and_sparse_weekdays_do_not_produce_a_forecast(): void
    {
        $fixture = MenuFixture::create();
        $filters = ReportFilters::fromArray([
            'preset' => 'custom', 'from' => '2026-08-25', 'to' => '2026-08-31', 'compare' => 'none',
        ], CarbonImmutable::parse('2026-10-17'));
        $forecast = app(SalesForecast::class);
        $result = $forecast->forecast(MetricsQuery::for($fixture->owner, $filters), $filters->period->from, $filters->period->to);
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertSame(7, $result['complete_days_used']);

        DB::table('agg_branch_daily')->whereRaw('DAYOFWEEK(business_date) = 1')->update(['is_reconciled' => false]);
        $filters = ReportFilters::fromArray([
            'preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-23', 'compare' => 'none',
        ], CarbonImmutable::parse('2026-10-17'));
        $result = $forecast->forecast(MetricsQuery::for($fixture->owner, $filters), $filters->period->from, $filters->period->to);
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertGreaterThanOrEqual(28, $result['complete_days_used']);
    }

    public function test_zero_sales_history_is_finite(): void
    {
        $fixture = MenuFixture::create();
        DB::table('agg_branch_daily')->update(['net_sales_after_returns' => 0]);
        $result = json_decode((new SalesForecastTool($fixture->owner))->handle(new Request([
            'preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-23', 'compare' => 'none',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $result['metrics']['status']);
        $this->assertEquals(0, $result['metrics']['expected']);
        $this->assertEquals(0, $result['metrics']['low']);
        $this->assertEquals(0, $result['metrics']['high']);
    }

    public function test_35_days_of_steady_sales_use_a_neutral_trend(): void
    {
        $fixture = MenuFixture::create();
        DB::table('agg_branch_daily')->where('business_date', '<', '2026-09-12')->delete();
        DB::table('agg_branch_daily')->update(['net_sales_after_returns' => 100]);
        $result = json_decode((new SalesForecastTool($fixture->owner))->handle(new Request([
            'preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-23', 'compare' => 'none',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(35, $result['metrics']['complete_days_used']);
        $this->assertEquals(1, $result['metrics']['trend']);
        $this->assertEquals(1400, $result['metrics']['expected']);
        $this->assertSame('low', $result['metrics']['confidence']);
    }

    public function test_fewer_than_200_completed_orders_is_insufficient_even_with_56_complete_days(): void
    {
        $fixture = MenuFixture::create();
        DB::table('agg_branch_daily')->update(['orders_count' => 1]);
        $result = json_decode((new SalesForecastTool($fixture->owner))->handle(new Request([
            'preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-23', 'compare' => 'none',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('insufficient_data', $result['metrics']['status']);
        $this->assertSame(56, $result['metrics']['complete_days_used']);
        $this->assertSame(112, $result['metrics']['orders_considered']);
    }

    public function test_future_forecast_explicitly_rejects_like_for_like_instead_of_resolving_an_empty_scope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'Africa/Cairo'));
        $fixture = MenuFixture::create();
        $args = ['preset' => 'custom', 'from' => '2026-12-01', 'to' => '2026-12-31', 'compare' => 'previous'];
        $tool = new SalesForecastTool($fixture->owner);
        $normal = json_decode($tool->handle(new Request($args)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $normal['metrics']['status']);
        $this->assertCount(2, $normal['branches']);

        $unsupported = json_decode($tool->handle(new Request($args + ['like_for_like' => true])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('unsupported_filter', $unsupported['error']);
        $this->assertStringContainsString('الفروع المتماثلة', $unsupported['message_ar']);
        $this->assertArrayNotHasKey('metrics', $unsupported);

        $denied = json_decode((new SalesForecastTool($fixture->managerA))->handle(new Request($args + [
            'like_for_like' => true, 'branches' => [$fixture->branchB->id],
        ])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('forbidden_branch', $denied['error']);
    }
}
