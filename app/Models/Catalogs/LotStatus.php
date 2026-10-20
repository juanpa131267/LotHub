<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/**
 * Estados de lote. Catálogo cerrado: los triggers del motor usan estos ids
 * (RN-04, RN-05, RN-08). RESERVADO y VENDIDO solo los asigna la base de datos.
 */
class LotStatus extends Model
{
    const DISPONIBLE = 1;
    const RESERVADO  = 2;
    const VENDIDO    = 3;
    const BLOQUEADO  = 4;

    protected $table = 'lot_statuses';

    public $timestamps = false;
}
