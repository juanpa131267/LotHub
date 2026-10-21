<?php

/*
|--------------------------------------------------------------------------
| Planificador del sistema (CU-27 Ejecutar tareas programadas)
|--------------------------------------------------------------------------
| En el servidor basta una tarea del sistema operativo cada minuto:
|   * * * * * cd /ruta/lothub && php artisan schedule:run >> /dev/null 2>&1
| (en Windows: Programador de tareas con "php artisan schedule:run" cada minuto).
*/

use Illuminate\Support\Facades\Schedule;

// RN-03: respaldo del evento ev_expire_reservations por si el event_scheduler está apagado.
Schedule::command('reservations:expire')->everyFifteenMinutes()->withoutOverlapping();

// RN-19: liquidación diaria de intereses de mora (idempotente por fecha).
Schedule::command('portfolio:late-charges')->dailyAt('00:30')->withoutOverlapping();

// CU-27 / RN-23: comunicaciones de cobro (se programan a primera hora y se envían dentro del horario legal).
Schedule::command('notifications:schedule')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('notifications:send')->everyFifteenMinutes()->withoutOverlapping();

// CU-20 4b: consulta en Wompi y vence las transacciones abandonadas.
Schedule::command('gateway:expire')->everyFifteenMinutes()->withoutOverlapping();
