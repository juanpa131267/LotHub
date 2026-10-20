<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/** Tipos de identificación (CC, CE, NIT...). */
class IdentificationType extends Model
{
    protected $table = 'identification_types';

    public $timestamps = false;

    protected $fillable = ['code', 'name'];
}
