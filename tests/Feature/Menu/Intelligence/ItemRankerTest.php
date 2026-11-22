<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\IntelligenceWriter;
use App\Domain\Menu\Intelligence\ItemRanker;
use App\Models\Menu\ItemRanking;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * October 2026 stats (branch 0), next month November = winter:
 *
 *   main:single  A  qty 100 net 5000 cogs 2000  um 30  mom 0.2   repeat 0.3  winter  -> stars
 *                B  qty 150 net 6000 cogs 3000  um 20  mom null  repeat 0.5  all     -> workhorses
 *                C  qty  50 net 3000 cogs 1000  um 40  mom -0.1  repeat 0.1  summer  -> hidden_gems
 *   main:family  TRAY alone                                                           -> stars
 *   side:single  S1 stars, S2 workhorses, S3 weak, S4 hidden_gems
 *   delivery     A qty 40 (um 30), C qty 10 (um 40), B on sale 3 days only
 *
 * Ineligible: NEW (5 days), FREE (cogs 0), DRINK (not marketable), NOROLE, NOPROFILE.
 */
class ItemRankerTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-08';

    private const PERIOD = '2026-10';

    private const BUILD = '01JRANK000000000000000001';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Org Ranker',
            'slug' => 'org-ranker-'.Str::random(6),
            'plan' => 'pro',
            'ai_enabled' => true,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $this->profile('A', 'main', 'single', 'winter');
        $this->profile('B', 'main', 'single', 'all');
        $this->profile('C', 'main', 'single', 'summer');
        $this->profile('TRAY', 'main', 'family', 'all');
        foreach (['S1', 'S2', 'S3', 'S4'] as $key) {
            $this->profile($key, 'side', 'single', 'all');
        }
        $this->profile('NEW', 'main', 'single', 'all');
        $this->profile('FREE', 'main', 'single', 'all');
        $this->profile('DRINK', 'drink', 'single', 'all');
        $this->profile('NOROLE', null, 'single', 'all');

        $this->stat('A', 100, 5000, 2000, mom: 0.2, repeat: 0.3);
        $this->stat('B', 150, 6000, 3000, mom: null, repeat: 0.5);
        $this->stat('C', 50, 3000, 1000, mom: -0.1, repeat: 0.1);
        $this->stat('TRAY', 20, 4000, 1500);
        $this->stat('S1', 100, 1000, 300);
        $this->stat('S2', 100, 600, 400);
        $this->stat('S3', 10, 50, 40);
        $this->stat('S4', 10, 200, 50);

        $this->stat('NEW', 80, 4000, 1000, days: 5);
        $this->stat('FREE', 80, 4000, 0);
        $this->stat('DRINK', 300, 3000, 900);
        $this->stat('NOROLE', 80, 4000, 1000);
        $this->stat('NOPROFILE', 80, 4000, 1000);

        $this->stat('A', 40, 2000, 800, channel: 'delivery', days: 15);
        $this->stat('C', 10, 600, 200, channel: 'delivery', days: 15);
        $this->stat('B', 30, 1200, 600, channel: 'delivery', days: 3);

        // Another branch's row: only branch_id 0 is ranked.
        $this->stat('S3', 999, 99999, 100, branch: 7);
    }

    public function test_family_tray_and_single_items_never_share_a_ranking_list(): void
    {
        $this->build();

        $tray = $this->ranking('push_next_month', 'TRAY');
        $sandwich = $this->ranking('push_next_month', 'A');

        $this->assertSame('main:family', $tray->segment_key);
        $this->assertSame('main:single', $sandwich->segment_key);
        $this->assertSame(1, $tray->rank);
        $this->assertSame(1, $sandwich->rank);

        // Every list's ranks are 1..n within its own segment.
        $lists = ItemRanking::withoutGlobalScopes()->where('organization_id', $this->organization->id)->get()
            ->groupBy(fn (ItemRanking $r): string => $r->strategy.'|'.$r->channel.'|'.$r->segment_key);
        foreach ($lists as $list => $rows) {
            $this->assertSame(range(1, $rows->count()), $rows->pluck('rank')->sort()->values()->all(), $list);
        }

        $this->assertSame(['A', 'C', 'B'], $this->order('push_next_month', 'main:single'));
        $this->assertSame(['TRAY'], $this->order('push_next_month', 'main:family'));
    }

    public function test_ineligible_items_are_not_ranked(): void
    {
        $this->build();

        $keys = ItemRanking::withoutGlobalScopes()->where('organization_id', $this->organization->id)->pluck('item_key')->unique()->sort()->values()->all();

        $this->assertSame(['A', 'B', 'C', 'S1', 'S2', 'S3', 'S4', 'TRAY'], $keys);
    }

    public function test_push_next_month_score_matches_the_hand_computed_case(): void
    {
        $this->build();

        // unit_margin B 0 / A .5 / C 1; headroom C 1 / A .5 / B 0; momentum (B unknown) C 0 / A 1 / B .5;
        // season_fit A 1 / B .5 / C 0; repeat C 0 / A .5 / B 1.
        $a = $this->ranking('push_next_month', 'A');
        $this->assertSame(0.675, $a->score);
        $this->assertSame([
            'unit_margin' => ['value' => 0.5, 'weight' => 0.3, 'contribution' => 0.15],
            'headroom' => ['value' => 0.5, 'weight' => 0.25, 'contribution' => 0.125],
            'momentum' => ['value' => 1.0, 'weight' => 0.2, 'contribution' => 0.2],
            'season_fit' => ['value' => 1.0, 'weight' => 0.15, 'contribution' => 0.15],
            'repeat' => ['value' => 0.5, 'weight' => 0.1, 'contribution' => 0.05],
        ], $a->breakdown);
        $this->assertSame('stars', $a->quadrant);

        $this->assertSame(0.275, $this->ranking('push_next_month', 'B')->score);
        $this->assertSame(0.55, $this->ranking('push_next_month', 'C')->score);
        $this->assertSame(0.5, $this->ranking('push_next_month', 'B')->breakdown['momentum']['value']);
    }

    public function test_single_item_segment_gets_the_middle_percentile(): void
    {
        $this->build();

        $tray = $this->ranking('push_next_month', 'TRAY');

        $this->assertSame(0.5, $tray->breakdown['unit_margin']['value']);
        $this->assertSame(0.5, $tray->breakdown['headroom']['value']);
        $this->assertSame(0.5, $tray->score);
        $this->assertSame(['x' => 0.5], ItemRanker::percentiles(['x' => 42.0]));
        $this->assertSame(['x' => 0.1667, 'y' => 0.8333, 'z' => 0.8333, 'w' => 0.1667], array_map(
            fn (float $v): float => round($v, 4),
            ItemRanker::percentiles(['x' => 1.0, 'y' => 5.0, 'z' => 5.0, 'w' => 1.0]),
        ));
    }

    public function test_strategies_rank_only_their_quadrants(): void
    {
        $this->build();

        $this->assertSame(['A'], $this->order('protect', 'main:single'));
        $this->assertSame(['TRAY'], $this->order('protect', 'main:family'));
        $this->assertSame(['S1'], $this->order('protect', 'side:single'));
        $this->assertSame(['B'], $this->order('reprice', 'main:single'));
        $this->assertSame(['S2'], $this->order('reprice', 'side:single'));
        $this->assertSame([], $this->order('fix_or_drop', 'main:single'));
        $this->assertSame(['S3'], $this->order('fix_or_drop', 'side:single'));
        $this->assertSame(['S1', 'S2', 'S3', 'S4'], collect($this->order('push_next_month', 'side:single'))->sort()->values()->all());

        $this->assertSame('weak', $this->ranking('fix_or_drop', 'S3')->quadrant);
        $this->assertSame('hidden_gems', $this->ranking('push_next_month', 'S4')->quadrant);
    }

    public function test_season_fit_uses_the_month_after_the_period(): void
    {
        $this->build();

        $this->assertSame(1.0, $this->ranking('push_next_month', 'A')->breakdown['season_fit']['value']);
        $this->assertSame(0.5, $this->ranking('push_next_month', 'B')->breakdown['season_fit']['value']);
        $this->assertSame(0.0, $this->ranking('push_next_month', 'C')->breakdown['season_fit']['value']);

        $this->assertSame(['winter'], ItemRanker::activeSeasons(CarbonImmutable::parse('2026-10-31')));
        $this->assertSame(['winter', 'ramadan'], ItemRanker::activeSeasons(CarbonImmutable::parse('2026-01-31')));
        $this->assertSame(['summer'], ItemRanker::activeSeasons(CarbonImmutable::parse('2026-05-20')));
        $this->assertSame([], ItemRanker::activeSeasons(CarbonImmutable::parse('2026-09-30')));
    }

    public function test_other_channels_get_push_rows_only_from_their_own_stats(): void
    {
        $this->build();

        $rows = ItemRanking::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('channel', 'delivery')
            ->orderBy('rank')
            ->get();

        $this->assertSame(['push_next_month'], $rows->pluck('strategy')->unique()->values()->all());
        // B sold on 3 delivery days only. On delivery A is a workhorse, C a hidden gem.
        $this->assertSame(['C', 'A'], $rows->pluck('item_key')->all());
        $this->assertSame([0.7, 0.3], $rows->pluck('score')->all());
        $this->assertSame(['hidden_gems', 'workhorses'], $rows->pluck('quadrant')->all());
        $this->assertSame('main:single', $rows[0]->segment_key);
    }

    public function test_build_replaces_the_period(): void
    {
        $first = $this->build();
        $second = $this->build();

        $this->assertSame([16, 16], [$first, $second]);
        $this->assertSame(16, ItemRanking::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count());
        $this->assertSame([self::BUILD], ItemRanking::withoutGlobalScopes()->distinct()->pluck('build_id')->all());
    }

    public function test_top_returns_one_period_in_rank_order(): void
    {
        $this->build();

        $top = app(ItemRanker::class)->top($this->organization->id, [self::PERIOD], 'push_next_month', segmentKey: 'main:single', limit: 2);

        $this->assertSame(['A', 'C'], array_column($top, 'item_key'));
        $this->assertSame('Item A', $top[0]['name']);
        $this->assertSame('main:single', $top[0]['segment_key']);
        $this->assertSame('stars', $top[0]['quadrant']);
        $this->assertSame(0.675, $top[0]['score']);
        $this->assertSame([self::PERIOD => 0.675], $top[0]['per_period']);
        $this->assertSame(0.15, $top[0]['breakdown']['unit_margin']['contribution'] ?? null);

        $delivery = app(ItemRanker::class)->top($this->organization->id, [self::PERIOD], 'push_next_month', 'delivery');
        $this->assertSame(['C', 'A'], array_column($delivery, 'item_key'));
    }

    public function test_top_over_several_periods_means_scores_with_missing_as_zero(): void
    {
        $this->rankings('2026-09', ['A' => 0.8, 'C' => 0.4]);
        $this->rankings('2026-10', ['B' => 0.9, 'A' => 0.6]);

        $top = app(ItemRanker::class)->top($this->organization->id, ['2026-09', '2026-10'], 'push_next_month');

        $this->assertSame(['A', 'B', 'C'], array_column($top, 'item_key'));
        $this->assertSame([0.7, 0.45, 0.2], array_column($top, 'score'));
        $this->assertSame(['2026-09' => 0.4, '2026-10' => 0.0], $top[2]['per_period']);
        $this->assertSame(['2026-09' => 0.0, '2026-10' => 0.9], $top[1]['per_period']);
        $this->assertNull($top[0]['breakdown']);
        $this->assertSame('Item C', $top[2]['name']);

        $this->assertSame(['A'], array_column(app(ItemRanker::class)->top($this->organization->id, ['2026-09', '2026-10'], 'push_next_month', limit: 1), 'item_key'));
    }

    private function build(): int
    {
        return app(ItemRanker::class)->build($this->organization, self::PERIOD, CarbonImmutable::parse(self::TODAY), self::BUILD);
    }

    private function ranking(string $strategy, string $key, string $channel = 'all'): ItemRanking
    {
        return ItemRanking::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('period_key', self::PERIOD)
            ->where('strategy', $strategy)
            ->where('channel', $channel)
            ->where('item_key', $key)
            ->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function order(string $strategy, string $segment): array
    {
        return array_values(ItemRanking::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('strategy', $strategy)
            ->where('channel', 'all')
            ->where('segment_key', $segment)
            ->orderBy('rank')
            ->pluck('item_key')
            ->all());
    }

    /**
     * @param  array<string, float>  $scores  in rank order
     */
    private function rankings(string $period, array $scores): void
    {
        $rows = [];
        $rank = 0;
        foreach ($scores as $key => $score) {
            $rows[] = [
                'strategy' => 'push_next_month',
                'channel' => 'all',
                'segment_key' => 'main:single',
                'item_key' => $key,
                'score' => $score,
                'rank' => ++$rank,
                'quadrant' => 'stars',
                'breakdown' => [],
                'build_id' => self::BUILD,
            ];
        }

        IntelligenceWriter::replace('item_rankings', $this->organization->id, $period, $rows);
    }

    private function profile(string $key, ?string $role, string $tier, string $season): void
    {
        DB::table('item_profiles')->insert([
            'organization_id' => $this->organization->id,
            'item_key' => $key,
            'name' => 'Item '.$key,
            'category_key' => null,
            'role' => $role,
            'serves_n' => 1,
            'serves_source' => 'default',
            'price_tier' => $tier,
            'price_tier_source' => 'auto',
            'season' => $season,
            'season_source' => 'auto',
            'list_price' => 100,
            'unit_cost' => 40,
            'contains' => '{}',
            'main_protein' => null,
            'has_recipe' => true,
            'is_existing_combo' => false,
            'combo_members' => null,
            'built_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stat(string $key, float $qty, float $net, float $cogs, ?float $mom = null, ?float $repeat = null, int $days = 20, string $channel = 'all', int $branch = 0): void
    {
        DB::table('item_period_stats')->insert([
            'organization_id' => $this->organization->id,
            'period_key' => self::PERIOD,
            'branch_id' => $branch,
            'channel' => $channel,
            'item_key' => $key,
            'qty' => $qty,
            'net_sales' => $net,
            'cogs' => $cogs,
            'gross_profit' => $net - $cogs,
            'open_days' => 31,
            'days_sold' => $days,
            'velocity' => $qty / max($days, 1),
            'orders' => (int) $qty,
            'attach_rate' => null,
            'daypart' => '{}',
            'weekday' => '{}',
            'channel_mix' => null,
            'repeat_customer_share' => $repeat,
            'return_qty' => 0,
            'waste_cost' => 0,
            'mom_velocity_change' => $mom,
            'build_id' => self::BUILD,
            'created_at' => now(),
        ]);
    }
}
