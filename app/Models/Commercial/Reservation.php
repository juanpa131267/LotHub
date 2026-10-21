<?php

namespace App\Models\Commercial;

use App\Models\Catalogs\ReservationStatus;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Reserva (separación temporal) de un lote para un cliente — CU-11, RF-08.
 * Los triggers garantizan RN-04 (solo lotes DISPONIBLES; el lote pasa a RESERVADO) y la
 * columna active_lot_id, RN-02 (una sola reserva ACTIVA por lote).
 */
class Reservation extends Model
{
    protected $table = 'reservations';

    public $timestamps = false;

    protected $fillable = [
        'lot_id', 'client_id', 'advisor_id', 'reserved_at', 'expires_at', 'deposit_amount', 'status_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at'    => 'datetime:Y-m-d H:i',
            'expires_at'     => 'datetime:Y-m-d H:i',
            'deposit_amount' => 'decimal:2',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id')->withTrashed();
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ReservationStatus::class, 'status_id');
    }

    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status_id', ReservationStatus::ACTIVA);
    }

    public function isActive(): bool
    {
        return (int) $this->status_id === ReservationStatus::ACTIVA;
    }

    /** Días que faltan para el vencimiento (negativo si ya venció). */
    public function daysLeft(): int
    {
        return (int) floor(now()->diffInDays($this->expires_at, false));
    }
}
