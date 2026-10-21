<?php

namespace App\Console\Commands;

use App\Services\Communications\CollectionNotifier;
use Illuminate\Console\Command;

/** CU-27 Programa los recordatorios de vencimiento y los avisos de mora del día (RN-23). */
class ScheduleNotificationsCommand extends Command
{
    protected $signature = 'notifications:schedule';

    protected $description = 'Programa los recordatorios y avisos de cobro del día según la Ley 2300 de 2023';

    public function handle(CollectionNotifier $notifier): int
    {
        $result = $notifier->schedule();
        $this->info("Programados: {$result['reminders']} recordatorio(s) y {$result['overdue']} aviso(s) de mora.");

        return self::SUCCESS;
    }
}
