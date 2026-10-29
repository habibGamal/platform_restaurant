<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table wh_expenses). Written only by App\Domain\Ingestion. */
class WhExpense extends Model
{
    use SoftDeletes;

    protected $table = 'wh_expenses';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'ingested_at' => 'datetime',
        ];
    }
}
