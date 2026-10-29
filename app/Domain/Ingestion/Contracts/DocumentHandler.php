<?php

namespace App\Domain\Ingestion\Contracts;

use App\Models\Branch;

/**
 * Applies ONE already-validated POS document to the warehouse.
 *
 * $document shape (architecture §4.2/§4.3):
 *   ['type' => 'order', 'uuid' => '…', 'version' => 18240, 'op' => 'upsert'|'delete',
 *    'occurred_at' => '…', 'data' => [...] | null]
 *
 * Rules every implementation follows:
 *  - Version guard: apply only if $document['version'] > stored source_version for (branch_id, uuid); otherwise return HandleResult::skipped().
 *  - op 'delete' => soft delete (deleted_at = now, source_version = version).
 *  - Child rows are replaced (delete + insert) when the parent is applied.
 *  - Runs inside a DB transaction opened by the caller.
 *  - Returns every business_date whose aggregates must be recomputed: the new one AND the previously stored one if it changed.
 */
interface DocumentHandler
{
    /** The document type this handler accepts, e.g. 'order'. */
    public function type(): string;

    /** @param  array<string, mixed>  $document */
    public function handle(Branch $branch, array $document): HandleResult;
}
