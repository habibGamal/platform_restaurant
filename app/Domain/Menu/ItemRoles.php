<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use App\Models\ItemRoleAssignment;

final class ItemRoles
{
    public function mapFor(int $organizationId): ItemRoleMap
    {
        $threshold = (float) config('ai.roles.trusted_confidence', 0.70);

        $trusted = [];
        $rows = ItemRoleAssignment::query()
            ->where('organization_id', $organizationId)
            ->get(['item_key', 'role', 'source', 'confidence']);

        foreach ($rows as $row) {
            $role = ItemRole::tryFrom($row->role);
            if ($role === null) {
                continue;
            }
            if ($row->source === 'owner' || (float) $row->confidence >= $threshold) {
                $trusted[$row->item_key] = $role;
            }
        }

        return new ItemRoleMap($trusted);
    }

    public function setOwnerRole(int $organizationId, string $itemKey, ItemRole $role): void
    {
        ItemRoleAssignment::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'item_key' => $itemKey],
            [
                'role' => $role->value,
                'source' => 'owner',
                'confidence' => 1.00,
                'classified_at' => now(),
            ],
        );
    }
}
