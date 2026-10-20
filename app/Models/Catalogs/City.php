<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Municipio (código DANE de 5 dígitos). */
class City extends Model
{
    protected $table = 'cities';

    public $timestamps = false;

    protected $fillable = ['state_id', 'name', 'dane_code'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** Opciones "Municipio (Departamento)" para los selects de formularios. */
    public static function options(): array
    {
        return static::with('state')->orderBy('name')->get()
            ->mapWithKeys(fn (City $c) => [$c->id => "{$c->name} ({$c->state->name})"])
            ->all();
    }
}
