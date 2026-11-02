<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Ai\Tools\Menu\ComboBuilderTool;
use App\Domain\Assistant\Evals\EvalRunner;
use App\Domain\Assistant\GroundingResult;
use App\Domain\Menu\ComboBuilder;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Domain\Menu\MenuEngineeringAnalysis;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ReportFilters;
use App\Models\ItemRoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

class ComboBuilderTest extends TestCase
{
    use RefreshDatabase;

    private MenuFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = MenuFixture::create();
        foreach (['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL', 'FETA-TOWER', 'QUILL-STEW'] as $key) {
            app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, $key, ItemRole::Main);
        }
        foreach (['LUMO-RICE', 'PEXA-SALAD'] as $key) {
            app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, $key, ItemRole::Side);
        }
        foreach (['VIRA-SODA', 'TINY-SPRING'] as $key) {
            app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, $key, ItemRole::Drink);
        }
        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'GLAZE-DIP', ItemRole::Addon);
        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'DIFF-LINE', ItemRole::Adjustment);
        app(ItemRoles::class)->setOwnerRole($this->fixture->organization->id, 'BULK-FLOUR', ItemRole::Raw);
    }

    private function metricsQuery(bool $manager = false, string $from = MenuFixture::FROM): MetricsQuery
    {
        return MetricsQuery::for($manager ? $this->fixture->managerA : $this->fixture->owner, ReportFilters::fromArray(
            ['preset' => 'custom', 'from' => $from, 'to' => MenuFixture::TO], CarbonImmutable::parse(MenuFixture::TO),
        ));
    }

    public function test_default_target_uses_best_companion_and_every_combo_has_one_main_and_safe_rounded_price(): void
    {
        $result = app(ComboBuilder::class)->proposeFor($this->metricsQuery(), $this->fixture->organization);
        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['combos']);
        $this->assertSame(['ZORBA-PLATE', 'LUMO-RICE'], array_column($result['combos'][0]['items'], 'key'));
        foreach ($result['combos'] as $combo) {
            $this->assertCount(1, array_filter($combo['items'], fn ($item) => $item['role'] === 'main'));
            foreach ($combo['items'] as $item) {
                $this->assertContains($item['role'], ['main', 'side', 'dessert']);
            }
            $this->assertGreaterThanOrEqual($result['margin_floor_pct'], $combo['margin_pct']);
            $this->assertSame(0.0, fmod($combo['suggested_price'], 5.0));
            $this->assertEqualsWithDelta(($combo['suggested_price'] - $combo['cost']) / $combo['suggested_price'], $combo['margin_pct'], 0.000001);
            $this->assertEqualsWithDelta(1 - $combo['suggested_price'] / $combo['list_price'], $combo['discount_pct'], 0.000001);
        }
        $this->assertSame(190.0, $result['combos'][0]['suggested_price']);
        $this->assertSame(225.0, $result['combos'][0]['list_price']);
        $this->assertSame(68.0, $result['combos'][0]['cost']);
    }

    public function test_unsafe_or_unknown_cost_combos_and_non_main_targets_are_dropped(): void
    {
        $builder = app(ComboBuilder::class);
        foreach (['TINY-SPRING', 'GLAZE-DIP', 'QUILL-STEW', 'FETA-TOWER', 'unseen'] as $key) {
            $this->assertSame([], $builder->proposeFor($this->metricsQuery(), $this->fixture->organization, $key)['combos']);
        }
        DB::table('agg_product_daily')->whereIn('product_ref', ['LUMO-RICE', 'PEXA-SALAD'])->update(['cogs' => DB::raw('net_sales * 5')]);
        $this->assertSame([], $builder->proposeFor($this->metricsQuery(), $this->fixture->organization)['combos']);
    }

    public function test_rounding_cannot_turn_an_acceptable_discount_into_a_below_floor_combo(): void
    {
        // Total 225: at 5% price 213.75 qualifies, rounded 210 does not. Other discounts fail.
        $floor = app(MenuEngineeringAnalysis::class)->run($this->metricsQuery(), $this->fixture->organization)['floors']['main'];
        $unitCost = (212 * (1 - $floor)) - 60;
        foreach (DB::table('agg_product_daily')->whereIn('product_ref', ['LUMO-RICE', 'PEXA-SALAD'])->get(['branch_id', 'business_date', 'product_uuid', 'qty_sold']) as $row) {
            DB::table('agg_product_daily')->where('branch_id', $row->branch_id)->where('business_date', $row->business_date)->where('product_uuid', $row->product_uuid)->update(['cogs' => (float) $row->qty_sold * $unitCost]);
        }
        $result = app(ComboBuilder::class)->proposeFor($this->metricsQuery(), $this->fixture->organization);
        foreach ($result['combos'] as $combo) {
            $this->assertNotContains('LUMO-RICE', array_column($combo['items'], 'key'));
            $this->assertGreaterThanOrEqual($floor, $combo['margin_pct']);
        }
    }

    public function test_untrusted_companions_are_excluded_and_manager_prices_do_not_use_other_branches(): void
    {
        ItemRoleAssignment::where('item_key', 'LUMO-RICE')->update(['source' => 'auto', 'confidence' => 0.5, 'classified_at' => now()]);
        DB::table('agg_product_daily')->where('branch_id', $this->fixture->branchB->id)->where('product_ref', 'ZORBA-PLATE')->update(['net_sales' => DB::raw('qty_sold * 9999')]);
        DB::table('dim_products')->where('branch_id', $this->fixture->branchB->id)->update(['name' => 'Private Branch Item']);
        $result = app(ComboBuilder::class)->proposeFor($this->metricsQuery(manager: true), $this->fixture->organization, 'ZORBA-PLATE', 1);
        $this->assertCount(1, $result['combos']);
        $this->assertNotContains('LUMO-RICE', array_column($result['combos'][0]['items'], 'key'));
        $this->assertSame(200.0, $result['combos'][0]['items'][0]['list_price']);
        $this->assertStringNotContainsString('Private Branch Item', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_smaller_discount_is_tried_when_rounding_breaks_the_largest_discount(): void
    {
        DB::table('agg_product_daily')->whereIn('product_ref', ['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL'])->update(['cogs' => DB::raw('net_sales * 0.75')]);
        DB::table('agg_product_daily')->where('product_ref', 'ZORBA-PLATE')->update(['net_sales' => DB::raw('qty_sold * 83'), 'cogs' => DB::raw('qty_sold * 62.25')]);
        DB::table('agg_product_daily')->where('product_ref', 'LUMO-RICE')->update(['net_sales' => DB::raw('qty_sold * 20'), 'cogs' => DB::raw('qty_sold * 2.75')]);
        $result = app(ComboBuilder::class)->proposeFor($this->metricsQuery(), $this->fixture->organization, 'ZORBA-PLATE');
        $this->assertEqualsWithDelta(0.25, $result['margin_floor_pct'], 0.000001);
        $this->assertNotEmpty($result['combos']);
        $this->assertSame(['ZORBA-PLATE', 'LUMO-RICE'], array_column($result['combos'][0]['items'], 'key'));
        $this->assertSame(103.0, $result['combos'][0]['list_price']);
        $this->assertSame(65.0, $result['combos'][0]['cost']);
        $this->assertSame(90.0, $result['combos'][0]['suggested_price']);
    }

    public function test_tool_has_summary_and_rejects_unsupported_channel_and_forbidden_branch(): void
    {
        $tool = new ComboBuilderTool($this->fixture->managerA);
        $args = ['preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO];
        $result = json_decode($tool->handle(new Request($args)), true);
        $this->assertSame('ok', $result['metrics']['status']);
        $this->assertNotEmpty($result['summary']);
        $this->assertLessThanOrEqual(16 * 1024, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
        $this->assertSame('unsupported_filter', json_decode($tool->handle(new Request($args + ['channel' => 'delivery'])), true)['error']);
        $this->assertSame('forbidden_branch', json_decode($tool->handle(new Request($args + ['branches' => [$this->fixture->branchB->id]])), true)['error']);
        $short = app(ComboBuilder::class)->proposeFor($this->metricsQuery(from: '2026-10-16'), $this->fixture->organization);
        $this->assertSame('insufficient_data', $short['status']);
        $this->assertNotEmpty($short['reason'] ?? null);
    }

    public function test_eval_combo_rule_grades_the_real_tool_payload(): void
    {
        $payload = (new ComboBuilderTool($this->fixture->owner))->handle(new Request(['preset' => 'custom', 'from' => MenuFixture::FROM, 'to' => MenuFixture::TO]));
        $decoded = json_decode($payload, true);
        $this->assertNotEmpty($decoded['metrics']['combos']);

        $grade = fn (string $result): array => app(EvalRunner::class)->gradeRules(
            rules: ['combos_one_main_above_floor'],
            calledTools: ['combo_builder'],
            delegatedAgents: [],
            toolResults: [['tool' => 'combo_builder', 'result' => $result]],
            replyText: '',
            grounding: new GroundingResult(grounded: true),
            organizationId: $this->fixture->organization->id,
        );
        $this->assertSame([], $grade($payload));

        $twoMains = $decoded;
        $twoMains['metrics']['combos'][0]['items'][1]['role'] = 'main';
        $this->assertSame(
            ['combos_one_main_above_floor' => 'Combo 1 has 2 main items.'],
            $grade(json_encode($twoMains, JSON_THROW_ON_ERROR)),
        );

        $raisedFloor = $decoded;
        $raisedFloor['metrics']['margin_floor_pct'] = 0.99;
        $this->assertArrayHasKey('combos_one_main_above_floor', $grade(json_encode($raisedFloor, JSON_THROW_ON_ERROR)));
    }
}
