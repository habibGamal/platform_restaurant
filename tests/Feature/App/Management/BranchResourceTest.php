<?php

namespace Tests\Feature\App\Management;

use App\Enums\BranchStatus;
use App\Filament\App\Resources\BranchResource\Pages\CreateBranch;
use App\Filament\App\Resources\BranchResource\Pages\ListBranches;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class BranchResourceTest extends ManagementTestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $analystA;

    private User $managerA;

    private Restaurant $restA;

    private Branch $branchA1;

    private Branch $branchA2;

    private Branch $branchB1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->ownerA = User::factory()->forOrganization($this->orgA)->create();
        $this->ownerA->assignRole('org_owner');

        $this->analystA = User::factory()->forOrganization($this->orgA)->create();
        $this->analystA->assignRole('org_analyst');

        $this->managerA = User::factory()->forOrganization($this->orgA)->create();
        $this->managerA->assignRole('branch_manager');

        $this->restA = Restaurant::factory()->create(['organization_id' => $this->orgA->id]);
        $restB = Restaurant::factory()->create(['organization_id' => $this->orgB->id]);

        $this->branchA1 = Branch::factory()->create([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $this->restA->id,
            'name' => 'فرع المعادي',
            'status' => BranchStatus::ACTIVE,
        ]);

        $this->branchA2 = Branch::factory()->create([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $this->restA->id,
            'name' => 'فرع التجمع',
            'status' => BranchStatus::PENDING,
        ]);

        $this->branchB1 = Branch::factory()->create([
            'organization_id' => $this->orgB->id,
            'restaurant_id' => $restB->id,
            'name' => 'فرع الإسكندرية',
            'status' => BranchStatus::ACTIVE,
        ]);

        // Manager only has access to branchA1
        $this->managerA->branches()->attach($this->branchA1);

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_manager_sees_only_accessible_branches(): void
    {
        $this->actingAs($this->managerA);

        Livewire::test(ListBranches::class)
            ->assertCanSeeTableRecords([$this->branchA1])
            ->assertCanNotSeeTableRecords([$this->branchA2, $this->branchB1]);
    }

    public function test_analyst_sees_all_org_branches_not_other_orgs(): void
    {
        $this->actingAs($this->analystA);

        Livewire::test(ListBranches::class)
            ->assertCanSeeTableRecords([$this->branchA1, $this->branchA2])
            ->assertCanNotSeeTableRecords([$this->branchB1]);
    }

    public function test_analyst_and_manager_cannot_create_or_edit_branches(): void
    {
        $this->actingAs($this->analystA);
        $this->assertFalse($this->analystA->can('create', Branch::class));
        $this->assertFalse($this->analystA->can('update', $this->branchA1));

        $this->actingAs($this->managerA);
        $this->assertFalse($this->managerA->can('create', Branch::class));
        $this->assertFalse($this->managerA->can('update', $this->branchA1));
    }

    public function test_owner_can_create_branch(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(CreateBranch::class)
            ->fillForm([
                'name' => 'فرع الشيخ زايد',
                'restaurant_id' => $this->restA->id,
                'timezone' => 'Africa/Cairo',
                'opened_on' => '2026-01-01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('branches', [
            'organization_id' => $this->orgA->id,
            'name' => 'فرع الشيخ زايد',
            'status' => BranchStatus::PENDING->value,
        ]);
    }

    public function test_pairing_code_action_generates_code_and_notifies(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(ListBranches::class)
            ->callTableAction('generatePairingCode', $this->branchA1)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('branch_pairing_codes', [
            'branch_id' => $this->branchA1->id,
            'created_by' => $this->ownerA->id,
        ]);
    }

    public function test_unlink_action_revokes_tokens_and_sets_status_pending(): void
    {
        $this->actingAs($this->ownerA);

        $token = $this->branchA1->issueIngestToken();
        $this->assertNotEmpty($token);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        Livewire::test(ListBranches::class)
            ->callTableAction('unlink', $this->branchA1)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->branchA1->refresh();
        $this->assertSame(BranchStatus::PENDING, $this->branchA1->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
