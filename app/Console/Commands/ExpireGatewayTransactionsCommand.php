<?php

namespace App\Console\Commands;

use App\Services\Payments\OnlinePaymentService;
use Illuminate\Console\Command;

/** CU-20 4b: consulta en Wompi y vence las transacciones abandonadas. Lo ejecuta el planificador. */
class ExpireGatewayTransactionsCommand extends Command
{
    protected $signature = 'gateway:expire';

    protected $description = 'Marca como EXPIRADAS las transacciones de la pasarela que siguen pendientes después del tiempo de pago';

    public function handle(OnlinePaymentService $online): int
    {
        $count = $online->expireStale();
        $this->info($count ? "{$count} transacción(es) expirada(s)." : 'No hay transacciones pendientes vencidas.');

        return self::SUCCESS;
    }
}
