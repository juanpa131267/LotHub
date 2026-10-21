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

    // CU-13 Acuerdos de pago. Las tasas se expresan en % mensual. RN-19: ninguna tasa (corriente
    // ni de mora) supera el límite de usura vigente; actualízalo cada mes con la certificación de
    // la Superintendencia Financiera (tasa efectiva anual convertida a mensual).
    'financing' => [
        'max_monthly_rate' => (float) env('USURY_MONTHLY_RATE', 2.10),
    ],

    // RN-19 Liquidación de mora: días de gracia después del vencimiento antes de liquidar.
    'portfolio' => [
        'grace_days' => (int) env('LATE_CHARGE_GRACE_DAYS', 0),
    ],

    // Motor de asignación automática de pagos (Sprint 6, ver docs/ASIGNACION.md).
    // Se asigna solo si el mejor candidato alcanza el umbral y supera al segundo por el margen.
    'allocation' => [
        'auto_threshold' => (int) env('ALLOCATION_AUTO_THRESHOLD', 75),
        'min_margin'     => (int) env('ALLOCATION_MIN_MARGIN', 20),
    ],

    // Pasarela Wompi (Sprint 7, ver docs/PASARELA.md). Las llaves se obtienen en el panel de
    // comercios de Wompi (Desarrolladores → Llaves y Secretos); nunca se guardan en la base de datos.
    'wompi' => [
        'environment'        => env('WOMPI_ENV', 'sandbox'),             // sandbox | production
        'public_key'         => env('WOMPI_PUBLIC_KEY'),
        'private_key'        => env('WOMPI_PRIVATE_KEY'),                // opcional: consulta por referencia
        'integrity_secret'   => env('WOMPI_INTEGRITY_SECRET'),
        'events_secret'      => env('WOMPI_EVENTS_SECRET'),
        'checkout_url'       => env('WOMPI_CHECKOUT_URL', 'https://checkout.wompi.co/p/'),
        'expiration_minutes' => (int) env('WOMPI_EXPIRATION_MINUTES', 60),
        'min_amount'         => (float) env('WOMPI_MIN_AMOUNT', 10000),
    ],

    // CU-27 Comunicaciones al cliente (RN-23, Ley 2300 de 2023). Los horarios permitidos, domingos y
    // festivos están en App\Services\Communications\CollectionNotifier y App\Support\ColombianCalendar.
    'notifications' => [
        'reminder_days_before' => array_map('intval', explode(',', env('NOTIFY_REMINDER_DAYS', '3'))),
        'overdue_days'         => array_map('intval', explode(',', env('NOTIFY_OVERDUE_DAYS', '1,8,15,30,60,90'))),
        'send_hour'            => (int) env('NOTIFY_SEND_HOUR', 9),
        // Proveedores de SMS y WhatsApp: mientras no se configuren, esos canales no se usan automáticamente.
        'providers' => [
            'sms'      => env('SMS_PROVIDER'),
            'whatsapp' => env('WHATSAPP_PROVIDER'),
        ],
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
