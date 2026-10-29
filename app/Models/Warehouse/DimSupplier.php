<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table dim_suppliers). Written only by App\Domain\Ingestion. */
class DimSupplier extends Model
{
    use SoftDeletes;

    protected $table = 'dim_suppliers';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ingested_at' => 'datetime',
        ];
    }
}
