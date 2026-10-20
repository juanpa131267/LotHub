<?php

namespace App\Models\Catalogs;

use Illuminate\Database\Eloquent\Model;

/** Medios de pago (efectivo, consignación, pasarela...). */
class PaymentMethod extends Model
{
    protected $table = 'payment_methods';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_online'];

    protected function casts(): array
    {
        return ['is_online' => 'boolean'];
    }
}
