<?php

namespace App\Policies;

use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Carbon;

class TargetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('analytics.view') || $user->can('targets.manage');
    }

    public function view(User $user, Target $target): bool
    {
        return $user->organization_id === $target->organization_id
            && ($user->can('analytics.view') || $user->can('targets.manage'));
    }

    public function create(User $user): bool
    {
        return $user->can('targets.manage');
    }

    public function update(User $user, Target $target): bool
    {
        if (! $user->can('targets.manage') || $user->organization_id !== $target->organization_id) {
            return false;
        }

        $periodMonth = Carbon::parse($target->period_month)->startOfMonth();
        $currentMonth = Carbon::now('Africa/Cairo')->startOfMonth();

        if ($periodMonth->lt($currentMonth) && ! $user->hasRole('org_owner')) {
            return false;
        }

        return true;
    }

    public function delete(User $user, Target $target): bool
    {
        if (! $user->can('targets.manage') || $user->organization_id !== $target->organization_id) {
            return false;
        }

        $periodMonth = Carbon::parse($target->period_month)->startOfMonth();
        $currentMonth = Carbon::now('Africa/Cairo')->startOfMonth();

        if ($periodMonth->lt($currentMonth) && ! $user->hasRole('org_owner')) {
            return false;
        }

        return true;
    }
}
