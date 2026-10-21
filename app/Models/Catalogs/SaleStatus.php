<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/**
 * Estados de negociación. Catálogo cerrado: sales.active_lot_id (VIGENTE o PAGADA), el CHECK
 * chk_sale_cancel y el trigger trg_sale_after_update (ANULADA o DESISTIDA liberan el lote, RN-08).
 */
class SaleStatus extends Model
{
    const VIGENTE   = 1;
    const PAGADA    = 2;
    const ANULADA   = 3;
    const DESISTIDA = 4;

    protected $table = 'sale_statuses';

    public $timestamps = false;
}
