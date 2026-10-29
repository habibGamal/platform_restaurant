<?php

namespace App\Policies\Warehouse;

use App\Models\User;
use App\Models\Warehouse\DimExpenseType;

class DimExpenseTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, DimExpenseType $dimExpenseType): bool
    {
        return $user->can('users.manage') && $user->organization_id === $dimExpenseType->getAttribute('organization_id');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DimExpenseType $dimExpenseType): bool
    {
        return $user->can('users.manage') && $user->organization_id === $dimExpenseType->getAttribute('organization_id');
    }

    public function delete(User $user, DimExpenseType $dimExpenseType): bool
    {
        return false;
    }
}
