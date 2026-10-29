<?php

namespace App\Policies;

use App\Models\BranchSet;
use App\Models\User;

class BranchSetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('analytics.view');
    }

    public function view(User $user, BranchSet $branchSet): bool
    {
        return $user->organization_id === $branchSet->organization_id
            && ($branchSet->user_id === null || $branchSet->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('analytics.view');
    }

    public function update(User $user, BranchSet $branchSet): bool
    {
        return $user->organization_id === $branchSet->organization_id
            && (
                $branchSet->user_id === $user->id
                || ($branchSet->user_id === null && $user->can('users.manage'))
            );
    }

    public function delete(User $user, BranchSet $branchSet): bool
    {
        return $user->organization_id === $branchSet->organization_id
            && (
                $branchSet->user_id === $user->id
                || ($branchSet->user_id === null && $user->can('users.manage'))
            );
    }
}
