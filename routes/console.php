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
