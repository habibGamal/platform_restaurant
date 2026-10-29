<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_branch_and_restaurant_share_an_organization(): void
    {
        $branch = Branch::factory()->create();
        $this->assertSame($branch->organization_id, $branch->restaurant->organization_id);
    }

    public function test_existing_restaurant_supplies_organization(): void
    {
        $restaurant = Restaurant::factory()->create();
        $branch = Branch::factory()->create(['restaurant_id' => $restaurant->id]);
        $this->assertSame($restaurant->organization_id, $branch->organization_id);
    }

    public function test_explicit_organization_is_used_for_generated_restaurant(): void
    {
        $org = Organization::factory()->create();
        $branch = Branch::factory()->create(['organization_id' => $org->id]);
        $this->assertSame($org->id, $branch->restaurant->organization_id);
    }
}
