<?php

namespace Tests\Feature\App\Management;

use App\Filament\App\Resources\BranchSetResource\Pages\CreateBranchSet;
use App\Filament\App\Resources\BranchSetResource\Pages\ListBranchSets;
use App\Models\Branch;
use App\Models\BranchSet;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class BranchSetResourceTest extends ManagementTestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $analystA;

    private User $managerA;

    private Branch $branchA1;

    private Branch $branchA2;

    private BranchSet $orgWideSetA;

    private BranchSet $analystPersonalSet;

    private BranchSet $managerPersonalSet;

    private BranchSet $otherOrgSet;

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

        $restA = Restaurant::factory()->create(['organization_id' => $this->orgA->id]);
        $this->branchA1 = Branch::factory()->create([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $restA->id,
            'name' => 'فرع المعادي',
        ]);
        $this->branchA2 = Branch::factory()->create([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $restA->id,
            'name' => 'فرع التجمع',
        ]);
        $this->managerA->branches()->attach([$this->branchA1->id, $this->branchA2->id]);

        $this->orgWideSetA = BranchSet::factory()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => null,
            'name' => 'مجموعة القاهرة الكبرى (عامة)',
        ]);
        $this->orgWideSetA->branches()->attach([$this->branchA1->id, $this->branchA2->id]);

        $this->analystPersonalSet = BranchSet::factory()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->analystA->id,
            'name' => 'مجموعة المحلل الخاصة',
        ]);
        $this->analystPersonalSet->branches()->attach([$this->branchA1->id]);

        $this->managerPersonalSet = BranchSet::factory()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->managerA->id,
            'name' => 'مجموعة المدير الخاصة',
        ]);
        $this->managerPersonalSet->branches()->attach([$this->branchA2->id]);

        $this->otherOrgSet = BranchSet::factory()->create([
            'organization_id' => $this->orgB->id,
            'user_id' => null,
            'name' => 'مجموعة منظمة أخرى',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_list_shows_org_wide_plus_own_sets_and_hides_others(): void
    {
        // Analyst sees org-wide set + own personal set, NOT manager's personal set, NOT other org's set
        $this->actingAs($this->analystA);

        Livewire::test(ListBranchSets::class)
            ->assertCanSeeTableRecords([$this->orgWideSetA, $this->analystPersonalSet])
            ->assertCanNotSeeTableRecords([$this->managerPersonalSet, $this->otherOrgSet]);

        // Manager sees org-wide set + own personal set, NOT analyst's personal set
        $this->actingAs($this->managerA);

        Livewire::test(ListBranchSets::class)
            ->assertCanSeeTableRecords([$this->orgWideSetA, $this->managerPersonalSet])
            ->assertCanNotSeeTableRecords([$this->analystPersonalSet, $this->otherOrgSet]);
    }

    public function test_owner_can_create_org_wide_set(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(CreateBranchSet::class)
            ->fillForm([
                'name' => 'مجموعة عامة جديدة',
                'branches' => [$this->branchA1->id, $this->branchA2->id],
                'is_org_wide' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $set = BranchSet::where('name', 'مجموعة عامة جديدة')->first();
        $this->assertNotNull($set);
        $this->assertSame($this->orgA->id, $set->organization_id);
        $this->assertNull($set->user_id);
        $this->assertCount(2, $set->branches);
    }

    public function test_analyst_creates_personal_set_even_if_toggled(): void
    {
        $this->actingAs($this->analystA);

        Livewire::test(CreateBranchSet::class)
            ->fillForm([
                'name' => 'مجموعة خاصة للمحلل 2',
                'branches' => [$this->branchA1->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $set = BranchSet::where('name', 'مجموعة خاصة للمحلل 2')->first();
        $this->assertNotNull($set);
        $this->assertSame($this->orgA->id, $set->organization_id);
        $this->assertSame($this->analystA->id, $set->user_id);
    }

    public function test_user_can_edit_only_their_own_sets_and_owner_manages_org_wide(): void
    {
        // Analyst cannot edit manager's personal set
        $this->actingAs($this->analystA);
        $this->assertFalse($this->analystA->can('update', $this->managerPersonalSet));
        $this->assertFalse($this->analystA->can('delete', $this->managerPersonalSet));

        // Analyst cannot edit org-wide set
        $this->assertFalse($this->analystA->can('update', $this->orgWideSetA));
        $this->assertFalse($this->analystA->can('delete', $this->orgWideSetA));

        // Analyst can edit their own personal set
        $this->assertTrue($this->analystA->can('update', $this->analystPersonalSet));
        $this->assertTrue($this->analystA->can('delete', $this->analystPersonalSet));

        // Owner can edit org-wide set
        $this->actingAs($this->ownerA);
        $this->assertTrue($this->ownerA->can('update', $this->orgWideSetA));
        $this->assertTrue($this->ownerA->can('delete', $this->orgWideSetA));
    }
}
