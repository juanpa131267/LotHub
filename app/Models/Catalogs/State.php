<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Departamento (código DANE de 2 dígitos). */
class State extends Model
{
    protected $table = 'states';

    public $timestamps = false;

    protected $fillable = ['name', 'dane_code'];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
}
