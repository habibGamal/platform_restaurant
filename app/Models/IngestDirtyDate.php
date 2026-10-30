<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $branch_id
 * @property int $ingest_batch_id
 * @property Carbon|string $business_date
 * @property int $generation
 * @property string $status
 * @property int $dispatch_attempts
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $recomputed_at
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization|null $organization
 * @property-read Branch|null $branch
 * @property-read IngestBatch|null $ingestBatch
 */
class IngestDirtyDate extends Model
{
    protected $table = 'ingest_dirty_dates';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'generation' => 'integer',
            'dispatched_at' => 'datetime',
            'recomputed_at' => 'datetime',
            'dispatch_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<IngestBatch, $this> */
    public function ingestBatch(): BelongsTo
    {
        return $this->belongsTo(IngestBatch::class);
    }
}
