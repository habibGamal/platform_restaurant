<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Metrics\Completeness;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompletenessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Restaurant $rest;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->rest = Restaurant::factory()->create(['organization_id' => $this->org->id]);
        $this->owner = User::factory()->forOrganization($this->org)->create();
        $this->owner->assignRole('org_owner');
    }

    public function test_all_five_completeness_statuses(): void
    {
        $today = CarbonImmutable::now('Africa/Cairo')->toDateString();
        $yesterday = CarbonImmutable::now('Africa/Cairo')->subDay()->toDateString();
        $twoDaysAgo = CarbonImmutable::now('Africa/Cairo')->subDays(2)->toDateString();
        $threeDaysAgo = CarbonImmutable::now('Africa/Cairo')->subDays(3)->toDateString();
        $fourDaysAgo = CarbonImmutable::now('Africa/Cairo')->subDays(4)->toDateString();
        $fiveDaysAgo = CarbonImmutable::now('Africa/Cairo')->subDays(5)->toDateString();

        // Branch opened 4 days ago
        $branch = Branch::factory()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'status' => BranchStatus::ACTIVE,
            'opened_on' => $fourDaysAgo,
        ]);

        // 1. Complete: 3 days ago closed + reconciled
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'branch_id' => $branch->id,
            'business_date' => $threeDaysAgo,
            'weekday' => 1,
            'is_day_closed' => true,
            'is_reconciled' => true,
        ]);

        // 2. Closed: 2 days ago closed, but not reconciled
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'branch_id' => $branch->id,
            'business_date' => $twoDaysAgo,
            'weekday' => 2,
            'is_day_closed' => true,
            'is_reconciled' => false,
        ]);

        // 3. Yesterday: no row received, but branch is active and date >= opened_on -> missing

        // 4. Today: not closed -> open

        // 5. Five days ago: before opened_on -> not_opened

        $period = new Period(
            CarbonImmutable::parse($fiveDaysAgo),
            CarbonImmutable::parse($today)
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$branch->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $result = Completeness::for($query, $period);

        $this->assertSame('complete', $result->statusFor($branch->id, $threeDaysAgo));
        $this->assertSame('closed', $result->statusFor($branch->id, $twoDaysAgo));
        $this->assertSame('missing', $result->statusFor($branch->id, $yesterday));
        $this->assertSame('open', $result->statusFor($branch->id, $today));
        $this->assertSame('not_opened', $result->statusFor($branch->id, $fiveDaysAgo));

        // Because of the 'missing' and 'closed' days, branch is not complete for the whole period
        $this->assertFalse($result->isBranchComplete($branch->id));
    }

    public function test_branch_complete_and_summary(): void
    {
        $today = CarbonImmutable::now('Africa/Cairo')->toDateString();
        $yesterday = CarbonImmutable::now('Africa/Cairo')->subDay()->toDateString();
        $twoDaysAgo = CarbonImmutable::now('Africa/Cairo')->subDays(2)->toDateString();

        $branchA = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'opened_on' => $twoDaysAgo,
        ]);

        $branchB = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'opened_on' => $twoDaysAgo,
        ]);

        // Branch A has all past days complete
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'branch_id' => $branchA->id,
            'business_date' => $twoDaysAgo,
            'weekday' => 1,
            'is_day_closed' => true,
            'is_reconciled' => true,
        ]);
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'branch_id' => $branchA->id,
            'business_date' => $yesterday,
            'weekday' => 2,
            'is_day_closed' => true,
            'is_reconciled' => true,
        ]);

        // Branch B is missing yesterday
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'branch_id' => $branchB->id,
            'business_date' => $twoDaysAgo,
            'weekday' => 1,
            'is_day_closed' => true,
            'is_reconciled' => true,
        ]);

        $pastPeriod = new Period(CarbonImmutable::parse($twoDaysAgo), CarbonImmutable::parse($yesterday));

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $pastPeriod,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$branchA->id, $branchB->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $result = Completeness::for($query, $pastPeriod);

        $this->assertTrue($result->isBranchComplete($branchA->id));
        $this->assertFalse($result->isBranchComplete($branchB->id));

        $this->assertTrue(Completeness::isBranchComplete($branchA->id, $pastPeriod));
        $this->assertFalse(Completeness::isBranchComplete($branchB->id, $pastPeriod));

        // Coverage
        $coverage = $result->perBranchCoverage();
        $this->assertSame(2, $coverage[$branchA->id]['complete']);
        $this->assertSame(2, $coverage[$branchA->id]['total']);
        $this->assertTrue($coverage[$branchA->id]['is_complete']);

        $this->assertSame(1, $coverage[$branchB->id]['complete']);
        $this->assertSame(2, $coverage[$branchB->id]['total']);
        $this->assertFalse($coverage[$branchB->id]['is_complete']);

        // Summary: 1 of 2 branches complete
        $summary = $result->summary();
        $this->assertSame(1, $summary['complete']);
        $this->assertSame(2, $summary['total']);
    }
}
