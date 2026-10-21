<?php

/*
|--------------------------------------------------------------------------
| Planificador del sistema (CU-27 Ejecutar tareas programadas)
|--------------------------------------------------------------------------
| En el servidor basta una tarea del sistema operativo cada minuto:
|   * * * * * cd /ruta/lothub && php artisan schedule:run >> /dev/null 2>&1
| (en Windows: Programador de tareas con "php artisan schedule:run" cada minuto).
*/

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Latido del planificador: lothub:check verifica que schedule:run se esté ejecutando.
Schedule::call(fn () => Cache::put('lothub:scheduler:heartbeat', now()->toDateTimeString(), now()->addDay()))->everyMinute()->name('heartbeat');

// S11: respaldo diario de la base de datos y de los archivos privados.
Schedule::command('lothub:backup')->dailyAt('02:00')->withoutOverlapping();

// RN-03: respaldo del evento ev_expire_reservations por si el event_scheduler está apagado.
Schedule::command('reservations:expire')->everyFifteenMinutes()->withoutOverlapping();

// RN-19: liquidación diaria de intereses de mora (idempotente por fecha).
Schedule::command('portfolio:late-charges')->dailyAt('00:30')->withoutOverlapping();

// CU-27 / RN-23: comunicaciones de cobro (se programan a primera hora y se envían dentro del horario legal).
Schedule::command('notifications:schedule')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('notifications:send')->everyFifteenMinutes()->withoutOverlapping();

// CU-20 4b: consulta en Wompi y vence las transacciones abandonadas.
Schedule::command('gateway:expire')->everyFifteenMinutes()->withoutOverlapping();
