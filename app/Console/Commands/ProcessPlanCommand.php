<?php

namespace App\Console\Commands;

use App\Models\Commercial\ProjectPlan;
use App\Services\Plans\PlanService;
use Illuminate\Console\Command;

/**
 * Procesa un plano cargado (DWG/DXF → JSON). Lo lanza PlanService::dispatch() en segundo
 * plano tras la carga; también sirve para reprocesar a mano:  php artisan plans:process 7
 */
class ProcessPlanCommand extends Command
{
    protected $signature = 'plans:process {plan : id de project_plans}';

    protected $description = 'Convierte y procesa el plano de un proyecto para el plano interactivo';

    public function handle(PlanService $plans): int
    {
        $plan = ProjectPlan::findOrFail($this->argument('plan'));
        $started = microtime(true);

        $plan = $plans->process($plan);
        $seconds = round(microtime(true) - $started, 1);

        if ($plan->isReady()) {
            $this->info("Plano {$plan->id} listo: {$plan->lots_detected} lotes en {$seconds} s.");

            return self::SUCCESS;
        }

        $this->error("Plano {$plan->id} con error: {$plan->error_message}");

        return self::FAILURE;
    }
}
