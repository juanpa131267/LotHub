<?php

namespace App\Models\Payments;

use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación (webhook) recibida de la pasarela (CU-21). Se guarda siempre, con firma válida
 * o no; el payload se depura antes de guardarse y nunca contiene datos de tarjeta (RN-18).
 */
class GatewayNotification extends Model
{
    protected $table = 'gateway_notifications';

    public $timestamps = false;

    protected $fillable = [
        'transaction_id', 'external_reference', 'event_type', 'payload', 'signature_valid', 'received_at', 'processed_at',
        'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'payload'         => 'array',
            'signature_valid' => 'boolean',
            'received_at'     => 'datetime:Y-m-d H:i:s',
            'processed_at'    => 'datetime:Y-m-d H:i:s',
            'reviewed_at'     => 'datetime:Y-m-d H:i',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(GatewayTransaction::class, 'transaction_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
