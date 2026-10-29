<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table wh_inventory_days). Written only by App\Domain\Ingestion. */
class WhInventoryDay extends Model
{
    use SoftDeletes;

    protected $table = 'wh_inventory_days';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'ingested_at' => 'datetime',
        ];
    }
}
