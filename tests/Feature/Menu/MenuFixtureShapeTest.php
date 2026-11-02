<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

/**
 * Pins the outcomes later menu tasks (menu engineering, basket pairs, combos) rely on, computed
 * straight from the database over the full 60 days. If a fixture retune breaks one of these, the
 * downstream task tests would silently lose their unambiguous expectations.
 */
class MenuFixtureShapeTest extends TestCase
{
    use RefreshDatabase;

    private const MAINS = ['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL', 'FETA-TOWER', 'QUILL-STEW'];

    private const PARTNERS = ['LUMO-RICE', 'PEXA-SALAD', 'VIRA-SODA', 'TINY-SPRING'];

    public function test_menu_engineering_quadrants_are_unambiguous(): void
    {
        MenuFixture::create();

        $rows = DB::table('agg_product_daily')
            ->whereIn('product_ref', self::MAINS)
            ->groupBy('product_ref')
            ->selectRaw('product_ref, SUM(qty_sold) AS qty, SUM(net_sales) AS net, SUM(cogs) AS cogs, COUNT(DISTINCT business_date) AS days')
            ->get();

        // eligible = qty > 0, at least 14 days on sale, cogs > 0 (drops Feta Tower and Quill Stew)
        $eligible = $rows->filter(fn ($r) => (float) $r->qty > 0 && (int) $r->days >= 14 && (float) $r->cogs > 0)
            ->keyBy('product_ref');
        $this->assertEqualsCanonicalizing(['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL'], $eligible->keys()->all());

        $n = $eligible->count();
        $totalQty = (float) $eligible->sum('qty');
        $avgCm = ((float) $eligible->sum('net') - (float) $eligible->sum('cogs')) / $totalQty;

        $quad = [];
        foreach ($eligible as $ref => $r) {
            $unitCm = ((float) $r->net - (float) $r->cogs) / (float) $r->qty;
            $quad[$ref] = [
                'popular' => (float) $r->qty / $totalQty >= 0.70 * (1 / $n),
                'profitable' => $unitCm >= $avgCm,
                'unitCm' => $unitCm,
            ];
        }

        $this->assertSame([true, false], [$quad['ZORBA-PLATE']['profitable'], $quad['ZORBA-PLATE']['popular']]);
        $this->assertSame([true, true], [$quad['KELTO-WRAP']['profitable'], $quad['KELTO-WRAP']['popular']]);
        $this->assertSame([false, true], [$quad['BRIMO-BOX']['profitable'], $quad['BRIMO-BOX']['popular']]);
        $this->assertSame([false, false], [$quad['NADD-BOWL']['profitable'], $quad['NADD-BOWL']['popular']]);

        // comfortable margins, so a small volume change cannot flip a quadrant
        $this->assertGreaterThanOrEqual(1.3 * $avgCm, $quad['KELTO-WRAP']['unitCm']);
        $this->assertLessThanOrEqual(0.5 * $avgCm, $quad['BRIMO-BOX']['unitCm']);
    }

    public function test_zorba_to_lumo_is_the_clear_top_basket_pair_by_lift(): void
    {
        MenuFixture::create();

        $orders = [];
        $lines = DB::table('wh_order_items as i')
            ->join('wh_orders as o', fn ($j) => $j->on('o.branch_id', '=', 'i.branch_id')->on('o.uuid', '=', 'i.order_uuid'))
            ->where('o.status', 'completed')
            ->get(['i.branch_id', 'i.order_uuid', 'i.product_ref']);
        foreach ($lines as $l) {
            $orders["{$l->branch_id}|{$l->order_uuid}"][$l->product_ref] = true;
        }

        $total = count($orders);
        $with = [];
        $together = [];
        foreach ($orders as $set) {
            foreach (array_keys($set) as $ref) {
                $with[$ref] = ($with[$ref] ?? 0) + 1;
            }
            foreach (self::MAINS as $main) {
                if (! isset($set[$main])) {
                    continue;
                }
                foreach (self::PARTNERS as $partner) {
                    if (isset($set[$partner])) {
                        $together["$main>$partner"] = ($together["$main>$partner"] ?? 0) + 1;
                    }
                }
            }
        }

        $minSupport = max(5, 0.002 * $total);
        $pairs = [];
        foreach ($together as $key => $count) {
            if ($count < $minSupport) {
                continue;
            }
            [$main, $partner] = explode('>', $key);
            $pairs[] = [
                'key' => $key,
                'together' => $count,
                'lift' => ($count / $total) / (($with[$main] / $total) * ($with[$partner] / $total)),
            ];
        }
        usort($pairs, fn (array $a, array $b): int => [$b['lift'], $b['together']] <=> [$a['lift'], $a['together']]);

        $this->assertGreaterThan(1, count($pairs));
        $this->assertSame('ZORBA-PLATE>LUMO-RICE', $pairs[0]['key']);
        $this->assertGreaterThanOrEqual(1.5 * $pairs[1]['lift'], $pairs[0]['lift']);
    }
}
