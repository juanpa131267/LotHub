<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/**
 * Estados de reserva. Catálogo cerrado: los usan los triggers y el evento de vencimiento
 * (RN-02, RN-03, RN-05) y la columna generada reservations.active_lot_id (ACTIVA = 1).
 */
class ReservationStatus extends Model
{
    const ACTIVA     = 1;
    const CONVERTIDA = 2;
    const VENCIDA    = 3;
    const ANULADA    = 4;

    protected $table = 'reservation_statuses';

    public $timestamps = false;
}
