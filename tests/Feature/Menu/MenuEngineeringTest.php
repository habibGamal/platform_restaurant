<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Ai\Agents\ItemRoleClassifier;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Ai\Tools\Menu\MenuEngineering;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Domain\Menu\MenuEngineeringAnalysis;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ReportFilters;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

class MenuEngineeringTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = [
        'ZORBA-PLATE' => ItemRole::Main,
        'KELTO-WRAP' => ItemRole::Main,
        'BRIMO-BOX' => ItemRole::Main,
        'NADD-BOWL' => ItemRole::Main,
        'FETA-TOWER' => ItemRole::Main,
        'QUILL-STEW' => ItemRole::Main,
        'LUMO-RICE' => ItemRole::Side,
        'PEXA-SALAD' => ItemRole::Side,
        'VIRA-SODA' => ItemRole::Drink,
        'TINY-SPRING' => ItemRole::Drink,
        'GLAZE-DIP' => ItemRole::Addon,
        'DIFF-LINE' => ItemRole::Adjustment,
        'BULK-FLOUR' => ItemRole::Raw,
    ];

    private MenuFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = MenuFixture::create();
    }

    /** @param list<string> $except */
    private function seedRoles(array $except = []): void
    {
        foreach (self::ROLES as $key => $role) {
            if (! in_array($key, $except, true)) {
                app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, $key, $role);
            }
        }
    }

    /** @return array<string, mixed> */
    private function analyse(?User $user = null, string $from = MenuFixture::FROM, string $to = MenuFixture::TO): array
    {
        $filters = ReportFilters::fromArray(
            ['preset' => 'custom', 'from' => $from, 'to' => $to],
            CarbonImmutable::parse(MenuFixture::TO),
        );
        $query = MetricsQuery::for($user ?? $this->fixture->owner, $filters);

        return app(MenuEngineeringAnalysis::class)->run($query, $this->fixture->organization);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function groupedKeys(array $result): array
    {
        $keys = [];
        foreach ($result['groups'] as $quadrants) {
            foreach ($quadrants as $rows) {
                array_push($keys, ...array_column($rows, 'key'));
            }
        }

        return $keys;
    }

    public function test_hidden_gem_is_the_high_margin_low_volume_main(): void
    {
        $this->seedRoles();

        $result = $this->analyse();

        $this->assertSame('ok', $result['status']);
        $groups = $result['groups']['main'];
        $this->assertSame(['ZORBA-PLATE'], array_column($groups['hidden_gems'], 'key'));
        $this->assertContains('KELTO-WRAP', array_column($groups['stars'], 'key'));
        $this->assertContains('BRIMO-BOX', array_column($groups['workhorses'], 'key'));
        $this->assertSame(['NADD-BOWL'], array_column($groups['weak'], 'key'));

        $zorba = $groups['hidden_gems'][0];
        $this->assertSame('Zorba Plate', $zorba['name']);
        $this->assertSame('main', $zorba['role']);
        $this->assertEqualsWithDelta(140.0, $zorba['unit_margin'], 0.01);
        $this->assertEqualsWithDelta(0.70, $zorba['margin_pct'], 0.001);
        $this->assertLessThan(0.70, $zorba['popularity_ratio']);
        $this->assertGreaterThan(1.0, $zorba['margin_vs_avg']);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column(array_merge(...array_values($groups)), 'mix_share')), 0.001);

        // floor = Σ(net − cogs) / Σnet over the four eligible mains, straight from the aggregates
        $row = DB::table('agg_product_daily')
            ->whereIn('product_ref', ['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL'])
            ->selectRaw('SUM(net_sales) AS net, SUM(cogs) AS cogs')
            ->first();
        $this->assertEqualsWithDelta(((float) $row->net - (float) $row->cogs) / (float) $row->net, $result['floors']['main'], 0.0001);
    }

    public function test_drinks_addons_adjustments_and_raw_never_appear_in_any_group(): void
    {
        $this->seedRoles();

        $result = $this->analyse();

        $keys = $this->groupedKeys($result);
        $this->assertNotEmpty($keys);
        foreach (['TINY-SPRING', 'VIRA-SODA', 'GLAZE-DIP', 'DIFF-LINE', 'BULK-FLOUR'] as $excluded) {
            $this->assertNotContains($excluded, $keys);
        }
        $this->assertEqualsCanonicalizing(['main', 'side'], array_keys($result['groups']));
    }

    public function test_unclassified_items_are_excluded_and_counted(): void
    {
        $this->seedRoles(except: ['NADD-BOWL']);
        ItemRoleClassifier::fake(fn () => throw new RuntimeException('model unavailable'));

        $result = $this->analyse();

        $this->assertSame('ok', $result['status']);
        $this->assertNotContains('NADD-BOWL', $this->groupedKeys($result));
        $this->assertSame(1, $result['unclassified_count']);
        ItemRoleClassifier::assertPromptedTimes(1);
    }

    public function test_new_and_unknown_cost_items_are_listed_separately(): void
    {
        $this->seedRoles();

        $result = $this->analyse();

        $this->assertSame(['FETA-TOWER'], array_column($result['new_items'], 'key'));
        $this->assertSame(['QUILL-STEW'], array_column($result['unknown_cost'], 'key'));
        $this->assertNotContains('FETA-TOWER', $this->groupedKeys($result));
        $this->assertNotContains('QUILL-STEW', $this->groupedKeys($result));
        $this->assertSame(0, $result['unclassified_count']);
    }

    public function test_new_item_with_unknown_cost_appears_in_both_lists(): void
    {
        $this->seedRoles();
        DB::table('agg_product_daily')->where('product_ref', 'FETA-TOWER')->update(['cogs' => 0]);

        $result = $this->analyse();

        $this->assertContains('FETA-TOWER', array_column($result['new_items'], 'key'));
        $this->assertContains('FETA-TOWER', array_column($result['unknown_cost'], 'key'));
        $this->assertNotContains('FETA-TOWER', $this->groupedKeys($result));
    }

    public function test_manager_sees_only_their_branch(): void
    {
        $this->seedRoles();

        $result = $this->analyse($this->fixture->managerA);

        $branchAQty = (float) DB::table('agg_product_daily')
            ->where('branch_id', $this->fixture->branchA->id)
            ->where('product_ref', 'KELTO-WRAP')
            ->sum('qty_sold');
        $allQty = (float) DB::table('agg_product_daily')->where('product_ref', 'KELTO-WRAP')->sum('qty_sold');

        $mainGroups = $result['groups']['main'];
        $this->assertIsArray($mainGroups);
        $kelto = collect($mainGroups)->flatten(1)->firstWhere('key', 'KELTO-WRAP');
        $this->assertNotNull($kelto);
        $this->assertEqualsWithDelta($branchAQty, $kelto['qty'], 0.001);
        $this->assertLessThan($allQty, $kelto['qty']);
    }

    public function test_insufficient_data_for_short_history(): void
    {
        $this->seedRoles();

        $result = $this->analyse(from: '2026-10-12', to: '2026-10-16');

        $this->assertSame('insufficient_data', $result['status']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $result['reason']);
        $this->assertArrayNotHasKey('groups', $result);
    }

    public function test_tool_envelope_has_summary_and_progress_label(): void
    {
        $this->seedRoles();
        $tool = new MenuEngineering($this->fixture->owner);

        $this->assertInstanceOf(ReportsProgress::class, $tool);
        $this->assertSame('menu_engineering', $tool->name());
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $tool->progressLabel());

        $result = json_decode($tool->handle(new Request([
            'preset' => 'custom',
            'from' => MenuFixture::FROM,
            'to' => MenuFixture::TO,
        ])), true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('لقيت صنف واحد مربح ومش بيتباع كفاية من 4 أصناف رئيسية', $result['summary']);
        $this->assertSame('ok', $result['metrics']['status']);
        $this->assertSame(['ZORBA-PLATE'], array_column($result['metrics']['groups']['main']['hidden_gems'], 'key'));
        $this->assertArrayHasKey('side', $result['metrics']['groups']);
    }

    public function test_tool_role_filter_and_per_branch(): void
    {
        $this->seedRoles();
        $tool = new MenuEngineering($this->fixture->owner);

        $result = json_decode($tool->handle(new Request([
            'preset' => 'custom',
            'from' => MenuFixture::FROM,
            'to' => MenuFixture::TO,
            'role' => 'main',
            'per_branch' => true,
        ])), true);

        $this->assertSame(['main'], array_keys($result['metrics']['groups']));
        $byBranch = $result['metrics']['by_branch'];
        $this->assertEqualsCanonicalizing(
            [$this->fixture->branchA->id, $this->fixture->branchB->id],
            array_map('intval', array_keys($byBranch)),
        );
        $this->assertSame(['main'], array_keys($byBranch[$this->fixture->branchA->id]['groups']));
    }

    public function test_tool_reports_insufficient_data_in_summary(): void
    {
        $this->seedRoles();
        $tool = new MenuEngineering($this->fixture->owner);

        $result = json_decode($tool->handle(new Request([
            'preset' => 'custom',
            'from' => '2026-10-12',
            'to' => '2026-10-16',
        ])), true);

        $this->assertSame('insufficient_data', $result['metrics']['status']);
        $this->assertSame($result['metrics']['reason'], $result['summary']);
    }

    public function test_tool_rejects_channel_filter_it_cannot_apply(): void
    {
        $tool = new MenuEngineering($this->fixture->owner);
        $result = json_decode($tool->handle(new Request([
            'preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO, 'channel' => 'delivery',
        ])), true);

        $this->assertSame('unsupported_filter', $result['error']);
    }

    public function test_tool_rejects_a_manager_request_for_an_inaccessible_branch(): void
    {
        $tool = new MenuEngineering($this->fixture->managerA);
        $result = json_decode($tool->handle(new Request([
            'preset' => 'custom',
            'from' => MenuFixture::FROM,
            'to' => MenuFixture::TO,
            'branches' => [$this->fixture->branchB->id],
            'per_branch' => true,
        ])), true);

        $this->assertSame('forbidden_branch', $result['error']);
    }

    public function test_tool_immediately_reflects_owner_role_changes(): void
    {
        $this->seedRoles();
        $tool = new MenuEngineering($this->fixture->owner);
        $request = new Request(['preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO]);
        $before = json_decode($tool->handle($request), true);
        $this->assertContains('ZORBA-PLATE', $this->groupedKeys($before['metrics']));

        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'ZORBA-PLATE', ItemRole::Drink);
        $after = json_decode($tool->handle($request), true);
        $this->assertNotContains('ZORBA-PLATE', $this->groupedKeys($after['metrics']));
    }

    public function test_large_nested_results_respect_the_hard_payload_limit(): void
    {
        $tool = new MenuEngineering($this->fixture->owner);
        $items = array_fill(0, 40, ['name' => str_repeat('صنف', 60), 'qty' => 10, 'margin_pct' => 0.5]);
        $groups = array_fill_keys(['hidden_gems', 'stars', 'workhorses', 'weak'], $items);
        $result = $tool->enforcePayloadSize([
            'metrics' => ['groups' => ['main' => $groups, 'side' => $groups], 'by_branch' => [1 => ['groups' => ['main' => $groups]], 2 => ['groups' => ['main' => $groups]]]],
        ]);

        $this->assertLessThanOrEqual(16 * 1024, strlen($result));
        $this->assertTrue(json_decode($result, true)['truncated']);
    }
}
