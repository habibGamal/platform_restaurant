<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Filament\App\Components\ReportScopeSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ReportScopeSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_branches_accessible_to_the_user(): void
    {
        $fixture = TwoBranchesTwoWeeks::create();

        $this->actingAs($fixture->owner);
        Livewire::test(ReportScopeSelector::class)
            ->assertSee($fixture->branchA->name)
            ->assertSee($fixture->branchB->name);

        $this->actingAs($fixture->managerA);
        Livewire::test(ReportScopeSelector::class)
            ->assertSee($fixture->branchA->name)
            ->assertDontSee($fixture->branchB->name);
    }
}
