<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.manage') && $user->organization_id === $model->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.manage') && $user->organization_id === $model->organization_id;
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->can('users.manage') || $user->organization_id !== $model->organization_id) {
            return false;
        }

        if ($user->id === $model->id && $model->hasRole('org_owner')) {
            $otherOwners = User::where('organization_id', $model->organization_id)
                ->where('id', '!=', $model->id)
                ->role('org_owner')
                ->count();

            if ($otherOwners === 0) {
                return false;
            }
        }

        return true;
    }
}
