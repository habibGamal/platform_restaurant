<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;

/** Warehouse mirror of a POS document (table wh_order_return_items). Written only by App\Domain\Ingestion. */
class WhOrderReturnItem extends Model
{
    protected $table = 'wh_order_return_items';

    public $timestamps = false;

    protected $guarded = [];
}
