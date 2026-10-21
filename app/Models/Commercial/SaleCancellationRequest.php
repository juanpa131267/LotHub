<?php

namespace App\Models\Commercial;

use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitud de anulación o desistimiento de una negociación (CU-15, parche 004). */
class SaleCancellationRequest extends Model
{
    const PENDING = 'PENDIENTE';
    const APPROVED = 'APROBADA';
    const REJECTED = 'RECHAZADA';

    const TYPES = ['ANULACION' => 'Anulación', 'DESISTIMIENTO' => 'Desistimiento del comprador'];

    protected $table = 'sale_cancellation_requests';

    public $timestamps = false;

    protected $fillable = [
        'sale_id', 'request_type', 'reason', 'status', 'requested_by', 'requested_at',
        'resolved_by', 'resolved_at', 'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime:Y-m-d H:i',
            'resolved_at'  => 'datetime:Y-m-d H:i',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->request_type] ?? $this->request_type;
    }
}
