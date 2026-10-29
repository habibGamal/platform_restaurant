<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private Panel $adminPanel;

    private Panel $appPanel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminPanel = Filament::getPanel('admin');
        $this->appPanel = (new Panel)->id('app');
    }

    public function test_operator_can_access_admin_but_not_app(): void
    {
        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole('operator');

        $this->assertTrue($operator->canAccessPanel($this->adminPanel));
        $this->assertFalse($operator->canAccessPanel($this->appPanel));
    }

    public function test_owner_can_access_app_but_not_admin(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->forOrganization($org)->create();
        $owner->assignRole('org_owner');

        $this->assertFalse($owner->canAccessPanel($this->adminPanel));
        $this->assertTrue($owner->canAccessPanel($this->appPanel));
    }

    public function test_analyst_and_manager_can_access_app_but_not_admin(): void
    {
        $org = Organization::factory()->create();

        $analyst = User::factory()->forOrganization($org)->create();
        $analyst->assignRole('org_analyst');
        $this->assertFalse($analyst->canAccessPanel($this->adminPanel));
        $this->assertTrue($analyst->canAccessPanel($this->appPanel));

        $manager = User::factory()->forOrganization($org)->create();
        $manager->assignRole('branch_manager');
        $this->assertFalse($manager->canAccessPanel($this->adminPanel));
        $this->assertTrue($manager->canAccessPanel($this->appPanel));
    }

    public function test_user_with_no_org_can_access_neither(): void
    {
        $user = User::factory()->create(['organization_id' => null]);

        $this->assertFalse($user->canAccessPanel($this->adminPanel));
        $this->assertFalse($user->canAccessPanel($this->appPanel));
    }

    public function test_user_with_org_but_no_role_can_access_neither(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->forOrganization($org)->create();

        $this->assertFalse($user->canAccessPanel($this->adminPanel));
        $this->assertFalse($user->canAccessPanel($this->appPanel));
    }
}
