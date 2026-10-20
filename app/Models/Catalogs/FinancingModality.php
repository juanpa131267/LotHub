<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/** Modalidades de pago: contado, financiación directa, mixta (RN-10). */
class FinancingModality extends Model
{
    protected $table = 'financing_modalities';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'requires_agreement'];

    protected function casts(): array
    {
        return ['requires_agreement' => 'boolean'];
    }
}
