<?php

namespace Tests\Fixtures;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\Warehouse\DimCategory;
use App\Models\Warehouse\DimProduct;
use App\Support\Weekday;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Made-up menu shared by the menu-engineering, basket, combo, trend and forecast tests.
 *
 * 60 days (2026-08-18 .. 2026-10-16) of completed orders over two branches (A busy, B quieter),
 * plus the matching agg_product_daily / agg_branch_daily rows. Every agg_branch_daily row is
 * closed AND reconciled, so Completeness reports both branches complete for the whole period.
 * Fully deterministic: volumes come from fixed per-weekday factors and per-item counters.
 * No tax, service, discount, returns or expenses, so net_sales == gross_sales == Σ item totals.
 *
 * Item key = product_ref (the upper-kebab-cased name) and is identical in both branches.
 *
 * ref          | name        | category    | type         | role intent                    | price | cost | units/day (both branches)
 * -------------|-------------|-------------|--------------|--------------------------------|-------|------|---------------------------
 * ZORBA-PLATE  | Zorba Plate | Mains       | manufactured | main: high margin, low volume  | 200   | 60   | 5.3   (70% margin)
 * KELTO-WRAP   | Kelto Wrap  | Mains       | manufactured | main: high margin, high volume | 100   | 28   | 22.4  (72% margin)
 * BRIMO-BOX    | Brimo Box   | Mains       | manufactured | main: low margin, high volume  | 60    | 48   | 22.4  (20% margin)
 * NADD-BOWL    | Nadd Bowl   | Mains       | manufactured | main: low margin, low volume   | 50    | 40   | 4.2   (20% margin)
 * FETA-TOWER   | Feta Tower  | Mains       | manufactured | main: new, on sale 2026-10-12  | 120   | 50   | ~4.2 on its 5 days (21 total)
 *              |             |             |              | .. 2026-10-16 only             |       |      |
 * QUILL-STEW   | Quill Stew  | Mains       | manufactured | main: cost unknown (0)         | 90    | 0    | 4.2
 * LUMO-RICE    | Lumo Rice   | Sides       | manufactured | side                           | 25    | 8    | 3.2   (Zorba-only)
 * PEXA-SALAD   | Pexa Salad  | Sides       | manufactured | side                           | 30    | 10   | 14.7  (broad companion)
 * VIRA-SODA    | Vira Soda   | Drinks      | manufactured | drink, also sold alone         | 15    | 4    | 22.2
 * TINY-SPRING  | Tiny Spring | Drinks      | manufactured | drink: very high volume, never | 5     | 1    | 88.8  (highest quantity)
 *              |             |             |              | alone (attach rate 1.0)        |       |      |
 * GLAZE-DIP    | Glaze Dip   | Extras      | manufactured | add-on                         | 8     | 2    | 7.5
 * DIFF-LINE    | Diff Line   | Adjustments | manufactured | adjustment: always attached    | 1     | 0    | 4.1
 * BULK-FLOUR   | Bulk Flour  | Raw         | raw_material | raw                            | 30    | 20   | 1.1   (44 of 60 days)
 *
 * Totals: 3845 completed orders, 8581 order lines (12 of them in cancelled orders) over the 60 days.
 * Period sales: Kelto 134,300; Brimo 80,580; Zorba 63,800; Pexa 26,490; Tiny Spring 26,640; Quill 22,500;
 * Vira 19,950; Nadd 12,500; Lumo 4,800; Glaze 3,616; Feta 2,520; Bulk Flour 1,890; Diff 248.
 *
 * Median price of the manufactured items is 40, so Zorba priceVsMedian = 5.0 and Diff Line's
 * adjustment price ceiling is max(1, 0.02 * 40) = 1.
 *
 * Other facts the tests rely on:
 *  - Zorba Plate shares an order with Lumo Rice in 60% of its orders (counter % 5 < 3); ~20% of
 *    Zorba orders contain only Zorba, so its attach rate is ~0.8 (< 0.9).
 *  - Lumo Rice is only ever attached to Zorba Plate; Pexa Salad is a broad companion of Kelto, Brimo,
 *    Nadd, Quill, Feta and Zorba. So among main -> side/drink pairs, Zorba -> Lumo has lift ~12
 *    (192 orders) against a runner-up of ~2.3 (Feta -> Pexa), and Kelto/Brimo -> Tiny Spring have
 *    the most orders (807 each, lift ~1.2). Menu engineering over the four eligible mains (Feta has
 *    5 days on sale, Quill cost 0): Zorba hidden gem, Kelto star, Brimo workhorse, Nadd weak;
 *    unit margins 140 / 72 / 12 / 10 against an average of ~49.
 *  - Weekday factors (0 = Saturday .. 6 = Friday, percent of base): 110, 90, 90, 95, 100, 115, 130.
 *  - Each branch has a cancelled order every 10th day holding a lone DIFF-LINE (must be ignored).
 *  - Order hours peak around 13-14h and 20-21h; order types cycle dine_in / takeaway / delivery.
 *  - wh_inventory_days and wh_day_summaries exist for every branch-day so a metrics:rebuild would
 *    reproduce the same closed/reconciled flags.
 */
class MenuFixture
{
    public const FROM = '2026-08-18';

    public const TO = '2026-10-16';

    public const FETA_FIRST_DAY = '2026-10-12';

    /** @var array<string, array{0: string, 1: string, 2: string, 3: float, 4: float}> ref => [name, category slug, type, price, cost] */
    private const PRODUCTS = [
        'ZORBA-PLATE' => ['Zorba Plate', 'mains', 'manufactured', 200.00, 60.00],
        'KELTO-WRAP' => ['Kelto Wrap', 'mains', 'manufactured', 100.00, 28.00],
        'BRIMO-BOX' => ['Brimo Box', 'mains', 'manufactured', 60.00, 48.00],
        'NADD-BOWL' => ['Nadd Bowl', 'mains', 'manufactured', 50.00, 40.00],
        'FETA-TOWER' => ['Feta Tower', 'mains', 'manufactured', 120.00, 50.00],
        'QUILL-STEW' => ['Quill Stew', 'mains', 'manufactured', 90.00, 0.00],
        'LUMO-RICE' => ['Lumo Rice', 'sides', 'manufactured', 25.00, 8.00],
        'PEXA-SALAD' => ['Pexa Salad', 'sides', 'manufactured', 30.00, 10.00],
        'VIRA-SODA' => ['Vira Soda', 'drinks', 'manufactured', 15.00, 4.00],
        'TINY-SPRING' => ['Tiny Spring', 'drinks', 'manufactured', 5.00, 1.00],
        'GLAZE-DIP' => ['Glaze Dip', 'extras', 'manufactured', 8.00, 2.00],
        'DIFF-LINE' => ['Diff Line', 'adjustments', 'manufactured', 1.00, 0.00],
        'BULK-FLOUR' => ['Bulk Flour', 'raw', 'raw_material', 30.00, 20.00],
    ];

    /** @var array<string, string> */
    private const CATEGORIES = [
        'mains' => 'Mains',
        'sides' => 'Sides',
        'drinks' => 'Drinks',
        'extras' => 'Extras',
        'adjustments' => 'Adjustments',
        'raw' => 'Raw',
    ];

    /** Base orders per day [branch A, branch B] for each order driver (before the weekday factor). */
    private const DAILY_BASE = [
        'ZORBA-PLATE' => [4, 2],
        'KELTO-WRAP' => [14, 8],
        'BRIMO-BOX' => [14, 8],
        'NADD-BOWL' => [3, 2],
        'FETA-TOWER' => [3, 2],
        'QUILL-STEW' => [3, 2],
        'VIRA-SODA' => [4, 2], // drink-only orders
    ];

    /** Percent of base volume per weekday index 0 (Saturday) .. 6 (Friday). */
    private const WEEKDAY_FACTOR = [110, 90, 90, 95, 100, 115, 130];

    private const HOURS = [12, 13, 14, 13, 20, 14, 19, 21, 15, 20, 13, 18, 21, 22, 12, 14];

    private const TYPES = ['dine_in', 'takeaway', 'delivery'];

    public Organization $organization;

    public Restaurant $restaurant;

    public Branch $branchA;

    public Branch $branchB;

    public User $owner;

    public User $managerA;

    /** @var array<int, array<string, string>> branch id => ref => product uuid */
    private array $productUuids = [];

    public static function create(): self
    {
        $fixture = new self;

        (new RolesAndPermissionsSeeder)->run();

        $fixture->organization = Organization::create([
            'name' => 'Org Menu',
            'slug' => 'org-menu-'.Str::random(6),
            'plan' => 'pro',
            'ai_enabled' => true,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $fixture->restaurant = Restaurant::create([
            'organization_id' => $fixture->organization->id,
            'name' => 'Restaurant Menu',
        ]);

        foreach (['A', 'B'] as $letter) {
            $fixture->{'branch'.$letter} = Branch::create([
                'organization_id' => $fixture->organization->id,
                'restaurant_id' => $fixture->restaurant->id,
                'name' => 'Branch '.$letter,
                'timezone' => 'Africa/Cairo',
                'opened_on' => '2026-08-01',
                'status' => BranchStatus::ACTIVE,
            ]);
        }

        $fixture->owner = User::create([
            'organization_id' => $fixture->organization->id,
            'name' => 'Owner',
            'email' => 'owner-'.Str::random(5).'@example.com',
            'password' => bcrypt('password'),
        ]);
        $fixture->owner->assignRole('org_owner');

        $fixture->managerA = User::create([
            'organization_id' => $fixture->organization->id,
            'name' => 'Manager A',
            'email' => 'managera-'.Str::random(5).'@example.com',
            'password' => bcrypt('password'),
        ]);
        $fixture->managerA->assignRole('branch_manager');
        $fixture->managerA->branches()->attach($fixture->branchA->id);

        $fixture->seedDimensions();
        $fixture->seedSales();

        return $fixture;
    }

    protected function seedDimensions(): void
    {
        $orgId = $this->organization->id;

        foreach (self::PRODUCTS as $ref => [$name]) {
            CatalogItem::create([
                'organization_id' => $orgId,
                'product_ref' => $ref,
                'canonical_name' => $name,
            ]);
        }

        foreach ([$this->branchA, $this->branchB] as $branch) {
            foreach (self::CATEGORIES as $slug => $name) {
                DimCategory::create([
                    'organization_id' => $orgId,
                    'branch_id' => $branch->id,
                    'uuid' => "cat-{$slug}-{$branch->id}",
                    'name' => $name,
                    'source_version' => 1,
                ]);
            }

            $n = 0;
            foreach (self::PRODUCTS as $ref => [$name, $category, $type, $price, $cost]) {
                $n++;
                $uuid = "prod-{$branch->id}-{$n}";
                $this->productUuids[$branch->id][$ref] = $uuid;
                DimProduct::create([
                    'organization_id' => $orgId,
                    'branch_id' => $branch->id,
                    'uuid' => $uuid,
                    'source_version' => 1,
                    'name' => $name,
                    'product_ref' => $ref,
                    'category_uuid' => "cat-{$category}-{$branch->id}",
                    'type' => $type,
                    'unit' => 'pcs',
                    'price' => $price,
                    'cost' => $cost,
                ]);
            }
        }
    }

    protected function seedSales(): void
    {
        $orgId = $this->organization->id;
        $orders = [];
        $items = [];
        $branchDaily = [];
        $productDaily = [];
        $inventoryDays = [];
        $daySummaries = [];
        $counters = [];
        $orderSeq = 0;
        $itemSeq = 0;

        $period = CarbonImmutable::parse(self::FROM);
        $dayCount = (int) CarbonImmutable::parse(self::FROM)->diffInDays(CarbonImmutable::parse(self::TO)) + 1;

        foreach ([$this->branchA, $this->branchB] as $branchIdx => $branch) {
            $bid = $branch->id;

            for ($d = 0; $d < $dayCount; $d++) {
                $day = $period->addDays($d);
                $date = $day->toDateString();
                $weekday = Weekday::of($day);
                $factor = self::WEEKDAY_FACTOR[$weekday];

                $queue = $this->dayQueue($branchIdx, $date, $factor);

                $agg = [
                    'orders' => 0, 'gross' => 0.0, 'cogs' => 0.0,
                    'cancelled' => 0, 'cancelledValue' => 0.0,
                    'byType' => [], 'cash' => 0.0, 'card' => 0.0,
                ];

                foreach ($queue as $pos => $driver) {
                    $counters[$bid][$driver] = ($counters[$bid][$driver] ?? -1) + 1;
                    $lines = $this->linesFor($driver, $counters[$bid][$driver], $date);

                    $orderSeq++;
                    $orderUuid = "ord-{$bid}-{$orderSeq}";
                    $hour = self::HOURS[$pos % count(self::HOURS)];
                    $minute = ($pos * 7) % 60;
                    $type = self::TYPES[$orderSeq % 3];

                    $subTotal = 0.0;
                    $cogs = 0.0;
                    foreach ($lines as [$ref, $qty]) {
                        [, , , $price, $cost] = self::PRODUCTS[$ref];
                        $itemSeq++;
                        $total = $qty * $price;
                        $subTotal += $total;
                        $cogs += $qty * $cost;
                        $items[] = [
                            'organization_id' => $orgId,
                            'branch_id' => $bid,
                            'order_uuid' => $orderUuid,
                            'uuid' => "oi-{$bid}-{$itemSeq}",
                            'product_uuid' => $this->productUuids[$bid][$ref],
                            'product_ref' => $ref,
                            'quantity' => $qty,
                            'price' => $price,
                            'unit_cost' => $cost,
                            'item_discount' => 0,
                            'total' => $total,
                        ];

                        $k = "{$bid}|{$date}|{$ref}";
                        $productDaily[$k] ??= ['ref' => $ref, 'bid' => $bid, 'date' => $date, 'qty' => 0, 'gross' => 0.0, 'cogs' => 0.0];
                        $productDaily[$k]['qty'] += $qty;
                        $productDaily[$k]['gross'] += $total;
                        $productDaily[$k]['cogs'] += $qty * $cost;
                    }

                    $orders[] = [
                        'organization_id' => $orgId,
                        'branch_id' => $bid,
                        'uuid' => $orderUuid,
                        'source_version' => 1,
                        'business_date' => $date,
                        'type' => $type,
                        'status' => 'completed',
                        'created_at_local' => sprintf('%s %02d:%02d:00', $date, $hour, $minute),
                        'hour_of_day' => $hour,
                        'weekday' => $weekday,
                        'sub_total' => $subTotal,
                        'discount' => 0,
                        'tax' => 0,
                        'service' => 0,
                        'total' => $subTotal,
                        'items_discount' => 0,
                        'cogs' => $cogs,
                    ];

                    $agg['orders']++;
                    $agg['gross'] += $subTotal;
                    $agg['cogs'] += $cogs;
                    $agg['byType'][$type]['orders'] = ($agg['byType'][$type]['orders'] ?? 0) + 1;
                    $agg['byType'][$type]['net'] = ($agg['byType'][$type]['net'] ?? 0.0) + $subTotal;
                    $agg[$orderSeq % 2 === 0 ? 'cash' : 'card'] += $subTotal;
                }

                // Every 10th day: one cancelled order with a lone adjustment line (must never count).
                if ($d % 10 === 0) {
                    $orderSeq++;
                    $itemSeq++;
                    $orderUuid = "ord-{$bid}-{$orderSeq}";
                    $orders[] = [
                        'organization_id' => $orgId,
                        'branch_id' => $bid,
                        'uuid' => $orderUuid,
                        'source_version' => 1,
                        'business_date' => $date,
                        'type' => 'dine_in',
                        'status' => 'cancelled',
                        'created_at_local' => "{$date} 16:00:00",
                        'hour_of_day' => 16,
                        'weekday' => $weekday,
                        'sub_total' => 1,
                        'discount' => 0,
                        'tax' => 0,
                        'service' => 0,
                        'total' => 1,
                        'items_discount' => 0,
                        'cogs' => 0,
                    ];
                    $items[] = [
                        'organization_id' => $orgId,
                        'branch_id' => $bid,
                        'order_uuid' => $orderUuid,
                        'uuid' => "oi-{$bid}-{$itemSeq}",
                        'product_uuid' => $this->productUuids[$bid]['DIFF-LINE'],
                        'product_ref' => 'DIFF-LINE',
                        'quantity' => 1,
                        'price' => 1,
                        'unit_cost' => 0,
                        'item_discount' => 0,
                        'total' => 1,
                    ];
                    $agg['cancelled']++;
                    $agg['cancelledValue'] += 1.0;
                }

                $byType = [];
                foreach ($agg['byType'] as $type => $v) {
                    $byType[$type] = ['orders' => $v['orders'], 'net_sales' => number_format($v['net'], 2, '.', '')];
                }
                $gross = $agg['gross'];
                $branchDaily[] = [
                    'organization_id' => $orgId,
                    'restaurant_id' => $this->restaurant->id,
                    'branch_id' => $bid,
                    'business_date' => $date,
                    'weekday' => $weekday,
                    'orders_count' => $agg['orders'],
                    'cancelled_count' => $agg['cancelled'],
                    'cancelled_value' => $agg['cancelledValue'],
                    'gross_sales' => $gross,
                    'discounts' => 0,
                    'tax' => 0,
                    'service' => 0,
                    'net_sales' => $gross,
                    'returns_amount' => 0,
                    'net_sales_after_returns' => $gross,
                    'cogs' => $agg['cogs'],
                    'returned_cogs_recovered' => 0,
                    'gross_profit' => $gross - $agg['cogs'],
                    'expenses_total' => 0,
                    'labor_cost' => 0,
                    'rent_cost' => 0,
                    'other_expenses' => 0,
                    'waste_cost' => 0,
                    'inventory_variance_cost' => 0,
                    'purchases_total' => 0,
                    'purchase_returns_total' => 0,
                    'net_profit' => $gross - $agg['cogs'],
                    'sales_by_type' => json_encode((object) $byType),
                    'sales_by_method' => json_encode([
                        'cash' => number_format($agg['cash'], 2, '.', ''),
                        'card' => number_format($agg['card'], 2, '.', ''),
                    ]),
                    'shifts_count' => 0,
                    'cash_deficit_total' => 0,
                    'is_day_closed' => true,
                    'is_reconciled' => true,
                    'computed_at' => now(),
                ];

                $inventoryDays[] = [
                    'organization_id' => $orgId,
                    'branch_id' => $bid,
                    'uuid' => "inv-day-{$bid}-{$date}",
                    'source_version' => 1,
                    'business_date' => $date,
                ];
                $daySummaries[] = [
                    'organization_id' => $orgId,
                    'branch_id' => $bid,
                    'uuid' => "day-sum-{$bid}-{$date}",
                    'source_version' => 1,
                    'business_date' => $date,
                    'orders_count' => $agg['orders'] + $agg['cancelled'],
                    'completed_count' => $agg['orders'],
                    'net_total' => $gross,
                    'discount_total' => 0,
                    'cogs_total' => $agg['cogs'],
                    'expenses_total' => 0,
                    'waste_total' => 0,
                ];
            }
        }

        $productRows = [];
        foreach ($productDaily as $p) {
            [, $category] = self::PRODUCTS[$p['ref']];
            $productRows[] = [
                'organization_id' => $orgId,
                'branch_id' => $p['bid'],
                'business_date' => $p['date'],
                'product_uuid' => $this->productUuids[$p['bid']][$p['ref']],
                'category_uuid' => "cat-{$category}-{$p['bid']}",
                'product_ref' => $p['ref'],
                'qty_sold' => $p['qty'],
                'gross_sales' => $p['gross'],
                'item_discounts' => 0,
                'net_sales' => $p['gross'],
                'cogs' => $p['cogs'],
                'gross_profit' => $p['gross'] - $p['cogs'],
                'returns_qty' => 0,
                'waste_qty' => 0,
                'waste_cost' => 0,
            ];
        }

        $this->bulkInsert('wh_orders', $orders);
        $this->bulkInsert('wh_order_items', $items);
        $this->bulkInsert('wh_inventory_days', $inventoryDays);
        $this->bulkInsert('wh_day_summaries', $daySummaries);
        $this->bulkInsert('agg_branch_daily', $branchDaily);
        $this->bulkInsert('agg_product_daily', $productRows);
    }

    /**
     * Ordered list of order drivers (the item an order is built around) for one branch-day,
     * interleaved so every driver is spread across the day's hours.
     *
     * @return list<string>
     */
    private function dayQueue(int $branchIdx, string $date, int $factor): array
    {
        $entries = [];
        $rank = 0;
        foreach (self::DAILY_BASE as $driver => $base) {
            if ($driver === 'FETA-TOWER' && $date < self::FETA_FIRST_DAY) {
                continue;
            }
            $count = intdiv($base[$branchIdx] * $factor, 100);
            for ($k = 0; $k < $count; $k++) {
                $entries[] = [($k + 0.5) / $count, $rank, $driver];
            }
            $rank++;
        }

        usort($entries, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $e): string => $e[2], $entries);
    }

    /**
     * Lines of the $i-th order built around $driver: [ref, quantity] pairs, the driver first.
     * Attachment rules key off $i (a per-branch, per-driver counter) so shares are exact over time.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function linesFor(string $driver, int $i, string $date): array
    {
        if ($driver === 'VIRA-SODA') {
            return [['VIRA-SODA', $i % 3 === 0 ? 2 : 1]];
        }

        $lines = [[$driver, 1]];
        $add = function (string $ref, int $qty = 1) use (&$lines): void {
            $lines[] = [$ref, $qty];
        };

        switch ($driver) {
            case 'ZORBA-PLATE':
                if ($i % 5 < 3) {
                    $add('LUMO-RICE');
                    if ($i % 2 === 0) {
                        $add('TINY-SPRING', 2);
                    }
                } elseif ($i % 10 === 3) {
                    $add('PEXA-SALAD');
                } elseif ($i % 10 === 8) {
                    $add('GLAZE-DIP');
                }
                break;
            case 'KELTO-WRAP':
                if ($i % 5 < 3) {
                    $add('TINY-SPRING', 3);
                }
                if ($i % 4 === 1) {
                    $add('PEXA-SALAD');
                }
                if ($i % 3 === 0) {
                    $add('VIRA-SODA');
                }
                if ($i % 4 === 0) {
                    $add('GLAZE-DIP');
                }
                if ($i % 10 === 0) {
                    $add('DIFF-LINE');
                }
                break;
            case 'BRIMO-BOX':
                if ($i % 5 < 3) {
                    $add('TINY-SPRING', 3);
                }
                if ($i % 4 === 2) {
                    $add('PEXA-SALAD');
                }
                if ($i % 3 === 1) {
                    $add('VIRA-SODA');
                }
                if ($i % 12 === 5) {
                    $add('DIFF-LINE');
                }
                break;
            case 'NADD-BOWL':
                if ($i % 3 === 0) {
                    $add('PEXA-SALAD');
                }
                if ($i % 4 === 0) {
                    $add('BULK-FLOUR');
                }
                if ($i % 2 === 0) {
                    $add('TINY-SPRING');
                }
                break;
            case 'FETA-TOWER':
                if ($i % 2 === 0) {
                    $add('PEXA-SALAD');
                }
                if ($i % 3 === 0) {
                    $add('VIRA-SODA');
                }
                $add('TINY-SPRING', 2);
                break;
            case 'QUILL-STEW':
                if ($i % 3 === 0) {
                    $add('GLAZE-DIP');
                } elseif ($i % 3 === 1) {
                    $add('PEXA-SALAD');
                }
                if ($i % 2 === 1) {
                    $add('TINY-SPRING');
                }
                break;
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $rows */
    private function bulkInsert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
