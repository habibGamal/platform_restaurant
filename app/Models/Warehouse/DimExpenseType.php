<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table dim_expense_types). Written only by App\Domain\Ingestion. */
class DimExpenseType extends Model
{
    use SoftDeletes;

    protected $table = 'dim_expense_types';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ingested_at' => 'datetime',
        ];
    }
}
