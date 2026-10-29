<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestRejection extends Model
{
    protected $table = 'ingest_rejections';

    protected $guarded = [];

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
    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngestBatch::class, 'ingest_batch_id');
    }
}
