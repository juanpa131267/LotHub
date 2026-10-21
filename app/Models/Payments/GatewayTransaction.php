<?php

namespace App\Models\Payments;

use App\Models\Catalogs\TransactionStatus;
use App\Models\Commercial\Client;
use App\Models\Financing\Obligation;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Intento de pago en línea (CU-20). internal_reference viaja a la pasarela y
 * external_reference es el id que ella devuelve. RN-17: una transacción genera como máximo
 * un abono (payments.gateway_transaction_id es UNIQUE).
 */
class GatewayTransaction extends Model
{
    protected $table = 'gateway_transactions';

    public $timestamps = false;

    protected $fillable = [
        'gateway_id', 'obligation_id', 'client_id', 'internal_reference', 'external_reference', 'amount', 'reported_amount',
        'currency', 'payment_method_type', 'status_id', 'requested_at', 'confirmed_at', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'          => 'decimal:2',
            'reported_amount' => 'decimal:2',
            'requested_at'    => 'datetime:Y-m-d H:i',
            'confirmed_at'    => 'datetime:Y-m-d H:i',
            'reviewed_at'     => 'datetime:Y-m-d H:i',
        ];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TransactionStatus::class, 'status_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(GatewayNotification::class, 'transaction_id')->latest('id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function isApproved(): bool
    {
        return (int) $this->status_id === TransactionStatus::APROBADA;
    }

    /** El valor informado por la pasarela difiere del solicitado (CU-17.2 1a). */
    public function hasAmountMismatch(): bool
    {
        return $this->reported_amount !== null && abs((float) $this->reported_amount - (float) $this->amount) >= 0.01;
    }
}
