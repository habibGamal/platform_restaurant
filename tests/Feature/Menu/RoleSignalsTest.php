<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemSignals;
use App\Domain\Menu\RoleSignals;
use App\Domain\Metrics\Completeness;
use App\Domain\Metrics\Period\Period;
use App\Support\Weekday;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

class RoleSignalsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, ItemSignals> */
    private function signals(MenuFixture $f): array
    {
        return app(RoleSignals::class)->compute(
            $f->organization->id,
            CarbonImmutable::parse(MenuFixture::FROM),
            CarbonImmutable::parse(MenuFixture::TO),
        );
    }

    public function test_adjustment_line_is_detected_from_behaviour_not_name(): void
    {
        $f = MenuFixture::create();
        $signals = $this->signals($f);

        $this->assertSame(ItemRole::Adjustment, RoleSignals::hardRole($signals['DIFF-LINE']));
        $this->assertSame(ItemRole::Raw, RoleSignals::hardRole($signals['BULK-FLOUR']));
        $this->assertNull(RoleSignals::hardRole($signals['ZORBA-PLATE']));
        $this->assertNull(RoleSignals::hardRole($signals['QUILL-STEW']));
        $this->assertNull(RoleSignals::hardRole($signals['TINY-SPRING']));
    }

    public function test_always_attached_drink_has_high_attach_rate(): void
    {
        $f = MenuFixture::create();
        $signals = $this->signals($f);

        $this->assertGreaterThanOrEqual(0.9, $signals['TINY-SPRING']->attachRate);
        $this->assertLessThan(0.9, $signals['ZORBA-PLATE']->attachRate);
        $this->assertSame(0, $signals['TINY-SPRING']->ordersAsOnlyLine);
        $this->assertEqualsWithDelta(
            1 - $signals['ZORBA-PLATE']->ordersAsOnlyLine / $signals['ZORBA-PLATE']->ordersWith,
            $signals['ZORBA-PLATE']->attachRate,
            0.0001,
        );
    }

    public function test_signal_fields_describe_the_item(): void
    {
        $f = MenuFixture::create();
        $signals = $this->signals($f);

        $zorba = $signals['ZORBA-PLATE'];
        $this->assertSame('ZORBA-PLATE', $zorba->itemKey);
        $this->assertSame('Zorba Plate', $zorba->name);
        $this->assertSame('Mains', $zorba->categoryName);
        $this->assertSame('manufactured', $zorba->productType);
        $this->assertEqualsWithDelta(200.0, $zorba->avgPrice, 0.001);
        $this->assertEqualsWithDelta(60.0, $zorba->avgUnitCost, 0.001);
        $this->assertSame('raw_material', $signals['BULK-FLOUR']->productType);
        $this->assertEqualsWithDelta(0.0, $signals['QUILL-STEW']->avgUnitCost, 0.001);
        $this->assertEqualsWithDelta(1.0, $signals['DIFF-LINE']->avgPrice, 0.001);

        // median of the manufactured items' average prices is 40 (see MenuFixture docblock)
        $this->assertEqualsWithDelta(200.0 / 40.0, $zorba->priceVsMedian, 0.001);
        $this->assertEqualsWithDelta(3.0, $signals['TINY-SPRING']->avgQtyPerOrder, 0.6);
    }

    public function test_zorba_plate_is_paired_with_lumo_rice_in_about_sixty_percent_of_its_orders(): void
    {
        $f = MenuFixture::create();

        $zorbaOrders = DB::table('wh_order_items')->where('product_ref', 'ZORBA-PLATE')
            ->select('branch_id', 'order_uuid')->get();
        $pairedOrders = DB::table('wh_order_items as a')
            ->join('wh_order_items as b', fn ($j) => $j->on('a.branch_id', '=', 'b.branch_id')->on('a.order_uuid', '=', 'b.order_uuid'))
            ->where('a.product_ref', 'ZORBA-PLATE')->where('b.product_ref', 'LUMO-RICE')
            ->count();

        $this->assertGreaterThan(100, $zorbaOrders->count());
        $this->assertEqualsWithDelta(0.6, $pairedOrders / $zorbaOrders->count(), 0.03);
    }

    public function test_menu_shapes_are_unambiguous(): void
    {
        $f = MenuFixture::create();
        $signals = $this->signals($f);

        $qty = fn (string $k) => $signals[$k]->avgQtyPerOrder * $signals[$k]->ordersWith;
        $margin = fn (string $k) => 1 - $signals[$k]->avgUnitCost / $signals[$k]->avgPrice;

        $this->assertGreaterThan(0.6, $margin('ZORBA-PLATE'));
        $this->assertGreaterThan(0.6, $margin('KELTO-WRAP'));
        $this->assertLessThan(0.3, $margin('BRIMO-BOX'));
        $this->assertLessThan(0.3, $margin('NADD-BOWL'));

        $this->assertGreaterThan(3 * $qty('ZORBA-PLATE'), $qty('KELTO-WRAP'));
        $this->assertGreaterThan(3 * $qty('NADD-BOWL'), $qty('BRIMO-BOX'));
        $this->assertGreaterThan($qty('KELTO-WRAP'), $qty('TINY-SPRING'));
    }

    public function test_feta_tower_is_only_on_sale_for_the_last_five_days(): void
    {
        $f = MenuFixture::create();

        $days = DB::table('wh_orders as o')
            ->join('wh_order_items as i', fn ($j) => $j->on('o.branch_id', '=', 'i.branch_id')->on('o.uuid', '=', 'i.order_uuid'))
            ->where('i.product_ref', 'FETA-TOWER')
            ->distinct()->orderBy('o.business_date')->pluck('o.business_date')->all();

        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'], $days);
    }

    public function test_cancelled_orders_do_not_count_toward_signals(): void
    {
        $f = MenuFixture::create();
        $signals = $this->signals($f);

        $this->assertGreaterThan(0, DB::table('wh_orders')->where('status', 'cancelled')->count());
        // cancelled orders hold a lone DIFF-LINE; if they leaked in, the attach rate would drop below 1
        $this->assertSame(0, $signals['DIFF-LINE']->ordersAsOnlyLine);
        $this->assertEqualsWithDelta(1.0, $signals['DIFF-LINE']->attachRate, 0.0001);
    }

    public function test_fixture_volume_weekday_and_aggregates_are_consistent(): void
    {
        $f = MenuFixture::create();

        $this->assertGreaterThan(200, DB::table('wh_orders')->where('status', 'completed')->count());

        foreach (DB::table('wh_orders')->select('business_date', 'weekday', 'hour_of_day')->distinct()->get() as $row) {
            $this->assertSame(Weekday::of(CarbonImmutable::parse($row->business_date)), (int) $row->weekday);
            $this->assertNotNull($row->hour_of_day);
        }

        // agg_product_daily agrees with agg_branch_daily and with the raw orders
        $this->assertEqualsWithDelta(
            (float) DB::table('agg_branch_daily')->sum('net_sales'),
            (float) DB::table('agg_product_daily')->sum('net_sales'),
            0.01,
        );
        $this->assertEqualsWithDelta(
            (float) DB::table('wh_orders')->where('status', 'completed')->sum('total'),
            (float) DB::table('agg_branch_daily')->sum('net_sales'),
            0.01,
        );
        $this->assertEqualsWithDelta(
            (float) DB::table('agg_branch_daily')->sum('cogs'),
            (float) DB::table('agg_product_daily')->sum('cogs'),
            0.01,
        );
        $this->assertSame(
            (int) DB::table('wh_orders')->where('status', 'completed')->count(),
            (int) DB::table('agg_branch_daily')->sum('orders_count'),
        );
        $this->assertEqualsWithDelta(
            (float) DB::table('wh_order_items')
                ->whereIn('order_uuid', DB::table('wh_orders')->where('status', 'completed')->select('uuid'))
                ->sum('quantity'),
            (float) DB::table('agg_product_daily')->sum('qty_sold'),
            0.01,
        );
        $this->assertSame(120, (int) DB::table('agg_branch_daily')->count());
    }

    public function test_every_day_of_the_period_counts_as_complete_for_both_branches(): void
    {
        $f = MenuFixture::create();
        $period = new Period(CarbonImmutable::parse(MenuFixture::FROM), CarbonImmutable::parse(MenuFixture::TO));

        $this->assertSame(60, $period->days());
        $this->assertTrue(Completeness::isBranchComplete($f->branchA->id, $period));
        $this->assertTrue(Completeness::isBranchComplete($f->branchB->id, $period));
        $this->assertSame(
            120,
            (int) DB::table('agg_branch_daily')->where('is_day_closed', true)->where('is_reconciled', true)->count(),
        );
    }

    public function test_fixture_exposes_users_with_branch_scoping(): void
    {
        $f = MenuFixture::create();

        $this->assertTrue($f->owner->hasRole('org_owner'));
        $this->assertTrue($f->managerA->hasRole('branch_manager'));
        $this->assertSame([$f->branchA->id], $f->managerA->branches()->pluck('branches.id')->all());
    }
}
