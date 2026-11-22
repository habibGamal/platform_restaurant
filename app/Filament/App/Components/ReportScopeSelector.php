<?php

declare(strict_types=1);

namespace App\Filament\App\Components;

use App\Domain\Tenancy\AccessibleBranches;
use App\Models\Restaurant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ReportScopeSelector extends Component
{
    public ?string $restaurant = null;

    /** @var list<string> */
    public array $branches = [];

    public function mount(): void
    {
        $scope = session()->get('report_scope', []);
        $this->restaurant = isset($scope['restaurant']) ? (string) $scope['restaurant'] : null;
        $this->branches = array_map('strval', $scope['branches'] ?? []);
        $query = request()->query();
        if (isset($query['filters']) && is_array($query['filters'])) {
            $query = array_merge($query, $query['filters']);
        }
        if (array_key_exists('restaurant', $query)) {
            $this->restaurant = is_scalar($query['restaurant']) ? (string) $query['restaurant'] : null;
        }
        if (array_key_exists('branches', $query)) {
            $values = is_array($query['branches']) ? $query['branches'] : explode(',', (string) $query['branches']);
            $this->branches = array_values(array_map('strval', array_filter($values, 'is_numeric')));
        }
    }

    public function updatedRestaurant(): void
    {
        $this->branches = [];
        $this->applyScope();
    }

    public function updatedBranches(): void
    {
        $this->applyScope();
    }

    private function applyScope(): void
    {
        $user = auth()->user();
        abort_unless($user && $user->can('analytics.view'), 403);
        $restaurantId = is_numeric($this->restaurant) ? (int) $this->restaurant : null;
        if ($restaurantId !== null && ! Restaurant::query()->where('organization_id', $user->organization_id)->whereKey($restaurantId)->exists()) {
            abort(403);
        }

        $allowed = AccessibleBranches::query($user)->when($restaurantId, fn ($query) => $query->where('restaurant_id', $restaurantId))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $selected = array_values(array_unique(array_map('intval', $this->branches)));
        abort_if(array_diff($selected, $allowed) !== [], 403);
        $scope = ['restaurant' => $restaurantId, 'branches' => $selected ?: null];
        session()->put('report_scope', $scope);
        $this->dispatch('report-scope-changed', ...$scope);
    }

    public function render(): View
    {
        $user = auth()->user();
        $restaurants = Restaurant::query()->where('organization_id', $user?->organization_id)->orderBy('name')->pluck('name', 'id');
        $availableBranches = $user ? AccessibleBranches::query($user)->when($this->restaurant, fn ($query) => $query->where('restaurant_id', $this->restaurant))->orderBy('name')->pluck('name', 'id') : collect();

        return view('filament.app.components.report-scope-selector', compact('restaurants', 'availableBranches'));
    }
}
