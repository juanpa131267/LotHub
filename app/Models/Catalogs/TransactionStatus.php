<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/** Estados de una transacción de la pasarela. RN-17: solo una APROBADA genera abono. */
class TransactionStatus extends Model
{
    const PENDIENTE = 1;
    const APROBADA  = 2;
    const RECHAZADA = 3;
    const FALLIDA   = 4;
    const EXPIRADA  = 5;

    protected $table = 'transaction_statuses';

    public $timestamps = false;
}
