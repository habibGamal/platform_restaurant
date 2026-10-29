<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('branches.manage');
    }

    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->can('branches.manage') && $user->organization_id === $restaurant->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('branches.manage');
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->can('branches.manage') && $user->organization_id === $restaurant->organization_id;
    }

    public function delete(User $user, Restaurant $restaurant): bool
    {
        return $user->can('branches.manage') && $user->organization_id === $restaurant->organization_id;
    }
}
