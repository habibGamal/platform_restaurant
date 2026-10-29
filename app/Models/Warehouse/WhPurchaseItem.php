<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;

/** Warehouse mirror of a POS document (table wh_purchase_items). Written only by App\Domain\Ingestion. */
class WhPurchaseItem extends Model
{
    protected $table = 'wh_purchase_items';

    public $timestamps = false;

    protected $guarded = [];
}
