<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Scopes\OrganizationScope;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AccessibleBranches
{
    /**
     * @return Builder<Branch>
     */
    public static function query(User $user): Builder
    {
        if ($user->organization_id === null) {
            return Branch::query()->whereRaw('1 = 0');
        }

        if ($user->hasRole(['org_owner', 'org_analyst'])) {
            return Branch::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $user->organization_id);
        }

        if ($user->hasRole('branch_manager')) {
            return Branch::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $user->organization_id)
                ->whereIn('id', $user->branches()->select('branches.id'));
        }

        return Branch::query()->whereRaw('1 = 0');
    }

    /**
     * @return array<int>
     */
    public static function ids(User $user): array
    {
        return static::query($user)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
