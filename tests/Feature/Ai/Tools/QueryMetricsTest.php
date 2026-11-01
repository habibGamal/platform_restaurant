<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\Metrics\QueryMetrics;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class QueryMetricsTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_exact_numbers_for_net_sales_by_branch(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales'],
            'group_by' => ['branch'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('EGP', $result['currency']);
        $this->assertSame('2026-10-03', $result['period']['from']);
        $this->assertSame('2026-10-16', $result['period']['to']);

        $metrics = $result['metrics'];
        $this->assertSame('agg_branch_daily', $metrics['table']);
        $this->assertSame(['branch'], $metrics['group_by']);
        $this->assertSame(2, $metrics['row_count']);

        $rowsByBranch = [];
        foreach ($metrics['rows'] as $row) {
            $rowsByBranch[$row['branch_id']] = $row;
        }

        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;

        $this->assertArrayHasKey($branchAId, $rowsByBranch);
        $this->assertArrayHasKey($branchBId, $rowsByBranch);

        // Branch A: 14 days * 920.00 = 12880.00
        $this->assertSame('Branch A', $rowsByBranch[$branchAId]['branch_name']);
        $this->assertEquals(12880.00, $rowsByBranch[$branchAId]['net_sales']);

        // Branch B: 13 days * 320.00 = 4160.00
        $this->assertSame('Branch B', $rowsByBranch[$branchBId]['branch_name']);
        $this->assertEquals(4160.00, $rowsByBranch[$branchBId]['net_sales']);
    }

    public function test_exact_numbers_for_top_products_by_net_sales_with_limit(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales', 'qty_sold'],
            'group_by' => ['product'],
            'order_by' => 'net_sales',
            'order_direction' => 'desc',
            'limit' => 3,
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);

        $metrics = $result['metrics'];
        $this->assertSame('agg_product_daily', $metrics['table']);
        $this->assertCount(3, $metrics['rows']);
        $this->assertSame(3, $metrics['row_count']);

        $topRows = $metrics['rows'];

        // 1. BURGER-1: 56 on A (5600) + 13 on B (1300) = 69 sold, 6900.00 net sales
        $this->assertSame('Single Burger', $topRows[0]['product_name']);
        $this->assertSame('BURGER-1', $topRows[0]['product_ref']);
        $this->assertEquals(6900.00, $topRows[0]['net_sales']);
        $this->assertEquals(69.00, $topRows[0]['qty_sold']);

        // 2. BURGER-2: 28 on A (4200) + 13 on B (1950) = 41 sold, 6150.00 net sales
        $this->assertSame('Double Burger', $topRows[1]['product_name']);
        $this->assertSame('BURGER-2', $topRows[1]['product_ref']);
        $this->assertEquals(6150.00, $topRows[1]['net_sales']);
        $this->assertEquals(41.00, $topRows[1]['qty_sold']);

        // 3. SIDE-1: 56 on A (2240) + 13 on B (520) = 69 sold, 2760.00 net sales
        $this->assertSame('Fries', $topRows[2]['product_name']);
        $this->assertSame('SIDE-1', $topRows[2]['product_ref']);
        $this->assertEquals(2760.00, $topRows[2]['net_sales']);
        $this->assertEquals(69.00, $topRows[2]['qty_sold']);
    }

    public function test_exact_numbers_for_sales_by_weekday(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales'],
            'group_by' => ['weekday'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);

        $metrics = $result['metrics'];
        $this->assertSame('agg_branch_daily', $metrics['table']);
        $this->assertCount(7, $metrics['rows']);

        $weekdayRows = [];
        foreach ($metrics['rows'] as $r) {
            $weekdayRows[$r['weekday']] = $r;
        }

        // Saturday (0): A (2 * 920 = 1840) + B (1 * 320 = 320) = 2160.00
        $this->assertArrayHasKey(0, $weekdayRows);
        $this->assertSame('السبت', $weekdayRows[0]['weekday_name']);
        $this->assertEquals(2160.00, $weekdayRows[0]['net_sales']);

        // Sunday (1): A (2 * 920 = 1840) + B (2 * 320 = 640) = 2480.00
        $this->assertArrayHasKey(1, $weekdayRows);
        $this->assertSame('الأحد', $weekdayRows[1]['weekday_name']);
        $this->assertEquals(2480.00, $weekdayRows[1]['net_sales']);
    }

    public function test_exact_numbers_for_expenses_by_category(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['amount'],
            'group_by' => ['expense_category'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);

        $metrics = $result['metrics'];
        $this->assertSame('agg_expense_daily', $metrics['table']);

        $categoryMap = [];
        foreach ($metrics['rows'] as $r) {
            $categoryMap[$r['expense_category']] = $r['amount'];
        }

        // Salaries / labor: A (1000) + B (500) = 1500.00
        $this->assertArrayHasKey('labor', $categoryMap);
        $this->assertEquals(1500.00, $categoryMap['labor']);

        // Utilities / other: A (14 * 50 = 700) + B (13 * 20 = 260) = 960.00
        $this->assertArrayHasKey('other', $categoryMap);
        $this->assertEquals(960.00, $categoryMap['other']);
    }

    public function test_derived_ratio_gross_margin_computed_from_sums_not_averaged(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['gross_margin', 'gross_profit', 'sales'],
            'group_by' => ['branch'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);

        $rowsByBranch = [];
        foreach ($result['metrics']['rows'] as $r) {
            $rowsByBranch[$r['branch_id']] = $r;
        }

        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;

        // Branch A: gross_profit = 7780.00, net_sales_after_returns = 12780.00
        // Ratio = 7780 / 12780 = 0.60876... -> 0.6088
        $this->assertEquals(7780.00, $rowsByBranch[$branchAId]['gross_profit']);
        $this->assertEquals(12780.00, $rowsByBranch[$branchAId]['sales']);
        $this->assertEquals(0.6088, $rowsByBranch[$branchAId]['gross_margin']);

        // Branch B: gross_profit = 2535.00, net_sales_after_returns = 4160.00
        // Ratio = 2535 / 4160 = 0.609375... -> 0.6094
        $this->assertEquals(2535.00, $rowsByBranch[$branchBId]['gross_profit']);
        $this->assertEquals(4160.00, $rowsByBranch[$branchBId]['sales']);
        $this->assertEquals(0.6094, $rowsByBranch[$branchBId]['gross_margin']);
    }

    public function test_unknown_measure_returns_validation_error(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['non_existent_fake_metric'],
            'group_by' => ['branch'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('validation_error', $result['error']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('measures.0', $result['errors']);
    }

    public function test_unsupported_combination_returns_structured_error(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        // Combining product measure (qty_sold) with hourly dimension (hour)
        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['qty_sold'],
            'group_by' => ['hour'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('unsupported_combination', $result['error']);
        $this->assertArrayHasKey('supported', $result);
        $this->assertIsArray($result['supported']);
        $this->assertNotEmpty($result['supported']);
    }

    public function test_unsupported_group_by_dimension_channel_returns_structured_error(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        // Group by channel is a recognized whitelist dimension, but unsupported by any agg table
        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales'],
            'group_by' => ['channel'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('unsupported_combination', $result['error']);
        $this->assertArrayHasKey('supported', $result);
    }

    public function test_branch_manager_a_cannot_see_branch_b_rows(): void
    {
        $tool = new QueryMetrics($this->fixture->managerA);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales'],
            'group_by' => ['branch'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);

        $rows = $result['metrics']['rows'];
        $this->assertCount(1, $rows);

        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;

        $this->assertEquals($branchAId, $rows[0]['branch_id']);
        $this->assertNotEquals($branchBId, $rows[0]['branch_id']);
        $this->assertEquals(12880.00, $rows[0]['net_sales']);
    }

    public function test_branch_manager_a_explicitly_requesting_branch_b_returns_forbidden_branch(): void
    {
        $tool = new QueryMetrics($this->fixture->managerA);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'branches' => [$this->fixture->branchB->id],
            'measures' => ['net_sales'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('forbidden_branch', $result['error']);
    }

    public function test_sql_injection_style_input_in_group_by_rejected(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales'],
            'group_by' => ['branch; DROP TABLE users;--'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('validation_error', $result['error']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('group_by.0', $result['errors']);
    }

    public function test_sql_injection_style_input_in_order_by_rejected(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales'],
            'order_by' => 'net_sales; DROP TABLE users;--',
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('validation_error', $result['error']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('order_by', $result['errors']);
    }

    public function test_limit_cap_enforced(): void
    {
        $tool = new QueryMetrics($this->fixture->owner);

        // limit > 50 should fail validation
        $requestOverCap = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales'],
            'group_by' => ['product'],
            'limit' => 100,
        ]);

        $rawResult = $tool->handle($requestOverCap);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('validation_error', $result['error']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('limit', $result['errors']);

        // valid limit within 1-50 returns expected number of rows
        $requestValid = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'measures' => ['net_sales'],
            'group_by' => ['product'],
            'limit' => 2,
        ]);

        $rawResultValid = $tool->handle($requestValid);
        $resultValid = json_decode($rawResultValid, true);

        $this->assertIsArray($resultValid);
        $this->assertArrayNotHasKey('error', $resultValid);
        $this->assertCount(2, $resultValid['metrics']['rows']);
        $this->assertSame(2, $resultValid['metrics']['row_count']);
    }
}
