<?php

namespace App\Models\Aggregates;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model for agg_branch_hourly (composite primary key; write with DB::table()->upsert / insert, never save()).
 * Written only by App\Domain\Metrics aggregators; read only through MetricsQuery.
 */
class AggBranchHourly extends Model
{
    protected $table = 'agg_branch_hourly';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
        ];
    }
}
