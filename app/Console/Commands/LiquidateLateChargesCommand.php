<?php

namespace App\Console\Commands;

use App\Services\Financing\LateChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** RN-19 Liquida los intereses de mora de las cuotas vencidas (diario, idempotente por fecha). */
class LiquidateLateChargesCommand extends Command
{
    protected $signature = 'portfolio:late-charges {--date= : fecha de liquidación (AAAA-MM-DD), por defecto hoy}';

    protected $description = 'Liquida el interés de mora de las cuotas vencidas con saldo (RN-19)';

    public function handle(LateChargeService $service): int
    {
        $result = $service->run($this->option('date') ? CarbonImmutable::parse($this->option('date')) : null);

        $this->info("Mora liquidada en {$result['installments']} cuota(s) por $ ".number_format($result['amount'], 0, ',', '.').'.');

        return self::SUCCESS;
    }
}
