<?php

namespace App\Models\Financing;

use App\Models\Catalogs\PaymentFrequency;
use App\Models\Commercial\Sale;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Acuerdo de pago de una negociación (CU-13). Cada reestructuración crea una versión nueva
 * (UNIQUE sale_id + version, RN-11); la vigente es la de mayor versión (v_current_agreement).
 * Las tasas se guardan en porcentaje mensual (1.5 = 1,5 % mensual).
 */
class PaymentAgreement extends Model
{
    protected $table = 'payment_agreements';

    const UPDATED_AT = null;

    protected $fillable = [
        'sale_id', 'version', 'down_payment', 'financed_amount', 'installments_count', 'frequency_id',
        'monthly_interest_rate', 'late_interest_rate', 'first_due_date', 'signed_at', 'restructure_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'down_payment'          => 'decimal:2',
            'financed_amount'       => 'decimal:2',
            'monthly_interest_rate' => 'decimal:4',
            'late_interest_rate'    => 'decimal:4',
            'first_due_date'        => 'date:Y-m-d',
            'signed_at'             => 'date:Y-m-d',
            'created_at'            => 'datetime:Y-m-d H:i',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function frequency(): BelongsTo
    {
        return $this->belongsTo(PaymentFrequency::class, 'frequency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function obligation(): HasOne
    {
        return $this->hasOne(Obligation::class, 'agreement_id');
    }
}
