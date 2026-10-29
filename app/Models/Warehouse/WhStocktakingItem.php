<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;

/** Warehouse mirror of a POS document (table wh_stocktaking_items). Written only by App\Domain\Ingestion. */
class WhStocktakingItem extends Model
{
    protected $table = 'wh_stocktaking_items';

    public $timestamps = false;

    protected $guarded = [];
}
