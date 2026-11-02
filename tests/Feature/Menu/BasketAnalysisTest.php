<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Ai\Tools\Contracts\ReportsProgress;
use App\Ai\Tools\Menu\BoughtTogether;
use App\Domain\Menu\BasketAnalysis;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ReportFilters;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

class BasketAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private MenuFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = MenuFixture::create();
        foreach ([
            'ZORBA-PLATE' => ItemRole::Main, 'KELTO-WRAP' => ItemRole::Main,
            'BRIMO-BOX' => ItemRole::Main, 'NADD-BOWL' => ItemRole::Main,
            'FETA-TOWER' => ItemRole::Main, 'QUILL-STEW' => ItemRole::Main,
            'LUMO-RICE' => ItemRole::Side, 'PEXA-SALAD' => ItemRole::Side,
            'VIRA-SODA' => ItemRole::Drink, 'TINY-SPRING' => ItemRole::Drink,
            'GLAZE-DIP' => ItemRole::Addon, 'DIFF-LINE' => ItemRole::Adjustment,
            'BULK-FLOUR' => ItemRole::Raw,
        ] as $key => $role) {
            app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, $key, $role);
        }
    }

    private function metricsQuery(?User $user = null, string $from = MenuFixture::FROM): MetricsQuery
    {
        return MetricsQuery::for($user ?? $this->fixture->owner, ReportFilters::fromArray([
            'preset' => 'custom', 'from' => $from, 'to' => MenuFixture::TO,
        ], CarbonImmutable::parse(MenuFixture::TO)));
    }

    private function analyse(?User $user = null, string $from = MenuFixture::FROM, ?string $anchor = null): array
    {
        return app(BasketAnalysis::class)->pairsFor($this->metricsQuery($user, $from), app(ItemRoles::class)->mapFor($this->fixture->organization->id), $anchor);
    }

    public function test_pairs_are_ranked_by_lift_and_exclude_non_companions(): void
    {
        $result = $this->analyse();
        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThanOrEqual(200, $result['orders_considered']);
        $this->assertSame('ZORBA-PLATE', $result['pairs'][0]['anchor_key']);
        $this->assertSame('LUMO-RICE', $result['pairs'][0]['partner_key']);
        $this->assertGreaterThan(1, $result['pairs'][0]['lift']);
        $this->assertSame('Zorba Plate', $result['pairs'][0]['anchor_name']);
        foreach ($result['pairs'] as $pair) {
            $this->assertSame('main', $pair['anchor_role']);
            $this->assertContains($pair['partner_role'], ['side', 'drink', 'dessert']);
            $this->assertNotContains($pair['partner_key'], ['DIFF-LINE', 'BULK-FLOUR', 'GLAZE-DIP']);
        }
    }

    public function test_anchor_limit_and_branch_scope_use_only_authorized_orders(): void
    {
        $all = $this->analyse(anchor: 'ZORBA-PLATE');
        $manager = $this->analyse($this->fixture->managerA, anchor: 'ZORBA-PLATE');
        $this->assertNotEmpty($manager['pairs']);
        $this->assertSame('ZORBA-PLATE', $manager['pairs'][0]['anchor_key']);
        $this->assertSame('LUMO-RICE', $manager['pairs'][0]['partner_key']);
        $this->assertLessThan($all['pairs'][0]['together_orders'], $manager['pairs'][0]['together_orders']);
        $count = DB::table('wh_orders as o')->join('wh_order_items as a', fn ($j) => $j->on('a.branch_id', '=', 'o.branch_id')->on('a.order_uuid', '=', 'o.uuid'))
            ->join('wh_order_items as b', fn ($j) => $j->on('b.branch_id', '=', 'o.branch_id')->on('b.order_uuid', '=', 'o.uuid'))
            ->where('o.branch_id', $this->fixture->branchA->id)->where('o.status', 'completed')->whereNull('o.deleted_at')
            ->where('a.product_ref', 'ZORBA-PLATE')->where('b.product_ref', 'LUMO-RICE')->distinct()->count('o.id');
        $this->assertSame($count, $manager['pairs'][0]['together_orders']);
        $limited = app(BasketAnalysis::class)->pairsFor($this->metricsQuery(), app(ItemRoles::class)->mapFor($this->fixture->organization->id), null, 1);
        $this->assertCount(1, $limited['pairs']);
    }

    public function test_short_period_has_insufficient_data(): void
    {
        $result = $this->analyse(from: '2026-10-16');
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $result['reason']);
        $this->assertSame([], $result['pairs']);
    }

    public function test_pair_statistics_count_distinct_orders_even_with_duplicate_item_lines(): void
    {
        $before = $this->analyse(anchor: 'ZORBA-PLATE');
        $pair = $before['pairs'][0];
        $multiOrders = DB::table('wh_orders as o')
            ->join('wh_order_items as i', fn ($j) => $j->on('i.branch_id', '=', 'o.branch_id')->on('i.order_uuid', '=', 'o.uuid'))
            ->whereIn('o.branch_id', [$this->fixture->branchA->id, $this->fixture->branchB->id])
            ->where('o.status', 'completed')->whereNull('o.deleted_at')
            ->groupBy('o.id')->havingRaw('COUNT(i.id) >= 2')->select('o.id');
        $counts = [];
        foreach (['ZORBA-PLATE', 'LUMO-RICE'] as $key) {
            $counts[$key] = DB::table('wh_orders as o')
                ->join('wh_order_items as i', fn ($j) => $j->on('i.branch_id', '=', 'o.branch_id')->on('i.order_uuid', '=', 'o.uuid'))
                ->whereIn('o.id', clone $multiOrders)->where('i.product_ref', $key)->distinct()->count('o.id');
        }
        $this->assertEqualsWithDelta($pair['together_orders'] / $before['orders_considered'], $pair['support'], 0.000001);
        $this->assertEqualsWithDelta($pair['together_orders'] / $counts['ZORBA-PLATE'], $pair['confidence'], 0.000001);
        $this->assertEqualsWithDelta($pair['confidence'] / ($counts['LUMO-RICE'] / $before['orders_considered']), $pair['lift'], 0.000001);

        $line = DB::table('wh_order_items as a')
            ->join('wh_order_items as b', fn ($j) => $j->on('a.branch_id', '=', 'b.branch_id')->on('a.order_uuid', '=', 'b.order_uuid'))
            ->where('a.product_ref', 'ZORBA-PLATE')->where('b.product_ref', 'LUMO-RICE')->select('a.*')->first();
        $copy = (array) $line;
        unset($copy['id']);
        $copy['uuid'] = 'duplicate-line';
        DB::table('wh_order_items')->insert($copy);
        $after = $this->analyse(anchor: 'ZORBA-PLATE');
        $this->assertSame($pair, $after['pairs'][0]);
        $this->assertSame($before['orders_considered'], $after['orders_considered']);

        DB::table('wh_orders')->where('branch_id', $line->branch_id)->where('uuid', $line->order_uuid)->update(['status' => 'cancelled']);
        $cancelled = $this->analyse(anchor: 'ZORBA-PLATE');
        $this->assertSame($before['orders_considered'] - 1, $cancelled['orders_considered']);
        $this->assertSame($pair['together_orders'] - 1, $cancelled['pairs'][0]['together_orders']);
    }

    public function test_tool_exposes_progress_and_scoped_results(): void
    {
        $tool = new BoughtTogether($this->fixture->managerA);
        $this->assertInstanceOf(ReportsProgress::class, $tool);
        $this->assertSame('بشوف الأصناف اللي بتتطلب مع بعض…', $tool->progressLabel());
        $result = json_decode($tool->handle(new Request(['preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO, 'item_key' => 'ZORBA-PLATE'])), true);
        $this->assertSame('ok', $result['metrics']['status']);
        $this->assertSame('ZORBA-PLATE', $result['metrics']['pairs'][0]['anchor_key']);
        $forbidden = json_decode($tool->handle(new Request(['preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO, 'branches' => [$this->fixture->branchB->id]])), true);
        $this->assertSame('forbidden_branch', $forbidden['error']);

        $noChannelOrders = json_decode($tool->handle(new Request(['preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO, 'channel' => 'unavailable'])), true);
        $this->assertSame('insufficient_data', $noChannelOrders['metrics']['status']);
        $this->assertSame(0, $noChannelOrders['metrics']['orders_considered']);
    }
}
