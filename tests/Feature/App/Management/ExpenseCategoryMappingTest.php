<?php

namespace Tests\Feature\App\Management;

use App\Filament\App\Resources\ExpenseCategoryMappingResource;
use App\Filament\App\Resources\ExpenseCategoryMappingResource\Pages\ListExpenseCategoryMappings;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\Warehouse\DimExpenseType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

class ExpenseCategoryMappingTest extends ManagementTestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $analystA;

    private Branch $branchA1;

    private DimExpenseType $expenseTypeA1;

    private DimExpenseType $expenseTypeB1;

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

        $restA = Restaurant::factory()->create(['organization_id' => $this->orgA->id]);
        $restB = Restaurant::factory()->create(['organization_id' => $this->orgB->id]);

        $this->branchA1 = Branch::factory()->create([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $restA->id,
            'name' => 'فرع التجمع',
        ]);

        $branchB1 = Branch::factory()->create([
            'organization_id' => $this->orgB->id,
            'restaurant_id' => $restB->id,
            'name' => 'فرع الجيزة',
        ]);

        $this->expenseTypeA1 = DimExpenseType::create([
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA1->id,
            'uuid' => (string) Str::uuid(),
            'source_version' => 1,
            'name' => 'فاتورة كهرباء',
            'category' => 'other',
            'category_override' => null,
        ]);

        $this->expenseTypeB1 = DimExpenseType::create([
            'organization_id' => $this->orgB->id,
            'branch_id' => $branchB1->id,
            'uuid' => (string) Str::uuid(),
            'source_version' => 1,
            'name' => 'إيجار مقر',
            'category' => 'rent',
            'category_override' => null,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_list_scoped_to_own_organization(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(ListExpenseCategoryMappings::class)
            ->assertCanSeeTableRecords([$this->expenseTypeA1])
            ->assertCanNotSeeTableRecords([$this->expenseTypeB1]);
    }

    public function test_analyst_cannot_access_expense_mappings(): void
    {
        $this->actingAs($this->analystA);
        $this->assertFalse(ExpenseCategoryMappingResource::canViewAny());
        $this->assertFalse($this->analystA->can('viewAny', DimExpenseType::class));
    }

    public function test_updating_category_override_dispatches_recompute_branch_day_jobs(): void
    {
        Queue::fake();

        $this->actingAs($this->ownerA);

        Livewire::test(ListExpenseCategoryMappings::class)
            ->call('updateTableColumnState', 'category_override', (string) $this->expenseTypeA1->getKey(), 'utilities');

        $this->expenseTypeA1->refresh();
        $this->assertSame('utilities', $this->expenseTypeA1->category_override);

        $tz = 'Africa/Cairo';
        $startDate = Carbon::now($tz)->subMonth()->startOfMonth();
        $endDate = Carbon::now($tz);

        $expectedDaysCount = 0;
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $expectedDaysCount++;
            $dateStr = $d->format('Y-m-d');
            Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $job) use ($dateStr): bool {
                return $job->branchId === $this->branchA1->id && $job->businessDate === $dateStr;
            });
        }

        Queue::assertPushed(RecomputeBranchDay::class, $expectedDaysCount);
    }
}
