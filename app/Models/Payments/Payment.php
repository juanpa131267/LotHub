<?php

namespace App\Models\Payments;

use App\Models\Catalogs\PaymentMethod;
use App\Models\Catalogs\PaymentStatus;
use App\Models\Commercial\Client;
use App\Models\Documents\Document;
use App\Models\Financing\Obligation;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Abono (CU-17). RN-15: no se elimina ni se modifica; solo se reversa con motivo, usuario y
 * fecha (triggers trg_pay_before_delete / before_update y CHECK chk_pay_reversal).
 * RN-16: valor positivo y origen identificado (usuario o transacción de pasarela).
 */
class Payment extends Model
{
    protected $table = 'payments';

    const UPDATED_AT = null;

    protected $fillable = [
        'obligation_id', 'payer_client_id', 'payment_date', 'amount', 'payment_method_id', 'bank_reference',
        'gateway_transaction_id', 'status_id', 'registered_by', 'reversal_reason', 'reversed_by', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'amount'       => 'decimal:2',
            'reversed_at'  => 'datetime:Y-m-d H:i',
            'created_at'   => 'datetime:Y-m-d H:i',
        ];
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'payer_client_id')->withTrashed();
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(PaymentStatus::class, 'status_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by')->withTrashed();
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by')->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function statementLine(): HasOne
    {
        return $this->hasOne(BankStatementLine::class);
    }

    public function isApplied(): bool
    {
        return (int) $this->status_id === PaymentStatus::APLICADO;
    }

    /** Excedente sin aplicar a cuotas: saldo a favor del cliente (RN-14). */
    public function unallocated(): float
    {
        return round((float) $this->amount - (float) $this->allocations()->sum('amount'), 2);
    }

    /** Origen del abono para mostrarlo: pasarela, extracto (automático o revisado) o manual. */
    public function originLabel(): string
    {
        return match (true) {
            $this->gateway_transaction_id !== null                      => 'Pasarela',
            $this->statementLine?->assignment_mode === 'AUTOMATICA'     => 'Extracto · automático',
            $this->statementLine !== null                               => 'Extracto · revisado',
            default                                                     => 'Manual',
        };
    }
}
