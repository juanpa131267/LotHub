<?php

namespace App\Console\Commands;

use App\Models\Security\Role;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * S11 Lista de chequeo técnica para la puesta en producción: configuración segura, base de datos
 * con todos los parches, planificadores, almacenamiento, correo, pasarela y respaldos.
 * Sale con código 1 si algún punto falla (útil en el script de despliegue).
 *
 *   php artisan lothub:check            # en el servidor de producción
 *   php artisan lothub:check --strict   # las advertencias también cuentan como falla
 */
class ProductionCheckCommand extends Command
{
    protected $signature = 'lothub:check {--strict : las advertencias se tratan como fallas}';

    protected $description = 'Verifica que el entorno esté listo para producción';

    /** Tabla o columna que deja cada parche de database/schema/patches. */
    private const PATCHES = [
        '001' => ['project_plans', null],
        '002' => ['project_photos', null],
        '003' => ['users', 'must_change_password'],
        '004' => ['sale_cancellation_requests', null],
        '005' => ['bank_statement_lines', null],
        '006' => ['gateway_transactions', 'reported_amount'],
        '007' => ['notifications', 'message'],
        '008' => ['parallel_runs', null],
    ];

    private array $rows = [];

    private array $failures = ['fail' => [], 'warn' => []];

    public function handle(): int
    {
        $this->section('Aplicación');
        $this->check('APP_ENV = production', app()->environment('production'), 'warn');
        $this->check('APP_DEBUG desactivado', ! config('app.debug'));
        $this->check('APP_KEY definida', filled(config('app.key')));
        $this->check('APP_URL con https', str_starts_with((string) config('app.url'), 'https://'), 'warn');
        $this->check('HTTPS obligatorio (FORCE_HTTPS)', (bool) config('lothub.force_https'));
        $this->check('Cookie de sesión segura (SESSION_SECURE_COOKIE)', (bool) config('session.secure'), 'warn');

        $this->section('Base de datos');
        try {
            DB::select('SELECT 1');
            $this->check('Conexión a '.config('database.connections.'.config('database.default').'.database'), true);
        } catch (Throwable $e) {
            $this->check('Conexión a la base de datos', false, 'fail', $e->getMessage());

            return $this->finish();
        }
        $this->check('Esquema oficial (vistas de saldo)', $this->viewExists('v_installment_balance'));
        foreach (self::PATCHES as $patch => [$table, $column]) {
            $ok = Schema::hasTable($table) && (! $column || Schema::hasColumn($table, $column));
            $this->check("Parche {$patch}", $ok);
        }
        $scheduler = DB::selectOne('SELECT @@event_scheduler AS s')->s ?? 'OFF';
        $this->check('event_scheduler del motor activo', strtoupper((string) $scheduler) === 'ON', 'warn',
            'Las reservas vencen igual con reservations:expire, pero se recomienda SET GLOBAL event_scheduler = ON.');
        $this->check('Usuario administrador', Role::where('name', 'ADMINISTRADOR')->first()?->users()->where('is_active', true)->exists() ?? false);

        $this->section('Tareas programadas y archivos');
        $beat = Cache::get('lothub:scheduler:heartbeat');
        $this->check('Planificador ejecutándose (schedule:run cada minuto)', $beat && now()->diffInMinutes($beat, true) <= 5, 'fail',
            $beat ? "Último latido: {$beat}" : 'Sin latidos: programa «php artisan schedule:run» cada minuto.');
        $this->check('storage/ con permiso de escritura', is_writable(storage_path()) && is_writable(storage_path('app')));

        $this->section('Integraciones');
        $this->check('Correo real (MAIL_MAILER)', ! in_array(config('mail.default'), ['log', 'array'], true), 'warn',
            'Con «log» los correos (recuperación, activación, avisos) no salen del servidor.');
        $this->check('Wompi configurado', filled(config('lothub.wompi.public_key')) && filled(config('lothub.wompi.integrity_secret')) && filled(config('lothub.wompi.events_secret')), 'warn');
        $this->check('Wompi en ambiente de producción', config('lothub.wompi.environment') === 'production'
            && str_starts_with((string) config('lothub.wompi.public_key'), 'pub_prod_'), 'warn');
        $this->check('Tasa de usura definida (USURY_MONTHLY_RATE)', (bool) config('lothub.financing.usury_configured'), 'warn',
            'Actualízala cada mes con la certificación de la Superfinanciera.');

        $this->section('Respaldos');
        $latest = collect(File::isDirectory(config('lothub.backup.path')) ? File::directories(config('lothub.backup.path')) : [])
            ->map(fn ($d) => basename($d))->filter(fn ($d) => preg_match('/^\d{8}_\d{6}$/', $d))->sort()->last();
        $this->check('Respaldo de las últimas 26 horas', $latest && now()->diffInHours(Carbon::createFromFormat('Ymd_His', $latest), true) <= 26, 'warn',
            $latest ? "Último: {$latest}" : 'Ejecuta php artisan lothub:backup y copia los respaldos fuera del servidor.');

        return $this->finish();
    }

    private function section(string $title): void
    {
        $this->rows[] = ['', "<options=bold>{$title}</>", ''];
    }

    private function check(string $label, bool $ok, string $level = 'fail', ?string $hint = null): void
    {
        $status = $ok ? '<fg=green>OK</>' : ($level === 'fail' ? '<fg=red>FALLA</>' : '<fg=yellow>REVISAR</>');
        $this->rows[] = [$status, $label, $ok ? '' : (string) $hint];
        if (! $ok) {
            $this->failures[$level][] = $label;
        }
    }

    private function viewExists(string $name): bool
    {
        return (bool) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.views WHERE table_schema = DATABASE() AND table_name = ?', [$name])->n;
    }

    private function finish(): int
    {
        $this->table(['Estado', 'Verificación', 'Detalle'], $this->rows);
        $fails = count($this->failures['fail']) + ($this->option('strict') ? count($this->failures['warn']) : 0);

        if ($fails) {
            $this->error("{$fails} punto(s) por resolver antes de producción.");

            return self::FAILURE;
        }
        $this->info(count($this->failures['warn']) ? 'Listo, con '.count($this->failures['warn']).' advertencia(s).' : 'Todo listo para producción.');

        return self::SUCCESS;
    }
}
