<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/** Estados de obligación. Catálogo cerrado: v_obligation_balance solo consolida las ACTIVAS. */
class ObligationStatus extends Model
{
    const ACTIVA         = 1;
    const CANCELADA      = 2;
    const REESTRUCTURADA = 3;
    const ANULADA        = 4;

    protected $table = 'obligation_statuses';

    public $timestamps = false;
}
