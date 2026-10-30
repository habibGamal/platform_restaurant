<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\BranchRanking;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\BranchRankingReport;
use App\Models\Branch;
use App\Models\ScoringProfile;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class BranchRankingReportTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    private CarbonImmutable $frozenNow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenNow = CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo');
        CarbonImmutable::setTestNow($this->frozenNow);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_percentile_scoring_formula_for_three_or_more_branches(): void
    {
        // 4 distinct values (higher is better)
        $values = [1 => 10.0, 2 => 20.0, 3 => 30.0, 4 => 40.0];
        $scores = BranchRankingReport::computePercentileScores($values, 'higher');

        $this->assertEquals(0.0, $scores[1]);
        $this->assertEquals(33.33, $scores[2]);
        $this->assertEquals(66.67, $scores[3]);
        $this->assertEquals(100.0, $scores[4]);

        // 4 distinct values (lower is better)
        $scoresLower = BranchRankingReport::computePercentileScores($values, 'lower');
        $this->assertEquals(100.0, $scoresLower[1]);
        $this->assertEquals(66.67, $scoresLower[2]);
        $this->assertEquals(33.33, $scoresLower[3]);
        $this->assertEquals(0.0, $scoresLower[4]);

        // Ties
        $tiedValues = [1 => 10.0, 2 => 20.0, 3 => 20.0, 4 => 30.0];
        $tiedScores = BranchRankingReport::computePercentileScores($tiedValues, 'higher');
        $this->assertEquals(0.0, $tiedScores[1]);
        $this->assertEquals(50.0, $tiedScores[2]);
        $this->assertEquals(50.0, $tiedScores[3]);
        $this->assertEquals(100.0, $tiedScores[4]);
    }

    public function test_min_max_scaling_for_fewer_than_three_branches(): void
    {
        $values = [1 => 20.0, 2 => 80.0];

        $higher = BranchRankingReport::computePercentileScores($values, 'higher');
        $this->assertEquals(0.0, $higher[1]);
        $this->assertEquals(100.0, $higher[2]);

        $lower = BranchRankingReport::computePercentileScores($values, 'lower');
        $this->assertEquals(100.0, $lower[1]);
        $this->assertEquals(0.0, $lower[2]);
    }

    public function test_rank_branches_with_ties_skips_next_rank(): void
    {
        $scores = [
            1 => 95.0,
            2 => 82.0,
            3 => 82.0,
            4 => 70.0,
        ];

        $ranks = BranchRankingReport::rankBranches($scores);

        $this->assertEquals(1, $ranks[1]);
        $this->assertEquals(2, $ranks[2]);
        $this->assertEquals(2, $ranks[3]);
        $this->assertEquals(4, $ranks[4]); // rank 3 skipped!
    }

    public function test_acceptance_criterion_fixture_with_two_branches_plus_two_synthetic_matches_hand_computed_values(): void
    {
        // Week 1: 2026-10-03 to 2026-10-09 (7 days)
        // Both Branch A and B are complete in this period
        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;
        $orgId = $this->fixture->organization->id;
        $restId = $this->fixture->restaurant->id;

        // Create 2 synthetic branches C & D
        $branchC = Branch::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'restaurant_id' => $restId,
            'name' => 'فرع النزهة (C)',
            'code' => 'BR-C',
            'status' => 'active',
            'opened_on' => '2026-01-01',
            'timezone' => 'Africa/Cairo',
        ]);

        $branchD = Branch::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'restaurant_id' => $restId,
            'name' => 'فرع الدقي (D)',
            'code' => 'BR-D',
            'status' => 'active',
            'opened_on' => '2026-01-01',
            'timezone' => 'Africa/Cairo',
        ]);

        // Attach C & D to owner
        DB::table('user_branch_access')->insert([
            ['user_id' => $this->fixture->owner->id, 'branch_id' => $branchC->id],
            ['user_id' => $this->fixture->owner->id, 'branch_id' => $branchD->id],
        ]);

        // Insert complete rows for C and D in Week 1 (2026-10-03 to 2026-10-09)
        // Branch C: high margin (45%), low food cost (25%), 0 waste, 0 cancel
        // Branch D: low margin (15%), high food cost (45%), high waste (5%), high cancel (10%)
        $dates = [
            '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06',
            '2026-10-07', '2026-10-08', '2026-10-09',
        ];

        foreach ($dates as $date) {
            DB::table('agg_branch_daily')->insert([
                [
                    'organization_id' => $orgId,
                    'restaurant_id' => $restId,
                    'branch_id' => $branchC->id,
                    'business_date' => $date,
                    'weekday' => 0,
                    'orders_count' => 10,
                    'cancelled_count' => 0,
                    'gross_sales' => 1000.0,
                    'discounts' => 0.0,
                    'tax' => 0.0,
                    'service' => 0.0,
                    'net_sales' => 1000.0,
                    'returns_amount' => 0.0,
                    'net_sales_after_returns' => 1000.0,
                    'cogs' => 250.0,
                    'returned_cogs_recovered' => 0.0,
                    'gross_profit' => 750.0,
                    'expenses_total' => 300.0,
                    'labor_cost' => 150.0,
                    'rent_cost' => 0.0,
                    'other_expenses' => 150.0,
                    'waste_cost' => 0.0,
                    'inventory_variance_cost' => 0.0,
                    'purchases_total' => 0.0,
                    'purchase_returns_total' => 0.0,
                    'net_profit' => 450.0, // 450 / 1000 = 45% net margin
                    'shifts_count' => 1,
                    'cash_deficit_total' => 0.0,
                    'is_day_closed' => 1,
                    'is_reconciled' => 1,
                ],
                [
                    'organization_id' => $orgId,
                    'restaurant_id' => $restId,
                    'branch_id' => $branchD->id,
                    'business_date' => $date,
                    'weekday' => 0,
                    'orders_count' => 9,
                    'cancelled_count' => 1, // 1 / 10 = 10% cancel rate
                    'gross_sales' => 1000.0,
                    'discounts' => 0.0,
                    'tax' => 0.0,
                    'service' => 0.0,
                    'net_sales' => 1000.0,
                    'returns_amount' => 0.0,
                    'net_sales_after_returns' => 1000.0,
                    'cogs' => 450.0,
                    'returned_cogs_recovered' => 0.0,
                    'gross_profit' => 550.0,
                    'expenses_total' => 350.0,
                    'labor_cost' => 150.0,
                    'rent_cost' => 0.0,
                    'other_expenses' => 200.0,
                    'waste_cost' => 50.0, // 50 / 1000 = 5% waste
                    'inventory_variance_cost' => 0.0,
                    'purchases_total' => 0.0,
                    'purchase_returns_total' => 0.0,
                    'net_profit' => 150.0, // 150 / 1000 = 15% net margin
                    'shifts_count' => 1,
                    'cash_deficit_total' => 0.0,
                    'is_day_closed' => 1,
                    'is_reconciled' => 1,
                ],
            ]);
        }

        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
            'branches' => [$branchAId, $branchBId, $branchC->id, $branchD->id],
        ], CarbonImmutable::parse('2026-10-17 12:00:00'), '12:00');

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = BranchRankingReport::for($query);
        $data = $report->data();

        // 4 branches are complete
        $this->assertCount(4, $data->rankedBranches);
        $this->assertCount(0, $data->excludedBranches);

        $rowMap = [];
        foreach ($data->rankedBranches as $r) {
            $rowMap[$r->branchId] = $r;
        }

        // Branch C has best net margin (45.0%), food cost (25.0%), waste (0.0%), cancel (0.0%)
        // Assert Branch C is ranked #1!
        $this->assertEquals(1, $rowMap[$branchC->id]->rank);
        $this->assertGreaterThan(80.0, $rowMap[$branchC->id]->overallScore);

        // Branch D has worst net margin (15.0%), food cost (45.0%), waste (5.0%), cancel (10.0%)
        // Assert Branch D is ranked #4!
        $this->assertEquals(4, $rowMap[$branchD->id]->rank);
        $this->assertLessThan(40.0, $rowMap[$branchD->id]->overallScore);

        // Hand-computed Net Margins:
        // C = 45.0%, A = 37.54%, B = 32.37%, D = 15.0%
        // Percentile scores for Net Margin (higher is better):
        // D (15%) = 0.0, B (32.37%) = 33.33, A (37.54%) = 66.67, C (45%) = 100.0
        $this->assertEquals(100.0, $rowMap[$branchC->id]->kpiScores['net_margin']->score);
        $this->assertEquals(66.67, $rowMap[$branchAId]->kpiScores['net_margin']->score);
        $this->assertEquals(33.33, $rowMap[$branchBId]->kpiScores['net_margin']->score);
        $this->assertEquals(0.0, $rowMap[$branchD->id]->kpiScores['net_margin']->score);

        // Hand-computed Waste %:
        // B = 0.0%, C = 0.0%, A = 0.79% (50 / 6340), D = 5.0%
        // Lower is better: B and C are tied for best!
        // Worse than B & C: A and D (2 branches).
        // Tied with B & C: 2 branches (B & C).
        // Score for B & C = (2 + 0.5 * 1) / 3 * 100 = 83.33!
        // A has 1 branch worse (D): (1 + 0.5 * 0) / 3 * 100 = 33.33!
        // D has 0 branches worse: (0 + 0) / 3 * 100 = 0.0!
        $this->assertEquals(83.33, $rowMap[$branchC->id]->kpiScores['waste_pct']->score);
        $this->assertEquals(83.33, $rowMap[$branchBId]->kpiScores['waste_pct']->score);
        $this->assertEquals(33.33, $rowMap[$branchAId]->kpiScores['waste_pct']->score);
        $this->assertEquals(0.0, $rowMap[$branchD->id]->kpiScores['waste_pct']->score);
    }

    public function test_changing_scoring_weights_re_ranks_deterministically(): void
    {
        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;
        $orgId = $this->fixture->organization->id;
        $restId = $this->fixture->restaurant->id;

        // Branch C
        $branchC = Branch::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'restaurant_id' => $restId,
            'name' => 'فرع النزهة (C)',
            'code' => 'BR-C2',
            'status' => 'active',
            'opened_on' => '2026-01-01',
            'timezone' => 'Africa/Cairo',
        ]);

        DB::table('user_branch_access')->insert([
            ['user_id' => $this->fixture->owner->id, 'branch_id' => $branchC->id],
        ]);

        $dates = [
            '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06',
            '2026-10-07', '2026-10-08', '2026-10-09',
        ];

        foreach ($dates as $date) {
            DB::table('agg_branch_daily')->insert([
                'organization_id' => $orgId,
                'restaurant_id' => $restId,
                'branch_id' => $branchC->id,
                'business_date' => $date,
                'weekday' => 0,
                'orders_count' => 10,
                'cancelled_count' => 0,
                'gross_sales' => 1000.0,
                'discounts' => 0.0,
                'tax' => 0.0,
                'service' => 0.0,
                'net_sales' => 1000.0,
                'returns_amount' => 0.0,
                'net_sales_after_returns' => 1000.0,
                'cogs' => 200.0,
                'returned_cogs_recovered' => 0.0,
                'gross_profit' => 800.0,
                'expenses_total' => 200.0,
                'labor_cost' => 100.0,
                'rent_cost' => 0.0,
                'other_expenses' => 100.0,
                'waste_cost' => 0.0,
                'inventory_variance_cost' => 0.0,
                'purchases_total' => 0.0,
                'purchase_returns_total' => 0.0,
                'net_profit' => 600.0,
                'shifts_count' => 1,
                'cash_deficit_total' => 0.0,
                'is_day_closed' => 1,
                'is_reconciled' => 1,
            ]);
        }

        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
            'branches' => [$branchAId, $branchBId, $branchC->id],
        ], CarbonImmutable::parse('2026-10-17 12:00:00'), '12:00');

        $query = MetricsQuery::for($this->fixture->owner, $filters);

        // 1. Default profile
        $report1 = BranchRankingReport::for($query);
        $scoreA1 = $report1->data()->rankedBranches[1]->overallScore;

        // 2. Custom profile: set net_margin weight to 70
        ScoringProfile::updateOrCreate(
            ['organization_id' => $orgId],
            [
                'weights' => [
                    'net_margin' => 70,
                    'sales_growth' => 5,
                    'target_achievement' => 5,
                    'food_cost_pct' => 5,
                    'waste_pct' => 5,
                    'avg_ticket_growth' => 5,
                    'cancel_rate' => 5,
                ],
            ]
        );

        $report2 = BranchRankingReport::for($query);
        $scoreA2 = $report2->data()->rankedBranches[1]->overallScore;

        // Overall score changed deterministically
        $this->assertNotEquals($scoreA1, $scoreA2);
    }

    public function test_branch_with_incomplete_data_is_excluded_from_ranking(): void
    {
        // 14-day period: 2026-10-03 to 2026-10-16
        // Branch B is missing data on 2026-10-10 and unreconciled on 2026-10-14
        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
        ], CarbonImmutable::parse('2026-10-17 12:00:00'), '12:00');

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = BranchRankingReport::for($query);
        $data = $report->data();

        // Branch B is excluded!
        $this->assertCount(1, $data->excludedBranches);
        $this->assertEquals($this->fixture->branchB->id, $data->excludedBranches[0]->branchId);
        $this->assertEquals('مستبعد لنقص البيانات', $data->excludedBranches[0]->reason);

        // Branch A is the only complete branch -> singleBranchMode is active
        $this->assertTrue($data->singleBranchMode);
        $this->assertCount(1, $data->rankedBranches);
        $this->assertEquals($this->fixture->branchA->id, $data->rankedBranches[0]->branchId);
        $this->assertNull($data->rankedBranches[0]->rank); // no ranking for 1 branch
    }
}
