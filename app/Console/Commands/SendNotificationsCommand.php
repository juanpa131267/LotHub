<?php

namespace App\Console\Commands;

use App\Services\Communications\CollectionNotifier;
use Illuminate\Console\Command;

/** CU-27 Envía las comunicaciones programadas; las de cobro solo dentro del horario permitido (RN-23). */
class SendNotificationsCommand extends Command
{
    protected $signature = 'notifications:send';

    protected $description = 'Envía las comunicaciones programadas cuya hora llegó';

    public function handle(CollectionNotifier $notifier): int
    {
        $r = $notifier->send();
        $this->info("Enviadas {$r['sent']}, fallidas {$r['failed']}, reprogramadas {$r['rescheduled']}, canceladas {$r['cancelled']}.");

        return self::SUCCESS;
    }
}
