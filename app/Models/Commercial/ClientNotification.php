<?php

namespace App\Models\Commercial;

use App\Models\Financing\Installment;
use App\Models\Payments\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comunicación al cliente (tabla notifications): recordatorio, aviso de mora o confirmación de pago. */
class ClientNotification extends Model
{
    const REMINDER = 'RECORDATORIO_VENCIMIENTO';
    const OVERDUE = 'AVISO_MORA';
    const PAYMENT = 'CONFIRMACION_PAGO';
    const GENERAL = 'GENERAL';

    /** Tipos sujetos a las reglas de comunicaciones de cobro de la Ley 2300 de 2023. */
    const COLLECTION_TYPES = [self::REMINDER, self::OVERDUE];

    const TYPES = [
        self::REMINDER => 'Recordatorio de vencimiento',
        self::OVERDUE  => 'Aviso de mora',
        self::PAYMENT  => 'Confirmación de pago',
        self::GENERAL  => 'General',
    ];

    protected $table = 'notifications';

    public $timestamps = false;

    protected $fillable = [
        'client_id', 'installment_id', 'payment_id', 'channel', 'notification_type', 'message',
        'scheduled_at', 'sent_at', 'status', 'error_message',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime:Y-m-d H:i', 'sent_at' => 'datetime:Y-m-d H:i'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->notification_type] ?? $this->notification_type;
    }
}
