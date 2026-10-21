<?php

namespace App\Models\Payments;

use App\Models\Financing\Installment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aplicación de un abono a una cuota y concepto (CU-18). El trigger trg_alloc_before_insert
 * impide aplicar más que el abono, un abono reversado o una cuota de otra obligación (RN-13, RN-14).
 */
class PaymentAllocation extends Model
{
    const LATE = 'INTERES_MORA';
    const INTEREST = 'INTERES_CORRIENTE';
    const PRINCIPAL = 'CAPITAL';

    const CONCEPTS = [self::LATE => 'Interés de mora', self::INTEREST => 'Interés corriente', self::PRINCIPAL => 'Capital'];

    protected $table = 'payment_allocations';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['payment_id', 'installment_id', 'concept', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
