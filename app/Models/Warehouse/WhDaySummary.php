<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Warehouse mirror of a POS document (table wh_day_summaries). Written only by App\Domain\Ingestion. */
class WhDaySummary extends Model
{
    use SoftDeletes;

    protected $table = 'wh_day_summaries';

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
