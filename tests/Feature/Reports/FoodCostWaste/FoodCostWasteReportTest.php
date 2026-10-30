<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\FoodCostWaste;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\FoodCostWasteReport;
use App\Models\Warehouse\WhPurchase;
use App\Models\Warehouse\WhPurchaseItem;
use App\Models\Warehouse\WhStocktaking;
use App\Models\Warehouse\WhStocktakingItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class FoodCostWasteReportTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo'));
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    /**
     * @param  array<int>|null  $branchIds
     */
    private function makeFilters(
        string $from = '2026-10-03',
        string $to = '2026-10-16',
        ?string $compFrom = null,
        ?string $compTo = null,
        ?array $branchIds = null,
    ): ReportFilters {
        $period = new Period(
            from: CarbonImmutable::parse($from, 'Africa/Cairo'),
            to: CarbonImmutable::parse($to, 'Africa/Cairo'),
        );

        $comparison = null;
        $comparisonMode = ComparisonMode::none;
        if ($compFrom !== null && $compTo !== null) {
            $comparison = new Period(
                from: CarbonImmutable::parse($compFrom, 'Africa/Cairo'),
                to: CarbonImmutable::parse($compTo, 'Africa/Cairo'),
            );
            $comparisonMode = ComparisonMode::previous;
        }

        return new ReportFilters(
            preset: PeriodPreset::custom,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: $branchIds,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::auto($period),
        );
    }

    public function test_branch_matrix_kpi_values_match_expected_fixture(): void
    {
        $filters = $this->makeFilters('2026-10-03', '2026-10-16');
        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = FoodCostWasteReport::for($query);

        $matrix = $report->branchMatrix();

        // Branch A asserts (K16, K17, K13)
        $branchA = $matrix[$this->fixture->branchA->id];
        $this->assertEquals(12780.00, $branchA->netSalesAfterReturns);
        // Gross COGS (5040) - returned COGS recovered (40) = 5000.00
        $this->assertEquals(5000.00, $branchA->cogs);
        // K16 Food Cost % = 5000 / 12780 = ~0.3912
        $this->assertEqualsWithDelta(0.3912, $branchA->foodCostPct, 0.001);
        // K12 Waste = 50.00
        $this->assertEquals(50.00, $branchA->wasteCost);
        // K17 Waste % = 50 / 12780 = ~0.0039
        $this->assertEqualsWithDelta(0.0039, $branchA->wastePct, 0.0005);
        // Waste share of usage = 50 / (5040 + 50) = 50 / 5090 = ~0.0098
        $this->assertEqualsWithDelta(0.0098, $branchA->wasteShareOfUsage, 0.0005);
        // K13 Inventory variance = 80.00
        $this->assertEquals(80.00, $branchA->inventoryVarianceCost);
        // Total loss = 50 + 80 = 130.00 (130 / 12780 = ~0.0102)
        $this->assertEquals(130.00, $branchA->totalLossCost);
        $this->assertEqualsWithDelta(0.0102, $branchA->totalLossPct, 0.0005);
        $this->assertTrue($branchA->wasteRecorded);
        $this->assertEquals('above_band', $branchA->targetBandStatus);
        $this->assertEquals('danger', $branchA->targetBandColor);

        // Branch B asserts
        $branchB = $matrix[$this->fixture->branchB->id];
        $this->assertEquals(4160.00, $branchB->netSalesAfterReturns);
        $this->assertEquals(1625.00, $branchB->cogs);
        // K16 Food Cost % = 1625 / 4160 = 0.390625
        $this->assertEqualsWithDelta(0.3906, $branchB->foodCostPct, 0.001);
        // K12 Waste = 30.00
        $this->assertEquals(30.00, $branchB->wasteCost);
        // K17 Waste % = 30 / 4160 = ~0.0072
        $this->assertEqualsWithDelta(0.0072, $branchB->wastePct, 0.0005);
        // K13 Inventory variance = 0.00
        $this->assertEquals(0.00, $branchB->inventoryVarianceCost);
        $this->assertEquals(30.00, $branchB->totalLossCost);
        $this->assertEqualsWithDelta(0.0072, $branchB->totalLossPct, 0.0005);
        $this->assertTrue($branchB->wasteRecorded);
        $this->assertEquals('above_band', $branchB->targetBandStatus);
        $this->assertEquals('danger', $branchB->targetBandColor);
    }

    public function test_kpi_overview_totals_and_group_benchmark(): void
    {
        $filters = $this->makeFilters('2026-10-03', '2026-10-16');
        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = FoodCostWasteReport::for($query);

        $overview = $report->kpiOverview();
        // Combined food cost % = 6625 / 16940 = ~0.3911
        $this->assertEqualsWithDelta(0.3911, $overview->foodCostPct, 0.001);
        $this->assertEquals(80.00, $overview->wasteCost);
        // Combined waste % = 80 / 16940 = ~0.0047
        $this->assertEqualsWithDelta(0.0047, $overview->wastePct, 0.0005);
        $this->assertEquals(80.00, $overview->inventoryVarianceCost);
        $this->assertEquals(160.00, $overview->totalLossCost);
        $this->assertEqualsWithDelta(0.0094, $overview->totalLossPct, 0.0005);

        // Group benchmark
        $group = $report->groupBenchmark();
        $this->assertNotNull($group);
        $this->assertEquals('متوسط المجموعة', $group->branchName);
        $this->assertEqualsWithDelta(0.3911, $group->foodCostPct, 0.001);

        // Manager A cannot see group benchmark
        $managerQuery = MetricsQuery::for($this->fixture->managerA, $filters);
        $managerReport = FoodCostWasteReport::for($managerQuery);
        $this->assertNull($managerReport->groupBenchmark());
    }

    public function test_top_wasted_items_ordered_by_cost(): void
    {
        $filters = $this->makeFilters('2026-10-03', '2026-10-16');
        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = FoodCostWasteReport::for($query);

        $wasted = $report->topWastedItems();

        $this->assertCount(2, $wasted);
        // 1st item: Branch A waste (50.00)
        $this->assertEquals($this->fixture->branchA->id, $wasted[0]->branchId);
        $this->assertEquals(50.00, $wasted[0]->wasteCost);
        $this->assertEquals(1.0, $wasted[0]->wasteQty);
        $this->assertEquals(1.0, $wasted[0]->wasteShareOfBranch); // 50 / 50 = 100%

        // 2nd item: Branch B waste (30.00)
        $this->assertEquals($this->fixture->branchB->id, $wasted[1]->branchId);
        $this->assertEquals(30.00, $wasted[1]->wasteCost);
        $this->assertEquals(1.0, $wasted[1]->wasteQty);
        $this->assertEquals(1.0, $wasted[1]->wasteShareOfBranch); // 30 / 30 = 100%
    }

    public function test_stocktaking_variances_returns_latest_stocktaking_per_branch(): void
    {
        $filters = $this->makeFilters('2026-10-03', '2026-10-16');
        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = FoodCostWasteReport::for($query);

        $variances = $report->stocktakingVariances();

        // Fixture only has stocktaking on Branch A
        $this->assertCount(1, $variances);
        $varA = $variances[0];
        $this->assertEquals($this->fixture->branchA->id, $varA->branchId);
        $this->assertEquals('2026-10-08', $varA->businessDate);
        $this->assertEquals(10.0, $varA->stockQuantity);
        $this->assertEquals(8.0, $varA->realQuantity);
        $this->assertEquals(2.0, $varA->shortageQuantity);
        $this->assertEquals(40.00, $varA->price);
        $this->assertEquals(80.00, $varA->shortageValue);
    }

    public function test_purchase_price_movement_shows_only_products_present_in_both_periods(): void
    {
        // Comparison period (2026-10-04) already has BURGER-1 in fixture.
        // Add a current period purchase for BURGER-1 on Branch A:
        $pUuid = 'purch-a-test-movement';
        WhPurchase::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => $pUuid,
            'source_version' => 1,
            'business_date' => '2026-10-12',
            'kind' => 'invoice',
            'supplier_uuid' => "sup-1-{$this->fixture->branchA->id}",
            'total' => 600.00,
        ]);
        WhPurchaseItem::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'purchase_uuid' => $pUuid,
            'product_uuid' => "prod-{$this->fixture->branchA->id}-1", // BURGER-1
            'quantity' => 10,
            'price' => 60.00,
            'total' => 600.00,
        ]);

        // Also add a current-only purchase for DRINK-1:
        $pDrinkUuid = 'purch-a-test-drink';
        WhPurchase::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => $pDrinkUuid,
            'source_version' => 1,
            'business_date' => '2026-10-12',
            'kind' => 'invoice',
            'supplier_uuid' => "sup-1-{$this->fixture->branchA->id}",
            'total' => 100.00,
        ]);
        WhPurchaseItem::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'purchase_uuid' => $pDrinkUuid,
            'product_uuid' => "prod-{$this->fixture->branchA->id}-5", // DRINK-1
            'quantity' => 10,
            'price' => 10.00,
            'total' => 100.00,
        ]);

        $filters = $this->makeFilters(
            from: '2026-10-10',
            to: '2026-10-16',
            compFrom: '2026-10-03',
            compTo: '2026-10-09',
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = FoodCostWasteReport::for($query);

        $movement = $report->purchasePriceMovement();

        // BURGER-1 was present in both periods. DRINK-1 was only present in current period.
        // Spec acceptance criterion: shows ONLY products present in BOTH periods.
        $this->assertCount(1, $movement);
        $this->assertEquals('BURGER-1', $movement[0]->productRef);
        $this->assertEquals(60.00, $movement[0]->currentAvgPrice);
        $this->assertEquals(50.00, $movement[0]->comparisonAvgPrice);
        // Price rose from 50 to 60 -> +20%
        $this->assertEqualsWithDelta(0.20, $movement[0]->priceChangePct, 0.001);
    }

    public function test_soft_deleted_stocktaking_is_ignored_by_stocktaking_variances(): void
    {
        $filters = $this->makeFilters('2026-10-03', '2026-10-16');
        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = FoodCostWasteReport::for($query);

        // Add a newer stocktaking on 2026-10-14 that is soft-deleted
        $deletedStocktakingUuid = 'stocktaking-deleted-test';
        WhStocktaking::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => $deletedStocktakingUuid,
            'source_version' => 1,
            'business_date' => '2026-10-14',
            'total' => 500.00,
            'variance_cost' => 500.00,
            'deleted_at' => '2026-10-14 18:00:00',
        ]);
        WhStocktakingItem::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'stocktaking_uuid' => $deletedStocktakingUuid,
            'product_uuid' => "prod-{$this->fixture->branchA->id}-1",
            'stock_quantity' => 20.0,
            'real_quantity' => 10.0,
            'price' => 50.00,
            'total' => 500.00,
        ]);

        $variances = $report->stocktakingVariances();

        // Must still return the active stocktaking from 2026-10-08, ignoring the soft-deleted one from 2026-10-14
        $this->assertCount(1, $variances);
        $this->assertEquals('2026-10-08', $variances[0]->businessDate);
        $this->assertEquals(80.00, $variances[0]->shortageValue);
        $this->assertNotEquals($deletedStocktakingUuid, $variances[0]->stocktakingUuid);
    }

    public function test_soft_deleted_purchases_are_ignored_by_purchase_price_movement(): void
    {
        // Add active purchase in current period (2026-10-12) for BURGER-1: 10 units @ 60 = 600
        $pUuid = 'purch-a-active-test';
        WhPurchase::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => $pUuid,
            'source_version' => 1,
            'business_date' => '2026-10-12',
            'kind' => 'invoice',
            'supplier_uuid' => "sup-1-{$this->fixture->branchA->id}",
            'total' => 600.00,
        ]);
        WhPurchaseItem::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'purchase_uuid' => $pUuid,
            'product_uuid' => "prod-{$this->fixture->branchA->id}-1",
            'quantity' => 10,
            'price' => 60.00,
            'total' => 600.00,
        ]);

        // Add soft-deleted purchase with a very high price that would skew average if not ignored
        $pDeletedUuid = 'purch-a-deleted-test';
        WhPurchase::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => $pDeletedUuid,
            'source_version' => 1,
            'business_date' => '2026-10-13',
            'kind' => 'invoice',
            'supplier_uuid' => "sup-1-{$this->fixture->branchA->id}",
            'total' => 5000.00,
            'deleted_at' => '2026-10-13 15:00:00',
        ]);
        WhPurchaseItem::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'purchase_uuid' => $pDeletedUuid,
            'product_uuid' => "prod-{$this->fixture->branchA->id}-1",
            'quantity' => 10,
            'price' => 500.00,
            'total' => 5000.00,
        ]);

        $filters = $this->makeFilters(
            from: '2026-10-10',
            to: '2026-10-16',
            compFrom: '2026-10-03',
            compTo: '2026-10-09',
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = FoodCostWasteReport::for($query);
        $movement = $report->purchasePriceMovement();

        $burgerMovement = collect($movement)->firstWhere('productRef', 'BURGER-1');
        $this->assertNotNull($burgerMovement);
        // Average price should be exactly 60.00 (from the active purchase), NOT (600 + 5000)/20 = 280
        $this->assertEquals(60.00, $burgerMovement->currentAvgPrice);
    }
}
