<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;

/** Warehouse mirror of a POS document (table dim_recipes). Written only by App\Domain\Ingestion. */
class DimRecipe extends Model
{
    protected $table = 'dim_recipes';

    public $timestamps = false;

    protected $guarded = [];
}
