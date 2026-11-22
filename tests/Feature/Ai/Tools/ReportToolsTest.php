<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\Contracts\ReportsProgress;
use App\Ai\Tools\Finance\ExpensesPurchasesTool;
use App\Ai\Tools\Finance\FoodCostWasteTool;
use App\Ai\Tools\Finance\ProfitabilityTool;
use App\Ai\Tools\Finance\ReportTool;
use App\Ai\Tools\Finance\SalesComparisonTool;
use App\Ai\Tools\Finance\SalesForecastTool;
use App\Ai\Tools\Finance\TargetsTool;
use App\Ai\Tools\Operations\BranchRankingTool;
use App\Ai\Tools\Operations\DataHealthTool;
use App\Ai\Tools\Operations\OverviewTool;
use App\Ai\Tools\Operations\PeakHoursTool;
use App\Ai\Tools\Operations\ShiftsWeekdaysTool;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ReportToolsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string<ReportTool>, string}> */
    public static function tools(): array
    {
        return [
            'forecast' => [SalesForecastTool::class, 'sales_forecast'],
            'profitability' => [ProfitabilityTool::class, 'profitability'],
            'food cost' => [FoodCostWasteTool::class, 'food_cost_waste'],
            'expenses' => [ExpensesPurchasesTool::class, 'expenses_purchases'],
            'sales comparison' => [SalesComparisonTool::class, 'sales_comparison'],
            'targets' => [TargetsTool::class, 'targets_vs_actual'],
            'ranking' => [BranchRankingTool::class, 'branch_ranking'],
            'shifts' => [ShiftsWeekdaysTool::class, 'shifts_weekdays'],
            'overview' => [OverviewTool::class, 'overview_report'],
            'peak hours' => [PeakHoursTool::class, 'peak_hours'],
            'data health' => [DataHealthTool::class, 'data_health'],
        ];
    }

    /** @param class-string<ReportTool> $class */
    #[DataProvider('tools')]
    public function test_tools_are_scoped_bounded_json_reports_with_summaries(string $class, string $name): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $fixture = TwoBranchesTwoWeeks::create();
        $tool = new $class($fixture->managerA);
        $raw = $tool->handle(new Request($this->filters()));
        $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertInstanceOf(ReportsProgress::class, $tool);
        $this->assertSame($name, $tool->name());
        $this->assertNotEmpty($tool->progressLabel());
        $this->assertArrayNotHasKey('error', $result, $raw);
        $this->assertNotEmpty($result['summary']);
        $this->assertEquals([$fixture->branchA->id], array_keys($result['branches']));
        $this->assertLessThanOrEqual(16384, strlen($raw));
        $this->assertBoundedLists($result['metrics']);
        $this->assertScopedRows($result['metrics'], $fixture->branchA->id);

        $denied = json_decode($tool->handle(new Request($this->filters() + ['branches' => [$fixture->branchB->id]])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('forbidden_branch', $denied['error']);
        $unsupported = json_decode($tool->handle(new Request($this->filters() + ['channel' => 'delivery'])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('unsupported_filter', $unsupported['error']);
    }

    public function test_explicit_branch_selection_changes_the_actual_report_values(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $fixture = TwoBranchesTwoWeeks::create();
        $tool = new OverviewTool($fixture->owner);
        $result = json_decode($tool->handle(new Request($this->filters() + ['branches' => [$fixture->branchA->id]])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(12780, $result['metrics']['overview']['sales']['value']);
        $this->assertCount(1, $result['metrics']['branches']);
    }

    public function test_cost_tools_define_the_inventory_variance_sign(): void
    {
        // A negative variance cost is a stock surplus that raises profit; models kept calling it a shortage.
        $this->seed(RolesAndPermissionsSeeder::class);
        $fixture = TwoBranchesTwoWeeks::create();
        foreach ([ProfitabilityTool::class => 'inventoryVarianceCost', FoodCostWasteTool::class => 'inventoryVarianceCost', OverviewTool::class => 'inventory_variance'] as $class => $field) {
            $result = json_decode((new $class($fixture->owner))->handle(new Request($this->filters())), true, flags: JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('surplus', $result['definitions'][$field] ?? '', $class);
        }
    }

    public function test_custom_dates_must_be_present_and_ordered(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $fixture = TwoBranchesTwoWeeks::create();
        foreach ([['preset' => 'custom'], ['preset' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-03']] as $args) {
            $result = json_decode((new OverviewTool($fixture->owner))->handle(new Request($args)), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('validation_error', $result['error']);
        }
    }

    /** @param class-string<ReportTool> $class */
    #[DataProvider('tools')]
    public function test_branch_names_are_anonymized_everywhere_for_a_restricted_multi_branch_viewer(string $class, string $name): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $fixture = TwoBranchesTwoWeeks::create();
        $viewer = User::factory()->create(['organization_id' => $fixture->organization->id]);
        $viewer->assignRole('branch_manager');
        $viewer->branches()->attach([$fixture->branchA->id, $fixture->branchB->id]);
        $raw = (new $class($viewer))->handle(new Request($this->filters()));
        $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('error', $result, $name.': '.$raw);
        $this->assertStringNotContainsString($fixture->branchA->name, $raw);
        $this->assertStringNotContainsString($fixture->branchB->name, $raw);
        $this->assertContains('فرع 1', $result['branches']);
        $this->assertContains('فرع 2', $result['branches']);
    }

    public function test_health_report_keeps_real_rejection_timestamps(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $fixture = TwoBranchesTwoWeeks::create();
        DB::table('ingest_rejections')->insert([
            'organization_id' => $fixture->organization->id, 'branch_id' => $fixture->branchA->id,
            'doc_type' => 'order', 'reason' => 'invalid payload', 'created_at' => '2026-10-10 12:34:56', 'updated_at' => '2026-10-10 12:34:56',
        ]);
        $result = json_decode((new DataHealthTool($fixture->managerA))->handle(new Request($this->filters())), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsString($result['metrics']['rejections'][0]['firstSeenAt']);
        $this->assertStringContainsString('2026-10-10', $result['metrics']['rejections'][0]['firstSeenAt']);
        $this->assertSame(1, $result['metrics']['rejections'][0]['count']);
    }

    public function test_keyed_branch_matrix_rows_are_capped_without_losing_row_fields(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $fixture = TwoBranchesTwoWeeks::create();
        Branch::factory()->count(10)->create([
            'organization_id' => $fixture->organization->id, 'restaurant_id' => $fixture->restaurant->id,
        ]);
        $result = json_decode((new FoodCostWasteTool($fixture->owner))->handle(new Request($this->filters())), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(10, $result['metrics']['branches']);
        $this->assertArrayHasKey('netSalesAfterReturns', $result['metrics']['branches'][0]);
        $this->assertArrayHasKey('targetBandStatus', $result['metrics']['branches'][0]);
        $this->assertBoundedLists($result['metrics']);
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return ['preset' => 'custom', 'from' => '2026-10-03', 'to' => '2026-10-16', 'compare' => 'none'];
    }

    private function assertBoundedLists(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        if (array_is_list($value)) {
            $this->assertLessThanOrEqual(10, count($value));
        }
        foreach ($value as $child) {
            $this->assertBoundedLists($child);
        }
    }

    private function assertScopedRows(mixed $value, int $branchId): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach (['branchId', 'branch_id'] as $key) {
            if (isset($value[$key]) && $value[$key] !== 0) {
                $this->assertSame($branchId, $value[$key]);
            }
        }
        foreach ($value as $child) {
            $this->assertScopedRows($child, $branchId);
        }
    }
}
