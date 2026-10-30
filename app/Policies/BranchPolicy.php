<?php

namespace App\Policies;

use App\Domain\Tenancy\AccessibleBranches;
use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('operator') ? true : null;
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('branches.manage');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('analytics.view') || $user->can('branches.manage');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $branch->organization_id === $user->organization_id
            && in_array($branch->id, AccessibleBranches::ids($user), true);
    }

    public function create(User $user): bool
    {
        return $user->can('branches.manage');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('branches.manage') && $branch->organization_id === $user->organization_id;
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->can('branches.manage') && $branch->organization_id === $user->organization_id;
    }
}
