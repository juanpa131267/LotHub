<?php

namespace App\Services\Commercial;

use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\ReservationStatus;
use App\Models\Commercial\Client;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Reservation;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CU-11 Registrar reserva de lote. Las reglas que garantiza el motor no se duplican aquí:
 * RN-04 (trg_res_before_insert / after_insert), RN-02 (UNIQUE active_lot_id) y la liberación
 * al anular (trg_res_after_update, parche 004). Este servicio valida lo que el motor no ve.
 */
class ReservationService
{
    public function reserve(Lot $lot, Client $client, CarbonInterface $expiresAt, float $deposit, ?string $notes = null): Reservation
    {
        if ((int) $lot->status_id !== LotStatus::DISPONIBLE) {
            throw new RuntimeException("El lote {$lot->label} no está disponible (estado: {$lot->status?->name}).");
        }
        if ($expiresAt->lte(now())) {
            throw new RuntimeException('La fecha de vencimiento debe ser posterior al momento actual.');
        }
        $maxDays = (int) config('lothub.reservations.max_days');
        if ($expiresAt->gt(now()->addDays($maxDays)->endOfDay())) {
            throw new RuntimeException("La reserva no puede superar {$maxDays} días.");
        }

        return DB::transaction(function () use ($lot, $client, $expiresAt, $deposit, $notes) {
            $reservation = Reservation::create([
                'lot_id'         => $lot->id,
                'client_id'      => $client->id,
                'advisor_id'     => auth()->id(),
                'reserved_at'    => now(),
                'expires_at'     => $expiresAt,
                'deposit_amount' => $deposit,
                'status_id'      => ReservationStatus::ACTIVA,
                'notes'          => $notes,
            ]);

            AuditLogger::model('RESERVAR_LOTE', $reservation, [], [
                'lote' => $lot->id, 'cliente' => $client->id, 'vence' => $expiresAt->format('Y-m-d H:i'), 'separacion' => $deposit,
            ]);

            return $reservation;
        });
    }

    /** CU-11 4b: la reserva pasa a ANULADA y el trigger devuelve el lote a DISPONIBLE. */
    public function cancel(Reservation $reservation, string $reason): void
    {
        if (! $reservation->isActive()) {
            throw new RuntimeException('Solo se anulan reservas activas.');
        }

        DB::transaction(function () use ($reservation, $reason) {
            $reservation->update([
                'status_id' => ReservationStatus::ANULADA,
                'notes'     => mb_substr(trim(($reservation->notes ? $reservation->notes.' · ' : '').'Anulada: '.$reason), 0, 255),
            ]);
            AuditLogger::model('ANULAR_RESERVA', $reservation, ['estado' => 'ACTIVA'], ['estado' => 'ANULADA', 'motivo' => $reason]);
        });
    }

    /**
     * RN-03 respaldo del evento ev_expire_reservations (por si el event_scheduler del servidor
     * está apagado). Lo ejecuta el planificador de Laravel: php artisan reservations:expire
     */
    public function expireOverdue(): int
    {
        return DB::transaction(function () {
            $ids = Reservation::active()->where('expires_at', '<', now())->lockForUpdate()->pluck('id', 'lot_id');
            if ($ids->isEmpty()) {
                return 0;
            }

            Lot::whereIn('id', $ids->keys())->where('status_id', LotStatus::RESERVADO)->update(['status_id' => LotStatus::DISPONIBLE]);
            Reservation::whereIn('id', $ids->values())->update(['status_id' => ReservationStatus::VENCIDA]);
            AuditLogger::log('VENCER_RESERVAS', 'reservations', 0, [], ['reservas' => $ids->values()->all()], null);

            return $ids->count();
        });
    }
}
