<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;

/** Warehouse mirror of a POS document (table wh_inventory_day_rows). Written only by App\Domain\Ingestion. */
class WhInventoryDayRow extends Model
{
    protected $table = 'wh_inventory_day_rows';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
        ];
    }
}
