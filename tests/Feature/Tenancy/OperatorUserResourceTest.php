<?php

namespace Tests\Feature\Tenancy;

use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorUserResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->operator = User::factory()->create(['organization_id' => null]);
        $this->operator->assignRole('operator');
        $this->organization = Organization::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->operator);
    }

    public function test_operator_can_create_the_first_owner_and_the_owner_can_access_the_app(): void
    {
        $this->get(UserResource::getUrl('create'))->assertOk();
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData())
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame($this->organization->id, $user->organization_id);
        $this->assertTrue($user->hasExactRoles(['org_owner']));
        $this->assertTrue(Hash::check('secure-password-123', $user->password));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_operator_can_list_users_across_organizations_but_not_operator_accounts(): void
    {
        $first = User::factory()->create(['organization_id' => $this->organization->id]);
        $second = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $tenantOperator = User::factory()->create(['organization_id' => $this->organization->id]);
        $tenantOperator->assignRole('operator');

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertCanNotSeeTableRecords([$this->operator, $tenantOperator]);
        $this->get(UserResource::getUrl('edit', ['record' => $this->operator]))->assertNotFound();
        $this->get(UserResource::getUrl('edit', ['record' => $tenantOperator]))->assertNotFound();
    }

    public function test_branch_manager_can_only_receive_branches_from_the_selected_organization(): void
    {
        $branch = Branch::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Branch::factory()->create();
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['role' => 'branch_manager', 'branches' => [$foreign->id]]))
            ->call('create')
            ->assertHasFormErrors();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['role' => 'branch_manager', 'branches' => [$branch->id]]))
            ->call('create')
            ->assertHasNoFormErrors();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($user->hasExactRoles(['branch_manager']));
        $this->assertSame([$branch->id], $user->branches()->pluck('branches.id')->all());
    }

    public function test_operator_role_and_missing_organization_are_rejected(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['role' => 'operator']))
            ->call('create')
            ->assertHasFormErrors(['role']);
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['organization_id' => null]))
            ->call('create')
            ->assertHasFormErrors(['organization_id']);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_edit_rejects_foreign_branches_and_privilege_escalation(): void
    {
        $branch = Branch::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Branch::factory()->create();
        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        $user->assignRole('branch_manager');
        $user->branches()->attach($branch);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['branches' => [$foreign->id]])
            ->call('save')->assertHasFormErrors();
        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['role' => 'operator'])
            ->call('save')->assertHasFormErrors(['role']);

        $this->assertTrue($user->fresh()->hasExactRoles(['branch_manager']));
        $this->assertSame([$branch->id], $user->branches()->pluck('branches.id')->all());
    }

    public function test_required_fields_and_duplicate_email_are_validated_before_creating_a_user(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['password' => '', 'email' => $this->operator->email]))
            ->call('create')->assertHasFormErrors(['password' => 'required', 'email' => 'unique']);
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['organization_id' => 999999999]))
            ->call('create')->assertHasFormErrors(['organization_id']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_organization_filter_and_branch_options_follow_the_selected_organization(): void
    {
        $local = Branch::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Branch::factory()->create();
        $first = User::factory()->create(['organization_id' => $this->organization->id]);
        $second = User::factory()->create(['organization_id' => $foreign->organization_id]);

        Livewire::test(ListUsers::class)
            ->filterTable('organization', $this->organization->id)
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);

        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['role' => 'branch_manager', 'branches' => [$local->id]]))
            ->assertFormFieldExists('branches', fn ($field): bool => array_keys($field->getOptions()) === [$local->id])
            ->set('data.organization_id', $foreign->organization_id)
            ->assertFormSet(['branches' => []])
            ->assertFormFieldExists('branches', fn ($field): bool => array_keys($field->getOptions()) === [$foreign->id]);
    }

    public function test_edit_preserves_password_and_organization_and_clears_obsolete_branch_access(): void
    {
        $branch = Branch::factory()->create(['organization_id' => $this->organization->id]);
        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        $user->assignRole('branch_manager');
        $user->branches()->attach($branch);
        $password = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->assertFormSet(['role' => 'branch_manager', 'branches' => [$branch->id]])
            ->fillForm(['name' => 'Updated', 'role' => 'org_analyst', 'password' => '', 'organization_id' => Organization::factory()->create()->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('Updated', $user->name);
        $this->assertSame($password, $user->password);
        $this->assertSame($this->organization->id, $user->organization_id);
        $this->assertTrue($user->hasExactRoles(['org_analyst']));
        $this->assertCount(0, $user->branches);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['password' => 'replacement-password-456'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('replacement-password-456', $user->fresh()->password));
    }

    public function test_last_owner_cannot_be_demoted_or_deleted_until_a_replacement_exists(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $owner->assignRole('org_owner');
        Livewire::test(EditUser::class, ['record' => $owner->id])
            ->fillForm(['role' => 'org_analyst'])->call('save')->assertHasFormErrors(['role']);
        Livewire::test(ListUsers::class)->assertTableActionHidden('delete', $owner);

        $replacement = User::factory()->create(['organization_id' => $this->organization->id]);
        $replacement->assignRole('org_owner');
        Livewire::test(ListUsers::class)->callTableAction('delete', $owner)->assertHasNoTableActionErrors();
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    }

    public function test_tenant_users_cannot_access_operator_user_management(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $owner->assignRole('org_owner');
        $this->actingAs($owner);
        $this->get(UserResource::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        Livewire::test(CreateUser::class)->assertForbidden();
        Livewire::test(EditUser::class, ['record' => $owner->id])->assertForbidden();
        Livewire::test(ListUsers::class)->assertForbidden();
        $this->actingAs($this->operator);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->assertFalse(\App\Filament\App\Resources\UserResource::canCreate());
    }

    /** @return array<string, mixed> */
    private function userData(array $overrides = []): array
    {
        return array_replace([
            'organization_id' => $this->organization->id,
            'name' => 'New user',
            'email' => 'new@example.com',
            'password' => 'secure-password-123',
            'role' => 'org_owner',
            'branches' => [],
        ], $overrides);
    }
}
