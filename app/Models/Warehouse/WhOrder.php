<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table wh_orders). Written only by App\Domain\Ingestion. */
class WhOrder extends Model
{
    use SoftDeletes;

    protected $table = 'wh_orders';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'created_at_local' => 'datetime',
            'ingested_at' => 'datetime',
        ];
    }
}
