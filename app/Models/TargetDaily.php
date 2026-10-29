<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Daily distribution of a monthly target (composite PK target_id+date; write with DB::table()). */
class TargetDaily extends Model
{
    protected $table = 'target_daily';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'decimal:4',
        ];
    }
}
