<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/** Estados de abono. Las vistas de saldo solo suman los APLICADOS (RN-15). */
class PaymentStatus extends Model
{
    const APLICADO  = 1;
    const REVERSADO = 2;

    protected $table = 'payment_statuses';

    public $timestamps = false;
}
