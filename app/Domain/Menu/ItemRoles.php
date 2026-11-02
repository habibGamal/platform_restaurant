<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use App\Models\ItemRoleAssignment;
use App\Models\Organization;
use Throwable;

final class ItemRoles
{
    /** Non-owner sources whose assignments are trusted once they reach the confidence threshold. */
    private const TRUSTED_SOURCES = ['signal', 'auto'];

    public function mapFor(int $organizationId): ItemRoleMap
    {
        $trusted = [];
        $rows = ItemRoleAssignment::query()
            ->where('organization_id', $organizationId)
            ->get(['item_key', 'role', 'source', 'confidence']);

        foreach ($rows as $row) {
            $role = ItemRole::tryFrom($row->role);
            if ($role !== null && self::isTrustedRow($row->source, (float) $row->confidence, $row->role)) {
                $trusted[$row->item_key] = $role;
            }
        }

        return new ItemRoleMap($trusted);
    }

    /** The single trust rule: a valid role that the owner set, or that a signal/auto source assigned at or above the threshold. */
    public static function isTrustedRow(string $source, float $confidence, ?string $role): bool
    {
        if ($role === null || ItemRole::tryFrom($role) === null) {
            return false;
        }

        return $source === 'owner'
            || (in_array($source, self::TRUSTED_SOURCES, true)
                && $confidence >= (float) config('ai.roles.trusted_confidence', 0.70));
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

    /**
     * The trusted role map for the organization, after making one attempt to classify any of the
     * given items that have no trusted role yet. A classification failure is reported, never thrown:
     * the returned map then simply still lacks those items.
     *
     * @param  list<string>  $itemKeys
     */
    public function ensureClassified(Organization $organization, array $itemKeys): ItemRoleMap
    {
        $map = $this->mapFor($organization->id);
        $untrusted = $map->untrustedAmong($itemKeys);

        if ($untrusted === []) {
            return $map;
        }

        try {
            app(RoleClassifier::class)->classify($organization, $untrusted);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->mapFor($organization->id);
    }
}
