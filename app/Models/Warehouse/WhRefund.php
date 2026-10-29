<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Model;

/** Warehouse mirror of a POS document (table wh_refunds). Written only by App\Domain\Ingestion. */
class WhRefund extends Model
{
    protected $table = 'wh_refunds';

    public $timestamps = false;

    protected $guarded = [];
}
