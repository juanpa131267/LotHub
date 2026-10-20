<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/**
 * Estados de proyecto. Catálogo cerrado: los ids están fijos en el script SQL
 * (la vista v_lot_availability filtra por EN_VENTA = 2), por eso no tiene CRUD.
 */
class ProjectStatus extends Model
{
    const EN_PLANEACION = 1;
    const EN_VENTA      = 2;
    const VENDIDO       = 3;
    const SUSPENDIDO    = 4;
    const CERRADO       = 5;

    protected $table = 'project_statuses';

    public $timestamps = false;
}
