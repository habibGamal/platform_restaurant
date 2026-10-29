<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;

/** Warehouse mirror of a POS document (table wh_waste_items). Written only by App\Domain\Ingestion. */
class WhWasteItem extends Model
{
    protected $table = 'wh_waste_items';

    public $timestamps = false;

    protected $guarded = [];
}
