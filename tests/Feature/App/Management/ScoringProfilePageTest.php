<?php

declare(strict_types=1);

namespace Tests\Feature\App\Management;

use App\Filament\App\Pages\Management\ScoringProfilePage;
use App\Models\ScoringProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ScoringProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_owner_with_users_manage_permission_can_access_page(): void
    {
        $this->actingAs($this->fixture->owner);

        Livewire::test(ScoringProfilePage::class)
            ->assertSuccessful()
            ->assertSet('data.net_margin', 25.0)
            ->assertSet('data.sales_growth', 20.0);
    }

    public function test_analyst_without_users_manage_cannot_access_page(): void
    {
        $this->actingAs($this->fixture->analyst);

        Livewire::test(ScoringProfilePage::class)
            ->assertForbidden();
    }

    public function test_manager_a_cannot_access_page(): void
    {
        $this->actingAs($this->fixture->managerA);

        Livewire::test(ScoringProfilePage::class)
            ->assertForbidden();
    }

    public function test_weights_not_summing_to_100_are_rejected(): void
    {
        $this->actingAs($this->fixture->owner);

        Livewire::test(ScoringProfilePage::class)
            ->set('data.net_margin', 30)
            ->set('data.sales_growth', 20)
            ->set('data.target_achievement', 20)
            ->set('data.food_cost_pct', 15)
            ->set('data.waste_pct', 10)
            ->set('data.avg_ticket_growth', 5)
            ->set('data.cancel_rate', 5) // Sum = 105
            ->call('save')
            ->assertHasErrors(['weights']);
    }

    public function test_weights_summing_to_100_are_saved_successfully(): void
    {
        $this->actingAs($this->fixture->owner);

        Livewire::test(ScoringProfilePage::class)
            ->set('data.net_margin', 30)
            ->set('data.sales_growth', 15)
            ->set('data.target_achievement', 20)
            ->set('data.food_cost_pct', 15)
            ->set('data.waste_pct', 10)
            ->set('data.avg_ticket_growth', 5)
            ->set('data.cancel_rate', 5) // Sum = 100
            ->call('save')
            ->assertHasNoErrors();

        $profile = ScoringProfile::query()
            ->where('organization_id', $this->fixture->organization->id)
            ->first();

        $this->assertNotNull($profile);
        /** @var array<string, mixed>|string $rawWeights */
        $rawWeights = $profile->getAttribute('weights');
        $weights = is_string($rawWeights) ? (json_decode($rawWeights, true) ?? []) : (array) $rawWeights;
        $this->assertIsArray($weights);
        $this->assertEquals(30.0, $weights['net_margin']);
        $this->assertEquals(15.0, $weights['sales_growth']);
    }
}
