<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\AffinityMiner;
use App\Domain\Menu\Intelligence\ItemStatsBuilder;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Menu\ItemAffinity;
use App\Models\Menu\ItemPeriodStat;
use App\Models\Organization;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * June 2026 baskets (290 counted orders, 260 with 2+ items after dropping ADJ/RAW):
 *
 *   120 dine_in  ALPHA BRAVO CHARLIE ADJ RAW   totals cycle 100 / 130 / 160
 *    60 takeaway ALPHA BRAVO                   total 50 (first 10 repeat the ALPHA line)
 *    40 takeaway CHARLIE DELTA                 total 40
 *    30 delivery ALPHA                         total 60
 *    10 delivery ALPHA DELTA                   total 70
 *    10 takeaway ALPHA ECHO                    total 20
 *    20 takeaway ALPHA FOXTROT                 total 20
 *
 * plus cancelled, soft-deleted and July triples that must be ignored.
 * Members: ALPHA 250, BRAVO 180, CHARLIE 160, DELTA 50, ECHO 10, FOXTROT 20.
 */
class AffinityMinerTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-07';

    private const PERIOD = '2026-06';

    private const BUILD = '01JAFFIN00000000000000001';

    private const TOTAL = 290;

    private Organization $organization;

    /** @var list<int> */
    private array $branches = [];

    private int $seq = 0;

    /** @var list<array<string, mixed>> */
    private array $orders = [];

    /** @var list<array<string, mixed>> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Org Affinity',
            'slug' => 'org-affinity-'.Str::random(6),
            'plan' => 'pro',
            'ai_enabled' => true,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);
        $restaurant = Restaurant::create(['organization_id' => $this->organization->id, 'name' => 'R']);
        foreach (['A', 'B'] as $letter) {
            $this->branches[] = Branch::create([
                'organization_id' => $this->organization->id,
                'restaurant_id' => $restaurant->id,
                'name' => 'Branch '.$letter,
                'timezone' => 'Africa/Cairo',
                'opened_on' => '2026-01-01',
                'status' => BranchStatus::ACTIVE,
            ])->id;
        }

        $roles = app(ItemRoles::class);
        foreach ([
            'ALPHA' => ItemRole::Main,
            'BRAVO' => ItemRole::Side,
            'CHARLIE' => ItemRole::Drink,
            'DELTA' => ItemRole::Dessert,
            'ECHO' => ItemRole::Addon,
            'ADJ' => ItemRole::Adjustment,
            'RAW' => ItemRole::Raw,
        ] as $key => $role) {
            $roles->setOwnerRole($this->organization->id, $key, $role); // FOXTROT stays unlabelled
        }

        $this->seedOrders();
    }

    private function seedOrders(): void
    {
        for ($n = 0; $n < 120; $n++) {
            $this->order('dine_in', ['ALPHA', 'BRAVO', 'CHARLIE', 'ADJ', 'RAW'], 100 + ($n % 3) * 30);
        }
        for ($n = 0; $n < 60; $n++) {
            $this->order('takeaway', $n < 10 ? ['ALPHA', 'ALPHA', 'BRAVO'] : ['ALPHA', 'BRAVO'], 50);
        }
        $this->repeat(40, 'takeaway', ['CHARLIE', 'DELTA'], 40);
        $this->repeat(30, 'delivery', ['ALPHA'], 60);
        $this->repeat(10, 'delivery', ['ALPHA', 'DELTA'], 70);
        $this->repeat(10, 'takeaway', ['ALPHA', 'ECHO'], 20);
        $this->repeat(20, 'takeaway', ['ALPHA', 'FOXTROT'], 20);

        // Ignored: not completed, soft-deleted, outside the period.
        $this->repeat(5, 'dine_in', ['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'], 100, status: 'cancelled');
        $this->repeat(5, 'dine_in', ['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'], 100, deletedAt: '2026-06-20 10:00:00');
        $this->repeat(5, 'dine_in', ['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'], 100, date: '2026-07-01');

        $this->flush();
    }

    /** @param list<string> $keys */
    private function repeat(int $times, string $type, array $keys, float $total, string $status = 'completed', ?string $deletedAt = null, ?string $date = null): void
    {
        for ($n = 0; $n < $times; $n++) {
            $this->order($type, $keys, $total, $status, $deletedAt, $date);
        }
    }

    /** @param list<string> $keys */
    private function order(string $type, array $keys, float $total, string $status = 'completed', ?string $deletedAt = null, ?string $date = null): void
    {
        $this->seq++;
        $branch = $this->branches[$this->seq % 2];
        $uuid = "ord-{$this->seq}";
        $date ??= sprintf('2026-06-%02d', $this->seq % 28 + 1);

        $this->orders[] = [
            'organization_id' => $this->organization->id,
            'branch_id' => $branch,
            'uuid' => $uuid,
            'source_version' => 1,
            'business_date' => $date,
            'type' => $type,
            'status' => $status,
            'total' => $total,
            'deleted_at' => $deletedAt,
        ];
        foreach ($keys as $line => $key) {
            $this->items[] = [
                'organization_id' => $this->organization->id,
                'branch_id' => $branch,
                'order_uuid' => $uuid,
                'uuid' => "{$uuid}-{$line}",
                'product_uuid' => 'p-'.strtolower($key),
                'product_ref' => $key,
                'quantity' => 1,
                'price' => 10,
                'total' => 10,
            ];
        }
    }

    private function flush(): void
    {
        foreach (['wh_orders' => $this->orders, 'wh_order_items' => $this->items] as $table => $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $this->orders = [];
        $this->items = [];
    }

    private function build(string $buildId = self::BUILD): int
    {
        return app(AffinityMiner::class)->build($this->organization, self::PERIOD, CarbonImmutable::parse(self::TODAY), $buildId);
    }

    /** @return array<string, ItemAffinity> 'KEY|KEY' => itemset */
    private function itemsets(string $channel = 'all'): array
    {
        return ItemAffinity::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('period_key', self::PERIOD)
            ->where('channel', $channel)
            ->get()
            ->keyBy(fn (ItemAffinity $a): string => implode('|', $a->items))
            ->all();
    }

    public function test_pair_and_triple_counts_are_exact(): void
    {
        $this->assertSame(8, $this->build());

        $sets = $this->itemsets();
        $together = array_map(fn (ItemAffinity $a): int => $a->together_orders, $sets);
        ksort($together);
        $this->assertSame([
            'ALPHA|BRAVO' => 180,
            'ALPHA|BRAVO|CHARLIE' => 120,
            'ALPHA|CHARLIE' => 120,
            'ALPHA|DELTA' => 10,
            'ALPHA|ECHO' => 10,
            'ALPHA|FOXTROT' => 20,
            'BRAVO|CHARLIE' => 120,
            'CHARLIE|DELTA' => 40,
        ], $together, 'ALPHA|CHARLIE|DELTA is a candidate but never bought together; no quads');

        $triple = $sets['ALPHA|BRAVO|CHARLIE'];
        $this->assertSame(3, $triple->size);
        $this->assertSame(['ALPHA' => 250, 'BRAVO' => 180, 'CHARLIE' => 160], $triple->member_orders);
        $this->assertSame(self::TOTAL, $triple->total_orders);
        $this->assertEqualsWithDelta(120 / self::TOTAL, $triple->support, 0.00000001);
        $this->assertEqualsWithDelta(120 * self::TOTAL ** 2 / (250 * 180 * 160), $triple->lift, 0.0001);
        $this->assertSame(130.0, $triple->avg_order_value);
        $this->assertSame(sha1('ALPHA|BRAVO|CHARLIE'), $triple->itemset_hash);
        $this->assertSame(self::BUILD, $triple->build_id);

        foreach ($sets as $set) {
            $this->assertSame(count($set->items), $set->size);
            $this->assertSame($set->items, array_keys($set->member_orders));
        }
    }

    public function test_lift_and_average_order_value_of_a_known_pair(): void
    {
        $this->build();

        $pair = $this->itemsets()['ALPHA|BRAVO'];
        $this->assertSame(['ALPHA' => 250, 'BRAVO' => 180], $pair->member_orders);
        $support = 180 / self::TOTAL;
        $this->assertEqualsWithDelta($support, $pair->support, 0.00000001);
        $this->assertEqualsWithDelta($support / ((250 / self::TOTAL) * (180 / self::TOTAL)), $pair->lift, 0.0001);
        $this->assertEqualsWithDelta(1.16, $pair->lift, 0.0001);
        $this->assertSame(round((120 * 130 + 60 * 50) / 180, 2), $pair->avg_order_value);

        $this->assertEqualsWithDelta(40 * self::TOTAL / (160 * 50), $this->itemsets()['CHARLIE|DELTA']->lift, 0.0001);
    }

    public function test_drinks_stay_and_adjustment_and_raw_lines_are_dropped(): void
    {
        $this->build();

        $sets = $this->itemsets();
        $this->assertArrayHasKey('ALPHA|CHARLIE', $sets, 'drink partner kept');
        $this->assertArrayHasKey('ALPHA|FOXTROT', $sets, 'unlabelled items kept');

        foreach ($sets as $set) {
            $this->assertEmpty(array_intersect(['ADJ', 'RAW'], $set->items));
            $this->assertLessThanOrEqual(3, $set->size);
        }
    }

    public function test_scope_below_min_baskets_is_skipped(): void
    {
        $this->assertGreaterThan(0, $this->build());

        config(['menu.intelligence.min_baskets' => 261]);
        $this->assertSame(0, $this->build());
        $this->assertSame(0, ItemAffinity::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count());
    }

    public function test_channel_scopes_only_appear_above_the_threshold(): void
    {
        $this->build();
        $this->assertSame(['all'], ItemAffinity::withoutGlobalScopes()->where('organization_id', $this->organization->id)->distinct()->pluck('channel')->all());

        // dine_in has 120 multi-item baskets, takeaway 130, delivery 10.
        config(['menu.intelligence.min_baskets' => 100]);
        $this->build();

        $channels = ItemAffinity::withoutGlobalScopes()->where('organization_id', $this->organization->id)->distinct()->pluck('channel')->all();
        $this->assertEqualsCanonicalizing(['all', 'dine_in', 'takeaway'], $channels);

        $dineIn = $this->itemsets('dine_in');
        $this->assertEqualsCanonicalizing(['ALPHA|BRAVO', 'ALPHA|CHARLIE', 'BRAVO|CHARLIE', 'ALPHA|BRAVO|CHARLIE'], array_keys($dineIn));
        $triple = $dineIn['ALPHA|BRAVO|CHARLIE'];
        $this->assertSame(120, $triple->total_orders);
        $this->assertSame(['ALPHA' => 120, 'BRAVO' => 120, 'CHARLIE' => 120], $triple->member_orders);
        $this->assertEqualsWithDelta(1.0, $triple->lift, 0.0001);

        // Takeaway: 130 orders, ALPHA in 90 of them; CHARLIE only with DELTA there.
        $takeaway = $this->itemsets('takeaway');
        $this->assertEqualsCanonicalizing(['ALPHA|BRAVO', 'ALPHA|ECHO', 'ALPHA|FOXTROT', 'CHARLIE|DELTA'], array_keys($takeaway));
        $this->assertSame(['ALPHA' => 90, 'BRAVO' => 60], $takeaway['ALPHA|BRAVO']->member_orders);
        $this->assertSame(130, $takeaway['ALPHA|BRAVO']->total_orders);
    }

    public function test_for_item_filters_and_orders_by_lift_then_together(): void
    {
        $this->build();
        $orgId = $this->organization->id;

        // ALPHA|BRAVO, ALPHA|FOXTROT and ALPHA|ECHO tie on lift (290 / 250): together breaks the tie.
        $all = AffinityMiner::forItem($orgId, self::PERIOD, 'ALPHA');
        $this->assertSame(
            ['ALPHA|BRAVO|CHARLIE', 'ALPHA|BRAVO', 'ALPHA|FOXTROT', 'ALPHA|ECHO', 'ALPHA|CHARLIE', 'ALPHA|DELTA'],
            $all->map(fn (ItemAffinity $a): string => implode('|', $a->items))->all(),
        );

        $pairs = AffinityMiner::forItem($orgId, self::PERIOD, 'DELTA', size: 2);
        $this->assertSame(['CHARLIE|DELTA', 'ALPHA|DELTA'], $pairs->map(fn (ItemAffinity $a): string => implode('|', $a->items))->all());

        $this->assertCount(0, AffinityMiner::forItem($orgId, self::PERIOD, 'ALPHA', 'dine_in'));
        $this->assertCount(0, AffinityMiner::forItem($orgId, self::PERIOD, 'ADJ'));
        $this->assertCount(1, AffinityMiner::forItem($orgId, self::PERIOD, 'ALPHA', size: 3));
    }

    public function test_member_orders_reconcile_with_item_stats_orders(): void
    {
        $this->build();
        app(ItemStatsBuilder::class)->build($this->organization, self::PERIOD, CarbonImmutable::parse(self::TODAY), self::BUILD);

        // No agg rows here, so the stats builder writes only per-channel rows; their orders add up per item.
        $orders = ItemPeriodStat::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('period_key', self::PERIOD)
            ->where('branch_id', 0)
            ->where('channel', '!=', 'all')
            ->get()
            ->groupBy('item_key')
            ->map(fn ($rows): int => (int) $rows->sum('orders'));

        $members = [];
        foreach ($this->itemsets() as $set) {
            $members += $set->member_orders;
        }
        ksort($members);

        $this->assertSame(['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA', 'ECHO', 'FOXTROT'], array_keys($members));
        foreach ($members as $key => $n) {
            $this->assertSame($orders[$key], $n, $key);
        }
    }

    public function test_a_small_chunk_size_gives_identical_results(): void
    {
        config(['menu.intelligence.min_baskets' => 100]);
        $snapshot = fn (): array => ItemAffinity::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->orderBy('channel')->orderBy('itemset_hash')
            ->get(['channel', 'size', 'itemset_hash', 'items', 'member_orders', 'together_orders', 'total_orders', 'support', 'lift', 'avg_order_value'])
            ->toArray();

        $this->assertSame(2000, (int) config('menu.intelligence.affinity_chunk_orders'));
        $default = $this->build();
        $expected = $snapshot();

        // 7 orders per chunk: dozens of chunks, and boundaries cut across every basket pattern of the fixture.
        $written = (new AffinityMiner(app(ItemRoles::class), 7))->build($this->organization, self::PERIOD, CarbonImmutable::parse(self::TODAY), self::BUILD);

        $this->assertSame($default, $written);
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $snapshot());

        config(['menu.intelligence.affinity_chunk_orders' => 3]);
        $this->assertSame($default, $this->build());
        $this->assertSame($expected, $snapshot());
    }

    public function test_rebuild_replaces_rows(): void
    {
        $this->build('01JAFFIN00000000000000001');

        DB::table('wh_order_items')->where('organization_id', $this->organization->id)->where('product_ref', 'ECHO')->delete();
        $written = $this->build('01JAFFIN00000000000000002');

        $this->assertSame(7, $written);
        $rows = ItemAffinity::withoutGlobalScopes()->where('organization_id', $this->organization->id)->get();
        $this->assertCount(7, $rows);
        $this->assertSame(['01JAFFIN00000000000000002'], $rows->pluck('build_id')->unique()->values()->all());
        $this->assertArrayNotHasKey('ALPHA|ECHO', $this->itemsets());
    }
}
