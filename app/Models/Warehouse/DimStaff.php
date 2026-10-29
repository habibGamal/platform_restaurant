<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table dim_staff). Written only by App\Domain\Ingestion. */
class DimStaff extends Model
{
    use SoftDeletes;

    protected $table = 'dim_staff';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ingested_at' => 'datetime',
        ];
    }
}
