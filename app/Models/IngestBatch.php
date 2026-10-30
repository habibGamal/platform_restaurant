<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $branch_id
 * @property string $batch_uuid
 * @property int $schema_version
 * @property string|null $pos_version
 * @property int|null $seq_from
 * @property int|null $seq_to
 * @property int $docs_count
 * @property int $accepted_count
 * @property int $rejected_count
 * @property string $status
 * @property string|null $claim_token
 * @property int $fencing_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $heartbeat_at
 * @property array<string, mixed>|null $result
 * @property string|null $storage_path
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Branch|null $branch
 * @property-read Organization|null $organization
 */
class IngestBatch extends Model
{
    protected $table = 'ingest_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'fencing_token' => 'integer',
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

    /** @return HasMany<IngestDirtyDate, $this> */
    public function dirtyDates(): HasMany
    {
        return $this->hasMany(IngestDirtyDate::class);
    }
}
