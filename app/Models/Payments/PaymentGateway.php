<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;

/** Pasarela de pagos por ambiente (SANDBOX / PRODUCCION). */
class PaymentGateway extends Model
{
    protected $table = 'payment_gateways';

    public $timestamps = false;

    protected $fillable = ['name', 'environment', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Registro de Wompi para el ambiente configurado (lothub.wompi.environment). */
    public static function wompi(): self
    {
        $environment = config('lothub.wompi.environment') === 'production' ? 'PRODUCCION' : 'SANDBOX';

        return static::firstOrCreate(['name' => 'Wompi', 'environment' => $environment], ['is_active' => true]);
    }
}
