<?php

namespace Tests\Feature\App\Management;

use App\Filament\App\Resources\UserResource\Pages\CreateUser;
use App\Filament\App\Resources\UserResource\Pages\EditUser;
use App\Filament\App\Resources\UserResource\Pages\ListUsers;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

class UserResourceTest extends ManagementTestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $analystA;

    private User $managerA;

    private User $ownerB;

    private Branch $branchA1;

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

        $this->ownerB = User::factory()->forOrganization($this->orgB)->create();
        $this->ownerB->assignRole('org_owner');

        $rest = Restaurant::factory()->create(['organization_id' => $this->orgA->id]);
        $this->branchA1 = Branch::factory()->create([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $rest->id,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_list_scoped_to_own_organization(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->ownerA, $this->analystA, $this->managerA])
            ->assertCanNotSeeTableRecords([$this->ownerB]);
    }

    public function test_analyst_and_manager_cannot_manage_users(): void
    {
        $this->actingAs($this->analystA);
        $this->assertFalse($this->analystA->can('viewAny', User::class));
        $this->assertFalse($this->analystA->can('create', User::class));

        $this->actingAs($this->managerA);
        $this->assertFalse($this->managerA->can('viewAny', User::class));
        $this->assertFalse($this->managerA->can('create', User::class));
    }

    public function test_create_user_with_manager_role_and_branch_access(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'مدير فرع جديد',
                'email' => 'manager_new@example.com',
                'password' => 'secret1234',
                'role' => 'branch_manager',
                'branches' => [$this->branchA1->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $newUser = User::where('email', 'manager_new@example.com')->first();
        $this->assertNotNull($newUser);
        $this->assertSame($this->orgA->id, $newUser->organization_id);
        $this->assertTrue($newUser->hasRole('branch_manager'));
        $this->assertTrue(Hash::check('secret1234', $newUser->password));
        $this->assertContains($this->branchA1->id, $newUser->branches()->pluck('branches.id')->all());
    }

    public function test_last_owner_cannot_demote_themselves_in_edit(): void
    {
        $this->actingAs($this->ownerA);

        // ownerA is the only owner in orgA
        Livewire::test(EditUser::class, ['record' => $this->ownerA->id])
            ->fillForm([
                'role' => 'org_analyst',
            ])
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->ownerA->refresh();
        $this->assertTrue($this->ownerA->hasRole('org_owner'));
    }

    public function test_last_owner_cannot_delete_themselves(): void
    {
        $this->actingAs($this->ownerA);

        // UserPolicy check: delete is denied
        $this->assertFalse($this->ownerA->can('delete', $this->ownerA));

        // Delete action is hidden on the table for the last owner
        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('delete', $this->ownerA);

        $this->assertDatabaseHas('users', ['id' => $this->ownerA->id]);
    }

    public function test_owner_can_delete_another_owner_if_not_last(): void
    {
        $secondOwner = User::factory()->forOrganization($this->orgA)->create();
        $secondOwner->assignRole('org_owner');

        $this->actingAs($this->ownerA);

        $this->assertTrue($this->ownerA->can('delete', $secondOwner));

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $secondOwner)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('users', ['id' => $secondOwner->id]);
    }
}
