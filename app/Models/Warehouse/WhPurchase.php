<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table wh_purchases). Written only by App\Domain\Ingestion. */
class WhPurchase extends Model
{
    use SoftDeletes;

    protected $table = 'wh_purchases';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'closed_at' => 'datetime',
            'ingested_at' => 'datetime',
        ];
    }
}
