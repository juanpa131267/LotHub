<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/** Tipos de documento soporte (promesa, soporte de pago...). */
class DocumentType extends Model
{
    protected $table = 'document_types';

    public $timestamps = false;

    protected $fillable = ['code', 'name'];
}
