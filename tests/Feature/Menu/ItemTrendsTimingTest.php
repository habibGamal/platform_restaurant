<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Ai\Agents\ItemRoleClassifier;
use App\Ai\Tools\Menu\ItemTimingTool;
use App\Ai\Tools\Menu\ItemTrendsTool;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Domain\Menu\ItemTiming;
use App\Domain\Menu\ItemTrends;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

class ItemTrendsTimingTest extends TestCase
{
    use RefreshDatabase;

    private MenuFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = MenuFixture::create();
        ItemRoleClassifier::fake(fn () => throw new \RuntimeException('Unavailable in tests'));
        foreach (['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL'] as $key) {
            app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, $key, ItemRole::Main);
        }
        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'LUMO-RICE', ItemRole::Side);
        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'TINY-SPRING', ItemRole::Drink);
        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'GLAZE-DIP', ItemRole::Addon);
        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'DIFF-LINE', ItemRole::Adjustment);
        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'BULK-FLOUR', ItemRole::Raw);
    }

    /** @param array<string, mixed> $extra */
    private function metricsQuery(bool $manager = false, array $extra = []): MetricsQuery
    {
        return MetricsQuery::for($manager ? $this->fixture->managerA : $this->fixture->owner, ReportFilters::fromArray(
            $extra + ['preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO], CarbonImmutable::parse(MenuFixture::TO),
        ));
    }

    public function test_trends_compare_exact_windows_and_exclude_untrusted_and_non_marketable_items(): void
    {
        DB::table('agg_product_daily')->where('business_date', '>=', '2026-09-19')->where('product_ref', 'ZORBA-PLATE')->update(['qty_sold' => DB::raw('qty_sold * 3')]);
        DB::table('agg_product_daily')->where('business_date', '>=', '2026-09-19')->where('product_ref', 'KELTO-WRAP')->update(['qty_sold' => DB::raw('qty_sold / 4')]);
        $result = app(ItemTrends::class)->run($this->metricsQuery(), app(ItemRoles::class)->mapFor($this->fixture->organization->id));
        $this->assertSame('ok', $result['status']);
        $this->assertSame('ZORBA-PLATE', $result['rising'][0]['key']);
        $this->assertSame('KELTO-WRAP', $result['falling'][0]['key']);
        $this->assertEqualsWithDelta(2.0, $result['rising'][0]['change_pct'], 0.00001);
        $this->assertEqualsWithDelta(-0.75, $result['falling'][0]['change_pct'], 0.00001);
        foreach (array_merge($result['rising'], $result['falling']) as $item) {
            $this->assertContains($item['role'], ['main', 'side', 'dessert', 'combo']);
            $this->assertGreaterThanOrEqual(20, $item['previous_qty']);
        }
    }

    public function test_trends_use_authorized_branches_and_require_both_full_windows(): void
    {
        DB::table('agg_product_daily')->where('business_date', '>=', '2026-09-19')->where('branch_id', $this->fixture->branchB->id)->where('product_ref', 'ZORBA-PLATE')->update(['qty_sold' => DB::raw('qty_sold * 100')]);
        $result = app(ItemTrends::class)->run($this->metricsQuery(manager: true), app(ItemRoles::class)->mapFor($this->fixture->organization->id));
        $this->assertNotContains('ZORBA-PLATE', array_column($result['rising'], 'key'));
        $short = app(ItemTrends::class)->run($this->metricsQuery(extra: ['to' => '2026-09-10']), app(ItemRoles::class)->mapFor($this->fixture->organization->id));
        $this->assertSame('insufficient_data', $short['status']);
        $this->assertSame([], $short['rising']);
    }

    public function test_timing_shares_match_completed_scoped_quantities_and_slow_slots_are_open(): void
    {
        $query = $this->metricsQuery(manager: true, extra: ['channel' => 'delivery']);
        $result = app(ItemTiming::class)->run($query, 'ZORBA-PLATE');
        $this->assertSame('ok', $result['status']);
        $this->assertEqualsWithDelta(1, array_sum(array_column($result['weekday'], 'qty_share')), 0.00001);
        $this->assertEqualsWithDelta(1, array_sum(array_column($result['hour'], 'qty_share')), 0.00001);
        $rows = DB::table('wh_orders as o')->join('wh_order_items as i', fn ($join) => $join->on('o.branch_id', '=', 'i.branch_id')->on('o.uuid', '=', 'i.order_uuid'))
            ->where('o.branch_id', $this->fixture->branchA->id)->where('o.status', 'completed')->whereNull('o.deleted_at')->where('o.type', 'delivery')->where('i.product_ref', 'ZORBA-PLATE')
            ->groupBy('o.hour_of_day')->selectRaw('o.hour_of_day, SUM(i.quantity) AS qty')->pluck('qty', 'hour_of_day')->all();
        foreach ($result['hour'] as $hour) {
            $this->assertEqualsWithDelta(($rows[$hour['hour']] ?? 0) / array_sum($rows), $hour['qty_share'], 0.000001);
        }
        $this->assertCount(3, $result['slow_slots']);
        foreach ($result['slow_slots'] as $slot) {
            $this->assertTrue(DB::table('wh_orders')->where('branch_id', $this->fixture->branchA->id)->where('status', 'completed')->where('type', 'delivery')->where('weekday', $slot['weekday'])->where('hour_of_day', $slot['hour'])->exists());
        }
    }

    public function test_timing_excludes_forbidden_roles_unknown_keys_and_sparse_history(): void
    {
        foreach (['TINY-SPRING', 'GLAZE-DIP', 'DIFF-LINE', 'BULK-FLOUR', 'FETA-TOWER', 'missing'] as $key) {
            $result = app(ItemTiming::class)->run($this->metricsQuery(), $key);
            $this->assertSame([], $result['weekday']);
            $this->assertSame([], $result['slow_slots']);
        }
        $result = app(ItemTiming::class)->run($this->metricsQuery(extra: ['from' => '2026-10-16']), 'ZORBA-PLATE');
        $this->assertSame('insufficient_data', $result['status']);
    }

    public function test_tools_validate_filters_and_return_summaries(): void
    {
        $args = ['preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO];
        $trends = new ItemTrendsTool($this->fixture->managerA);
        $timing = new ItemTimingTool($this->fixture->managerA);
        foreach ([$trends, $timing] as $tool) {
            $result = json_decode($tool->handle(new Request($args + ['item_key' => 'ZORBA-PLATE'])), true);
            $this->assertSame('ok', $result['metrics']['status']);
            $this->assertNotEmpty($result['summary']);
            $forbidden = json_decode($tool->handle(new Request($args + ['item_key' => 'ZORBA-PLATE', 'branches' => [$this->fixture->branchB->id]])), true);
            $this->assertSame('forbidden_branch', $forbidden['error']);
        }
        $this->assertSame('validation_error', json_decode($timing->handle(new Request($args)), true)['error']);
        $this->assertSame('unsupported_filter', json_decode($trends->handle(new Request($args + ['channel' => 'delivery'])), true)['error']);
    }

    public function test_timing_excludes_unknown_and_invalid_time_dimensions(): void
    {
        DB::table('wh_orders')->where('branch_id', $this->fixture->branchA->id)->update(['hour_of_day' => null]);
        DB::table('wh_orders')->where('branch_id', $this->fixture->branchB->id)->update(['hour_of_day' => 25]);
        $result = app(ItemTiming::class)->run($this->metricsQuery(), 'ZORBA-PLATE');
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertSame([], $result['hour']);
        $this->assertSame([], $result['slow_slots']);
    }

    public function test_trend_envelope_and_completeness_describe_the_actual_two_windows(): void
    {
        // Selected 5 days still anchors a 56-day analysis, including an earlier incomplete day.
        DB::table('agg_branch_daily')->where('branch_id', $this->fixture->branchA->id)->where('business_date', '2026-08-22')->update(['is_reconciled' => false]);
        $tool = new ItemTrendsTool($this->fixture->managerA);
        $result = json_decode($tool->handle(new Request(['preset' => 'custom', 'from' => '2026-10-12', 'to' => MenuFixture::TO])), true);
        $this->assertSame('2026-09-19', $result['period']['from']);
        $this->assertSame('2026-10-16', $result['period']['to']);
        $this->assertSame('2026-08-22', $result['comparison']['from']);
        $this->assertSame('2026-09-18', $result['comparison']['to']);
        $this->assertStringContainsString('2026-08-22', implode(' ', $result['completeness']['warnings']));
    }

    public function test_trends_exclude_items_with_fewer_than_twenty_units_in_earlier_window(): void
    {
        DB::table('agg_product_daily')->where('business_date', '<', '2026-09-19')->where('product_ref', 'ZORBA-PLATE')->update(['qty_sold' => 0]);
        $result = app(ItemTrends::class)->run($this->metricsQuery(), app(ItemRoles::class)->mapFor($this->fixture->organization->id));
        $this->assertNotContains('ZORBA-PLATE', array_column($result['rising'], 'key'));
    }

    public function test_trends_like_for_like_uses_actual_windows_and_preserves_explicit_branch_selection(): void
    {
        // The selected comparison is disabled, but the trend's earlier 28-day window still applies.
        DB::table('agg_branch_daily')->where('branch_id', $this->fixture->branchB->id)->whereBetween('business_date', ['2026-08-22', '2026-09-18'])->delete();
        $tool = new ItemTrendsTool($this->fixture->owner);
        $args = ['preset' => 'custom', 'from' => '2026-10-12', 'to' => MenuFixture::TO, 'compare' => 'none', 'like_for_like' => true];
        $result = json_decode($tool->handle(new Request($args)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $result['metrics']['status']);
        $this->assertSame([$this->fixture->branchA->id], array_keys($result['branches']));
        $selected = json_decode($tool->handle(new Request($args + ['branches' => [$this->fixture->branchB->id]])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $selected['branches']);
        $this->assertSame('insufficient_data', $selected['metrics']['status']);
    }
}
