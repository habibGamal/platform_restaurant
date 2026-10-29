<?php

namespace App\Models\Aggregates;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model for agg_branch_daily (composite primary key; write with DB::table()->upsert / insert, never save()).
 * Written only by App\Domain\Metrics aggregators; read only through MetricsQuery.
 */
class AggBranchDaily extends Model
{
    protected $table = 'agg_branch_daily';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'computed_at' => 'datetime',
            'sales_by_type' => 'array',
            'sales_by_method' => 'array',
            'is_day_closed' => 'boolean',
            'is_reconciled' => 'boolean',
        ];
    }
}
