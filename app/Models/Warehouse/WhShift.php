<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table wh_shifts). Written only by App\Domain\Ingestion. */
class WhShift extends Model
{
    use SoftDeletes;

    protected $table = 'wh_shifts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'ingested_at' => 'datetime',
            'has_deficit' => 'boolean',
            'closed' => 'boolean',
        ];
    }
}
