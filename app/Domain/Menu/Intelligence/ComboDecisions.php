<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use App\Models\Menu\ComboCandidate;
use InvalidArgumentException;

/**
 * Handles owner decisions (approved, rejected, or candidate) on nightly combo proposals.
 */
final class ComboDecisions
{
    /** @var list<string> */
    public const VALID_STATUSES = ['approved', 'rejected', 'candidate'];

    /**
     * Updates every combo_candidates row of the organization with the given signature
     * across all periods, ensuring decisions persist across nightly rebuilds.
     *
     * @return int The number of affected rows.
     */
    public static function apply(int $organizationId, string $signature, string $status, ?string $note): int
    {
        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid combo status: {$status}");
        }

        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        return ComboCandidate::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('signature', $signature)
            ->update([
                'status' => $status,
                'owner_note' => $note,
            ]);
    }
}
