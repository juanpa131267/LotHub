<?php

namespace App\Console\Commands;

use App\Services\Commercial\ReservationService;
use Illuminate\Console\Command;

/**
 * RN-03 Vence las reservas cuya fecha pasó y libera sus lotes. Respaldo del evento
 * ev_expire_reservations de la base de datos; lo ejecuta el planificador (routes/console.php).
 */
class ExpireReservationsCommand extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Marca como VENCIDAS las reservas activas cuya fecha de vencimiento pasó y libera sus lotes';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->expireOverdue();
        $this->info($count ? "{$count} reserva(s) vencida(s); sus lotes volvieron a estar disponibles." : 'No hay reservas vencidas.');

        return self::SUCCESS;
    }
}
