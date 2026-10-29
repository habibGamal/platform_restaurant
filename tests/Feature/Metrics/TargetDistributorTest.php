<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Targets\TargetDistributor;
use App\Models\Branch;
use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Target;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetDistributorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Restaurant $rest;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->rest = Restaurant::factory()->create(['organization_id' => $this->org->id]);
        $this->branch = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'opened_on' => '2026-01-01',
        ]);
    }

    public function test_weights_from_history_and_sum_exactness(): void
    {
        // Target month: November 2026 (Nov 1 is Sunday).
        // 8 full weeks ending on Friday Oct 30: from Saturday Sep 5 to Friday Oct 30 (56 days).
        $start = CarbonImmutable::parse('2026-09-05');
        for ($i = 0; $i < 56; $i++) {
            $date = $start->addDays($i);
            $weekday = ($date->dayOfWeek + 1) % 7; // Weekday::of
            // Saturdays (weekday 0) have 3000 sales, other days have 1000 sales
            $sales = ($weekday === 0) ? 3000.0 : 1000.0;

            DB::table('agg_branch_daily')->insert([
                'organization_id' => $this->org->id,
                'restaurant_id' => $this->rest->id,
                'branch_id' => $this->branch->id,
                'business_date' => $date->toDateString(),
                'weekday' => $weekday,
                'net_sales_after_returns' => $sales,
            ]);
        }

        $target = Target::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'metric' => 'net_sales',
            'period_month' => '2026-11-01',
            'value' => 300000.00,
            'distribution' => 'weekday_weights',
        ]);

        $daily = TargetDistributor::daily($target);

        // November has 30 days
        $this->assertCount(30, $daily);

        // Sum equals value exactly
        $this->assertSame(300000.00, round(array_sum($daily), 2));

        // In November 2026: Nov 7 is Saturday (weekday 0), Nov 8 is Sunday (weekday 1)
        // Saturday daily target should be significantly higher than Sunday daily target
        $this->assertGreaterThan($daily['2026-11-08'], $daily['2026-11-07']);
    }

    public function test_calendar_exclusion_filters_out_event_dates(): void
    {
        // Seed 8 weeks of history: all days 1000 sales, except one week where an event happened
        $start = CarbonImmutable::parse('2026-09-05');
        for ($i = 0; $i < 56; $i++) {
            $date = $start->addDays($i);
            $weekday = ($date->dayOfWeek + 1) % 7;
            DB::table('agg_branch_daily')->insert([
                'organization_id' => $this->org->id,
                'restaurant_id' => $this->rest->id,
                'branch_id' => $this->branch->id,
                'business_date' => $date->toDateString(),
                'weekday' => $weekday,
                'net_sales_after_returns' => 1000.0,
            ]);
        }

        // Add a calendar event excluding Sep 12 to Sep 18 (one full week)
        CalendarEvent::create([
            'organization_id' => $this->org->id,
            'name' => 'Renovation promo',
            'kind' => 'promo',
            'date_from' => '2026-09-12',
            'date_to' => '2026-09-18',
        ]);

        $target = Target::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'metric' => 'net_sales',
            'period_month' => '2026-11-01',
            'value' => 100000.00,
            'distribution' => 'weekday_weights',
        ]);

        $daily = TargetDistributor::daily($target);

        // With 7 weeks remaining (still >= 4 weeks), distribution still works and sum is exact
        $this->assertSame(100000.00, round(array_sum($daily), 2));
    }

    public function test_flat_fallback_when_fewer_than_4_weeks_of_history(): void
    {
        // Seed only 2 weeks (14 days)
        $start = CarbonImmutable::parse('2026-10-17');
        for ($i = 0; $i < 14; $i++) {
            $date = $start->addDays($i);
            $weekday = ($date->dayOfWeek + 1) % 7;
            DB::table('agg_branch_daily')->insert([
                'organization_id' => $this->org->id,
                'restaurant_id' => $this->rest->id,
                'branch_id' => $this->branch->id,
                'business_date' => $date->toDateString(),
                'weekday' => $weekday,
                'net_sales_after_returns' => 1000.0,
            ]);
        }

        $target = Target::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'metric' => 'net_sales',
            'period_month' => '2026-11-01',
            'value' => 30000.00,
            'distribution' => 'weekday_weights', // requests weekday_weights but only 2 weeks history
        ]);

        $daily = TargetDistributor::daily($target);

        // Falls back to flat: 30000 / 30 = 1000.00 each day
        $this->assertSame(1000.0, $daily['2026-11-01']);
        $this->assertSame(1000.0, $daily['2026-11-15']);
        $this->assertSame(30000.00, round(array_sum($daily), 2));
    }

    public function test_opened_on_mid_month_gives_zero_to_prior_days(): void
    {
        // Branch opened on November 16, 2026
        $this->branch->update(['opened_on' => '2026-11-16']);

        $target = Target::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'metric' => 'net_sales',
            'period_month' => '2026-11-01',
            'value' => 15000.00,
            'distribution' => 'flat',
        ]);

        $daily = TargetDistributor::daily($target);

        // Days 1..15 get 0.00
        $this->assertSame(0.0, $daily['2026-11-01']);
        $this->assertSame(0.0, $daily['2026-11-15']);

        // Days 16..30 (15 days) share 15000.00 -> 1000.0 each
        $this->assertSame(1000.0, $daily['2026-11-16']);
        $this->assertSame(1000.0, $daily['2026-11-30']);
        $this->assertSame(15000.00, round(array_sum($daily), 2));
    }

    public function test_target_observer_persists_to_target_daily_table(): void
    {
        $target = Target::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'metric' => 'net_sales',
            'period_month' => '2026-11-01',
            'value' => 30000.00,
            'distribution' => 'flat',
        ]);

        // TargetObserver should have automatically populated target_daily table
        $count = DB::table('target_daily')->where('target_id', $target->id)->count();
        $this->assertSame(30, $count);

        $sum = DB::table('target_daily')->where('target_id', $target->id)->sum('value');
        $this->assertSame(30000.0, (float) $sum);
    }
}
