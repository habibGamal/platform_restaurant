<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;

/** Warehouse mirror of a POS document (table wh_payments). Written only by App\Domain\Ingestion. */
class WhPayment extends Model
{
    protected $table = 'wh_payments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }
}
