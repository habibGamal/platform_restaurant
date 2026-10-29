<?php

namespace Tests\Feature\Aggregation;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RebuildCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Restaurant $restaurant;

    protected Branch $branch1;

    protected Branch $branch2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Org Command Test',
            'slug' => 'org-cmd-test',
            'plan' => 'pro',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $this->restaurant = Restaurant::create([
            'organization_id' => $this->organization->id,
            'name' => 'Restaurant Command',
        ]);

        $this->branch1 = Branch::create([
            'organization_id' => $this->organization->id,
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Branch 1',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-09-01',
            'status' => BranchStatus::ACTIVE,
        ]);

        $this->branch2 = Branch::create([
            'organization_id' => $this->organization->id,
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Branch 2',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-09-01',
            'status' => BranchStatus::ACTIVE,
        ]);
    }

    public function test_rebuild_command_with_specific_branch_and_range(): void
    {
        // Branch 1 has orders on Oct 1, Oct 2, Oct 3
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $date) {
            WhOrder::create([
                'organization_id' => $this->organization->id,
                'branch_id' => $this->branch1->id,
                'uuid' => "ord-b1-{$date}",
                'source_version' => 1,
                'business_date' => $date,
                'type' => 'dine_in',
                'status' => 'completed',
                'sub_total' => 100.00,
                'total' => 100.00,
                'cogs' => 30.00,
            ]);
        }

        // Branch 2 has order on Oct 1
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch2->id,
            'uuid' => 'ord-b2-2026-10-01',
            'source_version' => 1,
            'business_date' => '2026-10-01',
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 100.00,
            'total' => 100.00,
            'cogs' => 30.00,
        ]);

        // Rebuild only Branch 1 from 2026-10-01 to 2026-10-02
        $exitCode = Artisan::call('metrics:rebuild', [
            '--branch' => [$this->branch1->id],
            '--from' => '2026-10-01',
            '--to' => '2026-10-02',
        ]);
        $this->assertSame(0, $exitCode);

        // Branch 1 has Oct 1 and Oct 2, but not Oct 3
        $this->assertDatabaseHas('agg_branch_daily', [
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-01',
        ]);
        $this->assertDatabaseHas('agg_branch_daily', [
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-02',
        ]);
        $this->assertDatabaseMissing('agg_branch_daily', [
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-03',
        ]);

        // Branch 2 was not rebuilt
        $this->assertDatabaseMissing('agg_branch_daily', [
            'branch_id' => $this->branch2->id,
            'business_date' => '2026-10-01',
        ]);
    }

    public function test_rebuild_command_with_defaults_infers_all_branches_and_dates(): void
    {
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch1->id,
            'uuid' => 'ord-b1-1',
            'source_version' => 1,
            'business_date' => '2026-10-01',
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 100.00,
            'total' => 100.00,
            'cogs' => 30.00,
        ]);

        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch2->id,
            'uuid' => 'ord-b2-2',
            'source_version' => 1,
            'business_date' => '2026-10-02',
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 200.00,
            'total' => 200.00,
            'cogs' => 60.00,
        ]);

        // Run with no arguments/options
        $exitCode = Artisan::call('metrics:rebuild');
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('agg_branch_daily', [
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-01',
            'orders_count' => 1,
        ]);
        $this->assertDatabaseHas('agg_branch_daily', [
            'branch_id' => $this->branch2->id,
            'business_date' => '2026-10-02',
            'orders_count' => 1,
        ]);
    }

    public function test_rebuild_command_rejects_invalid_date_range(): void
    {
        $exitCode = Artisan::call('metrics:rebuild', [
            '--from' => '2026-10-05',
            '--to' => '2026-10-01',
        ]);
        $this->assertNotSame(0, $exitCode);
    }
}
