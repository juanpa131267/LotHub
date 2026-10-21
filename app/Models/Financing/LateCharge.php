<?php

namespace App\Models\Financing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Interés de mora liquidado sobre una cuota en una fecha (RN-19). UNIQUE installment_id +
 * calculated_on: la liquidación diaria es idempotente. Suma en amount_due de v_installment_balance.
 */
class LateCharge extends Model
{
    protected $table = 'late_charges';

    public $timestamps = false;

    protected $fillable = ['installment_id', 'calculated_on', 'days_overdue', 'amount'];

    protected function casts(): array
    {
        return [
            'calculated_on' => 'date:Y-m-d',
            'amount'        => 'decimal:2',
        ];
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
