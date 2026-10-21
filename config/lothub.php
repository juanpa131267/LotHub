<?php

/*
|--------------------------------------------------------------------------
| Parámetros de negocio de LotHub
|--------------------------------------------------------------------------
| Laravel 12 toma la configuración base (database, session, mail...) desde
| el framework y el archivo .env, por eso este proyecto solo publica este
| archivo con los valores propios del dominio. Si necesitas personalizar
| otro archivo usa: php artisan config:publish <nombre>
*/

return [

    // Permiso que concede acceso total (equivale a "superusuario").
    'super_permission' => 'sistema.acceso_total',

    // RN-25: bloqueo de cuenta y recuperación de contraseña.
    'auth' => [
        'max_attempts'        => 5,
        'lock_minutes'        => 15,
        'reset_token_minutes' => 30,
        // US-AUTH-008: vigencia del enlace con el que el cliente activa su cuenta del portal.
        'activation_hours'    => 72,
    ],

    // US-TEC-001: en producción toda petición se atiende por HTTPS (redirección 301 + HSTS).
    // Detrás de un proxy o balanceador, define TRUSTED_PROXIES para reconocer X-Forwarded-Proto.
    'force_https' => (bool) env('FORCE_HTTPS', false),
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),

    // CU-11 Reservas: vigencia propuesta en el formulario y máxima permitida (días).
    'reservations' => [
        'default_days' => (int) env('RESERVATION_DEFAULT_DAYS', 8),
        'max_days'     => (int) env('RESERVATION_MAX_DAYS', 30),
    ],

    // Roles que no pueden eliminarse desde la interfaz.
    'protected_roles' => ['ADMINISTRADOR'],

    // Ley 1581 de 2012: versión vigente de la política de tratamiento de datos.
    'privacy_policy_version' => '1.0',

    // Plano interactivo: procesamiento de DWG/DXF (ver docs/PLANOS.md).
    'plans' => [
        'python'     => env('PLAN_PYTHON', 'python'),
        'script'     => base_path('tools/plan-processor/process_plan.py'),
        // Conversor DWG → DXF (LibreDWG dwg2dxf). Vacío = solo se aceptan archivos DXF.
        'dwg2dxf'    => env('PLAN_DWG2DXF'),
        // El procesamiento corre en segundo plano (php artisan plans:process): un plano de
        // 40 MB / 4.500 lotes tarda ~40 s. Este es el tiempo máximo antes de darlo por fallido.
        'timeout'    => 1800,    // segundos
        // Tamaño máximo de archivo. El límite real es el menor entre este valor y los de PHP
        // (upload_max_filesize y post_max_size en php.ini): ver PlanService::maxUploadBytes().
        'max_mb'     => (int) env('PLAN_MAX_MB', 50),
        // PHP de consola para lanzar el proceso en segundo plano.
        'php'        => env('PLAN_PHP') ?: (str_contains(strtolower(basename(PHP_BINARY)), 'php') ? PHP_BINARY : 'php'),
        'directory'  => 'plans', // dentro de storage/app
    ],

    // Datos de contacto mostrados en el sitio público.
    'company' => [
        'name'     => 'LotHub Inmobiliaria',
        'city'     => 'Girardot, Cundinamarca',
        'phone'    => '+57 300 000 0000',
        'email'    => 'contacto@lothub.local',
    ],
];
