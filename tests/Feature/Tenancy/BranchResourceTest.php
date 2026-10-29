<?php

namespace Tests\Feature\Tenancy;

use App\Enums\BranchStatus;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranches;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BranchResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->operator = User::factory()->create(['organization_id' => null]);
        $this->operator->assignRole('operator');

        $org = Organization::factory()->create();
        $rest = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $rest->id,
            'status' => BranchStatus::ACTIVE,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_pairing_code_action_generates_code_and_notifies(): void
    {
        $this->actingAs($this->operator);

        Livewire::test(ListBranches::class)
            ->callTableAction('generatePairingCode', $this->branch)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('branch_pairing_codes', [
            'branch_id' => $this->branch->id,
            'created_by' => $this->operator->id,
        ]);
    }

    public function test_unlink_action_revokes_tokens_and_sets_pending(): void
    {
        $this->actingAs($this->operator);

        $token = $this->branch->issueIngestToken();
        $this->assertNotEmpty($token);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        Livewire::test(ListBranches::class)
            ->callTableAction('unlink', $this->branch)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->branch->refresh();
        $this->assertSame(BranchStatus::PENDING, $this->branch->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
