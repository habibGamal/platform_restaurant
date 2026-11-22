<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\ItemStatsBuilder;
use App\Enums\BranchStatus;
use App\Models\Menu\ItemPeriodStat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

class ItemStatsBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-07';

    private const BUILD = '01JSTATS000000000000000001';

    private MenuFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = MenuFixture::create();
    }

    private function build(string $periodKey = '2026-09', string $today = self::TODAY, string $buildId = self::BUILD): int
    {
        return app(ItemStatsBuilder::class)->build($this->fixture->organization, $periodKey, CarbonImmutable::parse($today), $buildId);
    }

    private function stat(string $item, int $branchId = 0, string $channel = 'all', string $periodKey = '2026-09'): ItemPeriodStat
    {
        return ItemPeriodStat::withoutGlobalScopes()
            ->where('organization_id', $this->fixture->organization->id)
            ->where('period_key', $periodKey)
            ->where('branch_id', $branchId)
            ->where('channel', $channel)
            ->where('item_key', $item)
            ->firstOrFail();
    }

    /** @return array{qty: float, net_sales: float, cogs: float, gross_profit: float} */
    private function aggSums(string $item, string $from, string $to, ?int $branchId = null): array
    {
        $row = DB::table('agg_product_daily')
            ->where('organization_id', $this->fixture->organization->id)
            ->where('product_ref', $item)
            ->whereBetween('business_date', [$from, $to])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('SUM(qty_sold) AS qty, SUM(net_sales) AS net_sales, SUM(cogs) AS cogs, SUM(gross_profit) AS gross_profit')
            ->first();

        return [
            'qty' => (float) $row->qty,
            'net_sales' => (float) $row->net_sales,
            'cogs' => (float) $row->cogs,
            'gross_profit' => (float) $row->gross_profit,
        ];
    }

    /**
     * Completed order ids of one branch in September containing the item, in id order.
     *
     * @return list<int>
     */
    private function orderIds(string $item, int $branchId): array
    {
        return array_values(DB::table('wh_orders as o')
            ->join('wh_order_items as i', fn ($j) => $j->on('i.branch_id', '=', 'o.branch_id')->on('i.order_uuid', '=', 'o.uuid'))
            ->where('o.branch_id', $branchId)
            ->where('o.status', 'completed')
            ->whereBetween('o.business_date', ['2026-09-01', '2026-09-30'])
            ->where('i.product_ref', $item)
            ->distinct()->orderBy('o.id')->pluck('o.id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_all_channel_totals_match_agg_product_daily(): void
    {
        $this->assertGreaterThan(0, $this->build());

        $expected = $this->aggSums('KELTO-WRAP', '2026-09-01', '2026-09-30');
        $stat = $this->stat('KELTO-WRAP');

        $this->assertEqualsWithDelta($expected['qty'], $stat->qty, 0.001);
        $this->assertEqualsWithDelta($expected['net_sales'], $stat->net_sales, 0.01);
        $this->assertEqualsWithDelta($expected['cogs'], $stat->cogs, 0.01);
        $this->assertEqualsWithDelta($expected['gross_profit'], $stat->gross_profit, 0.01);
        $this->assertSame(30, $stat->days_sold);
        $this->assertSame(60, $stat->open_days, 'two branches open 30 days each');
        $this->assertEqualsWithDelta($expected['qty'] / 60, $stat->velocity, 0.0001);
        $this->assertSame(0.0, $stat->return_qty);
        $this->assertSame(0.0, $stat->waste_cost);
        $this->assertSame(self::BUILD, $stat->build_id);

        $orders = count($this->orderIds('KELTO-WRAP', $this->fixture->branchA->id))
            + count($this->orderIds('KELTO-WRAP', $this->fixture->branchB->id));
        $this->assertSame($orders, $stat->orders);
        $this->assertNotNull($stat->attach_rate);
        $this->assertGreaterThan(0.0, $stat->attach_rate);
        $this->assertLessThanOrEqual(1.0, $stat->attach_rate);
    }

    public function test_velocity_uses_the_open_days_of_the_selling_branches(): void
    {
        // Branch B "closed" for Sep 1-10: no agg_branch_daily days, sales rows left untouched.
        DB::table('agg_branch_daily')->where('branch_id', $this->fixture->branchB->id)
            ->whereBetween('business_date', ['2026-09-01', '2026-09-10'])->delete();

        $this->build();

        $qty = $this->aggSums('KELTO-WRAP', '2026-09-01', '2026-09-30')['qty'];
        $all = $this->stat('KELTO-WRAP');
        $this->assertSame(50, $all->open_days);
        $this->assertEqualsWithDelta($qty / 50, $all->velocity, 0.0001);

        $b = $this->stat('KELTO-WRAP', $this->fixture->branchB->id);
        $this->assertSame(20, $b->open_days);
        $this->assertSame(30, $b->days_sold);

        // Only branches that sold the item add their open days: without B's Lumo Rice sales, just A's 30.
        DB::table('agg_product_daily')->where('branch_id', $this->fixture->branchB->id)->where('product_ref', 'LUMO-RICE')->delete();
        $this->build();
        $this->assertSame(30, $this->stat('LUMO-RICE')->open_days);
    }

    public function test_per_channel_rows_sum_to_the_all_channel_orders(): void
    {
        $this->build();

        foreach ([0, $this->fixture->branchA->id] as $branchId) {
            $all = $this->stat('KELTO-WRAP', $branchId);
            $channels = ItemPeriodStat::withoutGlobalScopes()
                ->where('organization_id', $this->fixture->organization->id)
                ->where('period_key', '2026-09')->where('branch_id', $branchId)
                ->where('item_key', 'KELTO-WRAP')->where('channel', '!=', 'all')
                ->get();

            $this->assertEqualsCanonicalizing(['dine_in', 'takeaway', 'delivery'], $channels->pluck('channel')->all());
            $this->assertSame($all->orders, (int) $channels->sum('orders'));
            $this->assertEqualsWithDelta($all->qty, (float) $channels->sum('qty'), 0.001);
            $this->assertEqualsWithDelta($all->net_sales, (float) $channels->sum('net_sales'), 0.01);

            foreach ($channels as $channel) {
                $this->assertNull($channel->channel_mix);
                $this->assertEqualsWithDelta($channel->net_sales - $channel->cogs, $channel->gross_profit, 0.01);
                $this->assertSame(0.0, $channel->return_qty);
            }

            $this->assertEqualsCanonicalizing(['dine_in', 'takeaway', 'delivery'], array_keys($all->channel_mix ?? []));
            $this->assertEqualsWithDelta(1.0, array_sum($all->channel_mix ?? []), 0.00001);
            $this->assertEqualsWithDelta($channels->firstWhere('channel', 'delivery')->orders / $all->orders, $all->channel_mix['delivery'], 0.0001);
        }
    }

    public function test_daypart_and_weekday_shares_sum_to_one(): void
    {
        $this->build();

        $rows = DB::table('item_period_stats')->where('organization_id', $this->fixture->organization->id)->get(['daypart', 'weekday', 'orders']);
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $daypart = json_decode((string) $row->daypart, true);
            $weekday = json_decode((string) $row->weekday, true);
            $this->assertSame(['morning', 'lunch', 'evening', 'late'], array_keys($daypart));
            $this->assertStringStartsWith('{"0":', (string) $row->weekday, 'weekday is a JSON object keyed "0".."6"');
            $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_keys($weekday));
            $this->assertEqualsWithDelta(1.0, array_sum($daypart), 0.00001);
            $this->assertEqualsWithDelta(1.0, array_sum($weekday), 0.00001);
        }

        // Fixture hours are 12..22: no morning orders; 22h is late.
        $zorba = $this->stat('ZORBA-PLATE');
        $this->assertSame(0.0, $zorba->daypart['morning']);
        $this->assertGreaterThan(0.0, $zorba->daypart['lunch']);
        $this->assertGreaterThan(0.0, $zorba->daypart['evening']);
    }

    public function test_repeat_customer_share_and_null_threshold(): void
    {
        $a = $this->fixture->branchA->id;
        $b = $this->fixture->branchB->id;

        // 12 Zorba orders at branch A with known customers; h0..h3 order again at A (a Kelto order),
        // h4 orders again only at branch B.
        $zorba = array_slice($this->orderIds('ZORBA-PLATE', $a), 0, 12);
        foreach ($zorba as $n => $id) {
            DB::table('wh_orders')->where('id', $id)->update(['customer_hash' => "h{$n}"]);
        }
        $kelto = $this->orderIds('KELTO-WRAP', $a);
        foreach (range(0, 3) as $n) {
            DB::table('wh_orders')->where('id', $kelto[$n])->update(['customer_hash' => "h{$n}"]);
        }
        DB::table('wh_orders')->where('id', $this->orderIds('KELTO-WRAP', $b)[0])->update(['customer_hash' => 'h4']);

        // Only 9 Nadd orders with a known customer: below the threshold.
        foreach (array_slice($this->orderIds('NADD-BOWL', $a), 0, 9) as $n => $id) {
            DB::table('wh_orders')->where('id', $id)->update(['customer_hash' => "n{$n}"]);
        }

        $this->build();

        $this->assertEqualsWithDelta(4 / 12, $this->stat('ZORBA-PLATE', $a)->repeat_customer_share, 0.0001);
        $this->assertEqualsWithDelta(5 / 12, $this->stat('ZORBA-PLATE')->repeat_customer_share, 0.0001, 'h4 repeats across branches');
        $this->assertNull($this->stat('NADD-BOWL')->repeat_customer_share);
        $this->assertNull($this->stat('NADD-BOWL', $a)->repeat_customer_share);
    }

    public function test_mom_velocity_change_against_the_previous_month(): void
    {
        $this->build();

        // August data starts on the 18th: 14 open days per branch.
        $sep = $this->aggSums('KELTO-WRAP', '2026-09-01', '2026-09-30')['qty'] / 60;
        $aug = $this->aggSums('KELTO-WRAP', '2026-08-01', '2026-08-31')['qty'] / 28;
        $this->assertEqualsWithDelta($sep / $aug - 1, $this->stat('KELTO-WRAP')->mom_velocity_change, 0.0001);

        // Feta Tower launched in October: nothing to compare with.
        $this->build('2026-10');
        $this->assertNull($this->stat('FETA-TOWER', 0, 'all', '2026-10')->mom_velocity_change);
        $this->assertNotNull($this->stat('KELTO-WRAP', 0, 'all', '2026-10')->mom_velocity_change);
    }

    public function test_rolling_90d_momentum_compares_the_last_30_days_with_the_60_before(): void
    {
        // Range 2026-07-19 .. 2026-10-16: last 30 days from 09-17, the 60 before from 07-19.
        $this->build('rolling_90d', '2026-10-17');

        $recent = $this->aggSums('KELTO-WRAP', '2026-09-17', '2026-10-16')['qty'] / 60;
        $before = $this->aggSums('KELTO-WRAP', '2026-07-19', '2026-09-16')['qty'] / 60; // data from 08-18: 30 days x 2
        $this->assertEqualsWithDelta($recent / $before - 1, $this->stat('KELTO-WRAP', 0, 'all', 'rolling_90d')->mom_velocity_change, 0.0001);
    }

    public function test_rerunning_the_build_replaces_the_rows(): void
    {
        $first = $this->build();
        $second = $this->build(buildId: '01JSTATS000000000000000002');

        $this->assertSame($first, $second);
        $rows = DB::table('item_period_stats')->where('organization_id', $this->fixture->organization->id)->where('period_key', '2026-09');
        $this->assertSame($second, (clone $rows)->count());
        $this->assertSame(['01JSTATS000000000000000002'], (clone $rows)->distinct()->pluck('build_id')->all());
    }

    public function test_numeric_item_keys_are_kept_as_strings(): void
    {
        DB::table('agg_product_daily')->where('product_ref', 'PEXA-SALAD')->update(['product_ref' => '1001']);
        DB::table('wh_order_items')->where('product_ref', 'PEXA-SALAD')->update(['product_ref' => '1001']);

        $this->build();

        $this->assertGreaterThan(0, $this->stat('1001')->orders);
        $this->assertGreaterThan(0, $this->stat('1001', 0, 'delivery')->orders);
    }

    public function test_branch_scope_zero_and_per_branch_rows(): void
    {
        $this->build();

        $all = $this->stat('KELTO-WRAP');
        $a = $this->stat('KELTO-WRAP', $this->fixture->branchA->id);
        $b = $this->stat('KELTO-WRAP', $this->fixture->branchB->id);

        $this->assertEqualsWithDelta($this->aggSums('KELTO-WRAP', '2026-09-01', '2026-09-30', $this->fixture->branchA->id)['qty'], $a->qty, 0.001);
        $this->assertEqualsWithDelta($all->qty, $a->qty + $b->qty, 0.001);
        $this->assertSame($all->orders, $a->orders + $b->orders);
        $this->assertSame(30, $a->open_days);
        $this->assertEqualsWithDelta($a->qty / 30, $a->velocity, 0.0001);

        // Branch scope 0 still counts a suspended branch's sales, but it gets no rows of its own.
        $this->fixture->branchB->update(['status' => BranchStatus::SUSPENDED]);
        $this->build();

        $this->assertEqualsWithDelta($all->qty, $this->stat('KELTO-WRAP')->qty, 0.001);
        $this->assertSame(0, ItemPeriodStat::withoutGlobalScopes()->where('organization_id', $this->fixture->organization->id)
            ->where('branch_id', $this->fixture->branchB->id)->count());
        $this->assertGreaterThan(0, ItemPeriodStat::withoutGlobalScopes()->where('organization_id', $this->fixture->organization->id)
            ->where('branch_id', $this->fixture->branchA->id)->count());
    }
}
