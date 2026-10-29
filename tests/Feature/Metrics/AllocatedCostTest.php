<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Metrics\AllocatedCost;
use App\Domain\Metrics\Period\Period;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AllocatedCostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $rest = Restaurant::factory()->create(['organization_id' => $this->org->id]);
        $this->branch = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $rest->id,
        ]);
    }

    public function test_closed_month_uses_actual_cost(): void
    {
        // Past month: January 2026 (31 days)
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->branch->restaurant_id,
            'branch_id' => $this->branch->id,
            'business_date' => '2026-01-15',
            'weekday' => 5,
            'labor_cost' => 31000.0,
            'rent_cost' => 15500.0,
        ]);

        // Full month of January: 31 days
        $fullPeriod = new Period(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));
        $this->assertSame(31000.0, AllocatedCost::labor([$this->branch->id], $fullPeriod));
        $this->assertSame(15500.0, AllocatedCost::rent([$this->branch->id], $fullPeriod));

        // Partial month: 10 days (Jan 01 to Jan 10) -> 31000 * (10 / 31) = 10000.0
        $partialPeriod = new Period(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-10'));
        $this->assertSame(10000.0, AllocatedCost::labor([$this->branch->id], $partialPeriod));
        $this->assertSame(5000.0, AllocatedCost::rent([$this->branch->id], $partialPeriod));
    }

    public function test_open_month_uses_avg_month_rate_from_expense_types(): void
    {
        // Future / current month: December 2099 (31 days)
        DB::table('dim_expense_types')->insert([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-type-1',
            'source_version' => 1,
            'name' => 'Salaries',
            'category' => 'labor',
            'avg_month_rate' => 62000.0,
        ]);

        DB::table('dim_expense_types')->insert([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-type-2',
            'source_version' => 1,
            'name' => 'Branch Rent',
            'category' => 'other',
            'category_override' => 'rent', // override wins over category
            'avg_month_rate' => 31000.0,
        ]);

        // Full month of December 2099
        $fullPeriod = new Period(CarbonImmutable::parse('2099-12-01'), CarbonImmutable::parse('2099-12-31'));
        $this->assertSame(62000.0, AllocatedCost::labor([$this->branch->id], $fullPeriod));
        $this->assertSame(31000.0, AllocatedCost::rent([$this->branch->id], $fullPeriod));

        // 10 days of December: 62000 * (10 / 31) = 20000.0
        $partialPeriod = new Period(CarbonImmutable::parse('2099-12-01'), CarbonImmutable::parse('2099-12-10'));
        $this->assertSame(20000.0, AllocatedCost::labor([$this->branch->id], $partialPeriod));
    }

    public function test_open_month_trailing_3_month_average_fallback(): void
    {
        // Open month December 2099 with no dim_expense_types
        // Trailing 3 months: November (30 days), October (31 days), September (30 days)
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->branch->restaurant_id,
            'branch_id' => $this->branch->id,
            'business_date' => '2099-09-15',
            'weekday' => 2,
            'labor_cost' => 24000.0,
        ]);
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->branch->restaurant_id,
            'branch_id' => $this->branch->id,
            'business_date' => '2099-10-15',
            'weekday' => 4,
            'labor_cost' => 36000.0,
        ]);
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->branch->restaurant_id,
            'branch_id' => $this->branch->id,
            'business_date' => '2099-11-15',
            'weekday' => 0,
            'labor_cost' => 30000.0,
        ]);

        // Trailing average = (24000 + 36000 + 30000) / 3 = 90000 / 3 = 30000.0
        $fullPeriod = new Period(CarbonImmutable::parse('2099-12-01'), CarbonImmutable::parse('2099-12-31'));
        $this->assertSame(30000.0, AllocatedCost::labor([$this->branch->id], $fullPeriod));
    }

    public function test_proration_across_multiple_overlapping_months(): void
    {
        // Past months: Jan 2026 (31 days, actual 31000) and Feb 2026 (28 days, actual 28000)
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->branch->restaurant_id,
            'branch_id' => $this->branch->id,
            'business_date' => '2026-01-15',
            'weekday' => 5,
            'labor_cost' => 31000.0,
        ]);
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->branch->restaurant_id,
            'branch_id' => $this->branch->id,
            'business_date' => '2026-02-15',
            'weekday' => 0,
            'labor_cost' => 28000.0,
        ]);

        // Period: Jan 22 to Feb 07
        // Jan days: Jan 22..Jan 31 = 10 days -> 31000 * (10 / 31) = 10000.0
        // Feb days: Feb 01..Feb 07 = 7 days -> 28000 * (7 / 28) = 7000.0
        // Total = 17000.0
        $period = new Period(CarbonImmutable::parse('2026-01-22'), CarbonImmutable::parse('2026-02-07'));
        $this->assertSame(17000.0, AllocatedCost::labor([$this->branch->id], $period));
    }
}
