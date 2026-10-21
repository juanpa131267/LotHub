<?php

namespace Database\Seeders;

use App\Models\Catalogs\FinancingModality;
use App\Models\Catalogs\IdentificationType;
use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\ObligationStatus;
use App\Models\Catalogs\PaymentFrequency;
use App\Models\Catalogs\PaymentMethod;
use App\Models\Catalogs\TransactionStatus;
use App\Models\Commercial\Client;
use App\Models\Commercial\DataConsent;
use App\Models\Commercial\InformationRequest;
use App\Models\Commercial\Lot;
use App\Models\Commercial\LotPriceHistory;
use App\Models\Commercial\Project;
use App\Models\Commercial\Reservation;
use App\Models\Commercial\Sale;
use App\Models\Commercial\SaleCancellationRequest;
use App\Models\Financing\Installment;
use App\Models\Financing\Obligation;
use App\Models\Payments\GatewayNotification;
use App\Models\Payments\GatewayTransaction;
use App\Models\Payments\Payment;
use App\Models\Payments\PaymentGateway;
use App\Models\Security\Person;
use App\Models\Security\Role;
use App\Models\Security\User;
use App\Services\Commercial\ReservationService;
use App\Services\Commercial\SaleService;
use App\Services\Communications\ClientRequestService;
use App\Services\Communications\CollectionNotifier;
use App\Services\Communications\ContactChannelService;
use App\Services\Documents\DocumentService;
use App\Services\Financing\AccountStatementService;
use App\Services\Financing\AgreementService;
use App\Services\Financing\LateChargeService;
use App\Services\Migration\LegacyMigrationService;
use App\Services\Migration\ParallelRunService;
use App\Services\Payments\BankStatementService;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Payments\PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use SplMinHeap;
use Throwable;

/**
 * 18 meses de operación simulada para demostración y pruebas de aceptación.
 *
 * Todo se crea con los servicios reales del sistema, en orden cronológico y con el reloj de la
 * aplicación puesto en la fecha de cada evento (Carbon::setTestNow). Así los datos cumplen las
 * mismas reglas, triggers y validaciones que el uso normal y quedan en la auditoría.
 *
 * Cubre: personal por rol, clientes (con y sin autorización de datos, canales y portal), solicitudes
 * de información, reservas (activas, convertidas, vencidas y anuladas), negociaciones de contado,
 * financiadas y mixtas con uno o varios compradores, documentos, acuerdos firmados y sin firmar,
 * abonos de clientes puntuales, atrasados, morosos y que pagan anticipado, pagos por pasarela,
 * liquidación mensual de mora, anulaciones aprobadas, rechazadas y pendientes, reestructuraciones,
 * reversiones, cambios de precio, extracto bancario con asignación automática y cola de revisión,
 * conciliación, comunicaciones de cobro, consultas y reclamos, estados de cuenta, migración de la
 * cartera anterior y un corte de la operación en paralelo.
 *
 *   php artisan db:seed --class=DemoDataSeeder      (incluye los proyectos y este seeder)
 * Usuarios: demo.director, demo.asesor1..3, demo.cartera1..2 · contraseña Demo2026*
 * Clientes con portal: su correo · contraseña Cliente2026*
 */
class DemoPortfolioSeeder extends Seeder
{
    public const STAFF_PASSWORD = 'Demo2026*';
    public const CLIENT_PASSWORD = 'Cliente2026*';

    /** Escala del volumen (1 = 220 clientes y ~150 negociaciones). Las pruebas usan una escala menor. */
    public static float $scale = 1.0;

    private const FIRST_F = ['María', 'Ana', 'Luisa', 'Carolina', 'Diana', 'Paola', 'Sandra', 'Claudia', 'Natalia', 'Valentina', 'Daniela',
        'Laura', 'Juliana', 'Camila', 'Andrea', 'Mónica', 'Patricia', 'Gloria', 'Marcela', 'Yolanda', 'Adriana', 'Lucía', 'Isabel', 'Sofía', 'Martha'];
    private const FIRST_M = ['Juan', 'Carlos', 'Andrés', 'Luis', 'Jorge', 'Diego', 'Felipe', 'Santiago', 'Alejandro', 'Camilo', 'Óscar', 'Mauricio',
        'Ricardo', 'Fernando', 'Hernán', 'Julián', 'Sebastián', 'Germán', 'Álvaro', 'Iván', 'Nicolás', 'Pedro', 'Héctor', 'Rafael', 'Wilson'];
    private const LAST = ['Rodríguez', 'Gómez', 'González', 'Martínez', 'García', 'López', 'Hernández', 'Sánchez', 'Ramírez', 'Pérez', 'Díaz',
        'Moreno', 'Muñoz', 'Rojas', 'Jiménez', 'Vargas', 'Castro', 'Ruiz', 'Ortiz', 'Romero', 'Suárez', 'Torres', 'Gutiérrez', 'Álvarez',
        'Quintero', 'Cárdenas', 'Mejía', 'Restrepo', 'Ospina', 'Cifuentes', 'Barrera', 'Salazar', 'Patiño', 'Bermúdez', 'Lozano', 'Gaitán',
        'Peña', 'Acosta', 'Rincón', 'Parra', 'Caicedo', 'Valencia', 'Cortés', 'Arango', 'Montoya', 'Galindo', 'Beltrán', 'Pinzón'];
    private const OCCUPATIONS = ['Comerciante', 'Docente', 'Ingeniero(a)', 'Médico(a)', 'Contador(a)', 'Pensionado(a)', 'Abogado(a)',
        'Enfermero(a)', 'Empresario(a)', 'Administrador(a)', 'Agricultor(a)', 'Conductor(a)', 'Policía', 'Militar', 'Independiente'];

    private SplMinHeap $timeline;
    private array $events = [];
    private int $sequence = 0;
    /** Momento simulado actual: ningún evento puede programarse en el pasado de la simulación. */
    private int $clock = 0;
    private CarbonImmutable $today;
    private CarbonImmutable $start;

    /** @var array<string, User> */
    private array $staff = [];
    /** @var array<int, array{date:CarbonImmutable, id:?int, consent:bool, channels:bool, portal:bool, female:bool}> */
    private array $clients = [];
    private array $usedClients = [];
    private array $payments = [];
    private array $stats = [];

    public function __construct(
        private ReservationService $reservations,
        private SaleService $sales,
        private AgreementService $agreements,
        private PaymentService $paymentService,
        private LateChargeService $lateCharges,
        private OnlinePaymentService $online,
        private CollectionNotifier $notifier,
        private ContactChannelService $channels,
        private ClientRequestService $requests,
        private DocumentService $documents,
    ) {}

    public function run(): void
    {
        if (User::where('username', 'demo.director')->exists()) {
            $this->command?->warn('Los datos de demostración ya existen (usuario demo.director); no se generan de nuevo.');

            return;
        }

        self::$scale = (float) (env('DEMO_SCALE') ?: self::$scale);
        mt_srand(2026);
        config(['mail.default' => 'array', 'lothub.notifications.payment_confirmations' => false]);
        $this->today = CarbonImmutable::now();
        $this->start = $this->today->subMonths(18)->startOfMonth();
        $this->timeline = new class extends SplMinHeap {};

        try {
            $this->at($this->start->subDays(5), fn () => $this->createStaff());
            $this->planClients((int) round(220 * self::$scale));
            $this->planInformationRequests((int) round(40 * self::$scale));
            $this->planSales((int) round(135 * self::$scale), (int) round(60 * self::$scale));
            $this->planMonthlyTasks();
            $this->planCommunications();
            $this->runTimeline();
            $this->afterTimeline();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            auth()->forgetUser();
        }

        $this->report();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Motor de la línea de tiempo
    // ══════════════════════════════════════════════════════════════════════

    /** Programa $action para ejecutarse con el reloj en $when (como $user, si se indica). */
    private function at(CarbonImmutable $when, callable $action, ?string $as = null): void
    {
        $timestamp = $when->getTimestamp();
        if ($when->gt($this->today)) {
            return;
        }
        // El reloj no retrocede: lo programado más temprano el mismo día corre unos minutos después;
        // lo que cae en días ya simulados se descarta.
        if ($timestamp < $this->clock) {
            if ($this->clock - $timestamp > 86_400) {
                return;
            }
            $timestamp = $this->clock + 300;
        }
        $id = ++$this->sequence;
        $this->events[$id] = [$action, $as];
        $this->timeline->insert([$timestamp, $id]);
    }

    private function runTimeline(): void
    {
        $total = 0;
        while (! $this->timeline->isEmpty()) {
            [$timestamp, $id] = $this->timeline->extract();
            [$action, $as] = $this->events[$id];
            unset($this->events[$id]);

            $this->clock = $timestamp;
            $now = CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone'));
            Carbon::setTestNow($now);
            CarbonImmutable::setTestNow($now);
            if ($as && isset($this->staff[$as])) {
                auth()->setUser($this->staff[$as]);
            }

            try {
                $action($now);
            } catch (Throwable $e) {
                $this->stats['eventos omitidos'] = ($this->stats['eventos omitidos'] ?? 0) + 1;
                if (($this->stats['eventos omitidos']) <= 5) {
                    $this->command?->warn('  · Evento omitido el '.$now->format('Y-m-d').': '.Str::limit($e->getMessage(), 140));
                }
            }
            if (++$total % 400 === 0) {
                $this->command?->line("  … {$total} eventos procesados (".$now->format('Y-m').')');
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Personal y clientes
    // ══════════════════════════════════════════════════════════════════════

    private function createStaff(): void
    {
        $people = [
            ['demo.director', 'DIRECTOR_COMERCIAL', 'Esteban', 'Rivera Montes'],
            ['demo.asesor1', 'ASESOR_COMERCIAL', 'Laura', 'Méndez Castro'],
            ['demo.asesor2', 'ASESOR_COMERCIAL', 'Miguel', 'Arias Londoño'],
            ['demo.asesor3', 'ASESOR_COMERCIAL', 'Paula', 'Sierra Rojas'],
            ['demo.cartera1', 'AUXILIAR_CARTERA', 'Rosa', 'Herrera Duque'],
            ['demo.cartera2', 'AUXILIAR_CARTERA', 'Javier', 'Quiroga Lara'],
        ];
        $cc = IdentificationType::where('code', 'CC')->value('id');

        foreach ($people as $i => [$username, $role, $first, $last]) {
            $person = Person::create([
                'identification_type_id' => $cc, 'identification_number' => (string) (52_400_000 + $i * 7919),
                'first_name' => $first, 'last_name' => $last, 'email' => "{$username}@lothub.demo", 'phone' => '310'.(5_550_000 + $i),
                'city_id' => $this->cityId('25307'),
            ]);
            $user = User::create([
                'person_id' => $person->id, 'username' => $username, 'password_hash' => Hash::make(self::STAFF_PASSWORD),
                'hash_algorithm' => 'bcrypt', 'is_active' => true, 'password_changed_at' => now(), 'last_login_at' => $this->today->subDays($i),
            ]);
            $user->roles()->sync([Role::where('name', $role)->value('id')]);
            $this->staff[$username] = $user;
        }
        auth()->setUser($this->staff['demo.asesor1']);
    }

    private function planClients(int $count): void
    {
        $span = $this->start->diffInDays($this->today->subDays(3));
        for ($i = 0; $i < $count; $i++) {
            // Más clientes en los meses recientes (la operación crece).
            $offset = (int) round($span * (mt_rand(0, 10_000) / 10_000) ** 0.75);
            $date = $this->start->addDays($offset)->setTime(mt_rand(8, 17), mt_rand(0, 59));
            $this->clients[$i] = [
                'date' => $date, 'id' => null, 'consent' => mt_rand(1, 100) <= 92, 'channels' => mt_rand(1, 100) <= 75,
                'portal' => false, 'female' => (bool) mt_rand(0, 1),
            ];
            $this->at($date, fn () => $this->createClient($i), $this->advisor());
        }
        // Algunos clientes con autorización y correo tendrán acceso al portal.
        $candidates = array_keys(array_filter($this->clients, fn ($c) => $c['consent']));
        shuffle($candidates);
        foreach (array_slice($candidates, 0, (int) round(40 * self::$scale)) as $i) {
            $this->clients[$i]['portal'] = true;
            $this->at($this->clients[$i]['date']->addDays(mt_rand(5, 40)), fn () => $this->enablePortal($i), $this->advisor());
        }
        // Tres revocan después su autorización de datos (RN-22).
        foreach (array_slice($candidates, -3) as $i) {
            $this->at($this->clients[$i]['date']->addDays(mt_rand(60, 200)), function () use ($i) {
                DataConsent::where('person_id', Client::find($this->clients[$i]['id'])->person_id)->update(['revoked_at' => now()]);
            });
        }
    }

    private function createClient(int $i): void
    {
        $c = $this->clients[$i];
        $first = $this->pick($c['female'] ? self::FIRST_F : self::FIRST_M);
        $second = mt_rand(0, 2) ? '' : ' '.$this->pick($c['female'] ? self::FIRST_F : self::FIRST_M);
        $last = $this->pick(self::LAST).' '.$this->pick(self::LAST);
        $types = ['CC' => 90, 'CE' => 4, 'PAS' => 2, 'PPT' => 2, 'NIT' => 2];
        $type = $this->weighted($types);

        $person = Person::create([
            'identification_type_id' => IdentificationType::where('code', $type)->value('id'),
            'identification_number'  => (string) ($type === 'NIT' ? 900_100_000 + $i * 13 : 1_000_000_000 + $i * 7_331 + mt_rand(0, 999)),
            'first_name'             => $first.$second,
            'last_name'              => $last,
            'email'                  => mt_rand(1, 100) <= 88 ? Str::slug(Str::ascii("{$first} ".explode(' ', $last)[0]), '.').".{$i}@correo.demo" : null,
            'phone'                  => '3'.$this->pick(['00', '01', '02', '04', '10', '11', '12', '13', '14', '15', '16', '17', '18', '20', '21']).mt_rand(1_000_000, 9_999_999),
            'city_id'                => $this->clientCity(),
            'address'                => $this->pick(['Calle', 'Carrera', 'Avenida', 'Diagonal', 'Transversal']).' '.mt_rand(1, 120).' # '.mt_rand(1, 99).'-'.mt_rand(1, 99),
        ]);
        $client = Client::create([
            'person_id' => $person->id, 'client_code' => Client::nextCode(), 'occupation' => $this->pick(self::OCCUPATIONS),
            'registered_by' => auth()->id(),
        ]);
        $this->clients[$i]['id'] = $client->id;

        if ($c['consent']) {
            DataConsent::create(['person_id' => $person->id, 'policy_version' => config('lothub.privacy_policy_version'),
                'channel' => $this->weighted(['PRESENCIAL' => 70, 'WEB' => 20, 'TELEFONICO' => 10]), 'granted_at' => now(), 'ip_address' => '10.0.0.'.mt_rand(2, 250)]);
            if ($c['channels']) {
                $wanted = ['EMAIL' => ['enabled' => (bool) $person->email], 'WHATSAPP' => ['enabled' => mt_rand(0, 1) === 1], 'LLAMADA' => ['enabled' => mt_rand(0, 2) === 0]];
                if (! $person->email && ! $wanted['WHATSAPP']['enabled']) {
                    $wanted['WHATSAPP']['enabled'] = true;
                }
                $this->channels->sync($client->load('person'), $wanted);
            }
        }
    }

    private function enablePortal(int $i): void
    {
        $client = Client::with('person')->find($this->clients[$i]['id']);
        if (! $client?->person->email || ! $client->hasActiveConsent()) {
            return;
        }
        $user = User::create([
            'person_id' => $client->person_id, 'username' => $client->person->email, 'password_hash' => Hash::make(self::CLIENT_PASSWORD),
            'hash_algorithm' => 'bcrypt', 'is_active' => true, 'password_changed_at' => now(), 'last_login_at' => now()->addDays(mt_rand(0, 30)),
        ]);
        $user->roles()->sync([Role::where('name', 'CLIENTE')->value('id')]);
        $current = $this->channels->active($client)->map(fn () => ['enabled' => true])->all();
        $this->channels->sync($client, $current + ['PORTAL' => ['enabled' => true]]);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Comercial: solicitudes, reservas y negociaciones
    // ══════════════════════════════════════════════════════════════════════

    private function planInformationRequests(int $count): void
    {
        $projects = Project::whereIn('code', array_column(DemoDataSeeder::PROJECTS, 0))->pluck('id')->all();
        for ($i = 0; $i < $count; $i++) {
            $date = $this->start->addDays(mt_rand(0, (int) $this->start->diffInDays($this->today)))->setTime(mt_rand(7, 21), mt_rand(0, 59));
            $recent = $date->gt($this->today->subDays(10));
            $this->at($date, function () use ($projects, $recent, $i) {
                $female = (bool) mt_rand(0, 1);
                $name = $this->pick($female ? self::FIRST_F : self::FIRST_M).' '.$this->pick(self::LAST);
                InformationRequest::create([
                    'project_id' => mt_rand(0, 5) ? $this->pick($projects) : null, 'full_name' => $name,
                    'email' => Str::slug(Str::ascii($name), '.').".web{$i}@correo.demo", 'phone' => '3'.mt_rand(100_000_000, 229_999_999),
                    'message' => $this->pick(['Quisiera conocer precios y formas de pago.', '¿Tienen financiación directa? ¿Cuál es la cuota inicial?',
                        'Me interesa un lote esquinero, ¿pueden llamarme?', 'Quiero agendar una visita al proyecto este fin de semana.',
                        '¿Los lotes tienen servicios públicos instalados?']),
                    'consent_accepted' => true,
                    'status' => $recent ? $this->weighted(['NUEVA' => 70, 'ATENDIDA' => 30]) : $this->weighted(['ATENDIDA' => 80, 'DESCARTADA' => 20]),
                    'attended_by' => $recent ? null : $this->staff[$this->advisor()]->id,
                ]);
            });
        }
    }

    private function planSales(int $direct, int $reservations): void
    {
        $lots = Lot::whereHas('project', fn ($q) => $q->whereIn('code', ['PRY-001', 'PRY-002', 'DEM-004', 'DEM-005', 'DEM-006'])->where('status_id', 2))
            ->where('status_id', LotStatus::DISPONIBLE)
            ->whereNot(fn ($q) => $q->whereHas('project', fn ($p) => $p->where('code', 'DEM-006'))->where('block', 'C')) // manzana C de DEM-006: migración
            ->pluck('id')->all();
        shuffle($lots);
        $span = (int) $this->start->diffInDays($this->today->subDays(2));

        // Reservas: convertidas, vencidas, anuladas y activas.
        $kinds = array_merge(
            array_fill(0, (int) round($reservations * 0.25), 'convertida'),
            array_fill(0, (int) round($reservations * 0.25), 'vencida'),
            array_fill(0, (int) round($reservations * 0.17), 'anulada'),
        );
        $kinds = array_merge($kinds, array_fill(0, max(0, $reservations - count($kinds)), 'activa'));
        foreach ($kinds as $kind) {
            $lotId = array_pop($lots);
            if (! $lotId) {
                break;
            }
            $date = $kind === 'activa'
                ? $this->today->subDays(mt_rand(0, 6))->setTime(mt_rand(8, 17), 0)
                : $this->start->addDays(mt_rand(20, $span - 15))->setTime(mt_rand(8, 17), 0);
            $client = $this->chooseClient($date);
            if ($client === null) {
                continue;
            }
            $this->at($date, fn () => $this->reserve($lotId, $client, $kind, $date), $this->advisor());
        }

        // Negociaciones directas (sin reserva previa).
        for ($i = 0; $i < $direct; $i++) {
            $lotId = array_pop($lots);
            if (! $lotId) {
                break;
            }
            $offset = (int) round(($span - 1) * (mt_rand(0, 10_000) / 10_000) ** 0.8);
            $date = $this->start->addDays(max(10, $offset))->setTime(mt_rand(9, 17), mt_rand(0, 59));
            $buyers = $this->chooseBuyers($date);
            if (! $buyers) {
                continue;
            }
            $this->at($date, fn () => $this->sell($lotId, $buyers), $this->advisor());
        }
    }

    private function reserve(int $lotId, int $clientIndex, string $kind, CarbonImmutable $date): void
    {
        $lot = Lot::with('status')->findOrFail($lotId);
        $client = Client::findOrFail($this->clients[$clientIndex]['id']);
        $days = $kind === 'activa' ? mt_rand(3, 12) : 8;
        $reservation = $this->reservations->reserve($lot, $client, $date->addDays($days)->setTime(18, 0), round(mt_rand(5, 30) * 100_000, -5),
            $this->pick([null, 'Cliente visitó el proyecto', 'Separación por transferencia', 'Referido por cliente actual']));
        $this->count('reservas');

        if ($kind === 'convertida') {
            $buyers = [$clientIndex => 100];
            if (mt_rand(1, 100) <= 25 && ($other = $this->chooseClient($date, exclude: $clientIndex)) !== null) {
                $buyers = [$clientIndex => 60, $other => 40];
            }
            // La negociación convierte la reserva activa del lote (RN-05).
            $this->at($date->addDays(mt_rand(2, 7))->setTime(mt_rand(9, 16), 30), fn () => $this->sell($lotId, $buyers), $this->advisor());
        } elseif ($kind === 'anulada') {
            $this->at($date->addDays(mt_rand(1, 5)), fn () => $this->reservations->cancel($reservation->fresh(),
                $this->pick(['El cliente no consiguió el crédito de la cuota inicial', 'Cambio de decisión del cliente', 'El cliente prefirió otro lote'])),
                $this->advisor());
        }
        // Las «vencidas» quedan activas con fecha pasada: el vencimiento automático las libera al final.
    }

    /** @param array<int, float> $buyers índice de cliente => participación (el primero es el titular) */
    private function sell(int $lotId, array $buyers): void
    {
        $lot = Lot::findOrFail($lotId);
        $modality = FinancingModality::where('code', $this->weighted(['FINANCIACION_DIRECTA' => 65, 'CONTADO' => 20, 'MIXTA' => 15]))->first();
        $price = round((float) $lot->list_price * (1 - mt_rand(0, 6) / 100), -5);   // descuentos negociados de 0 a 6 %
        $first = array_key_first($buyers);

        $sale = $this->sales->create([
            'lot_id' => $lot->id, 'agreed_price' => $price, 'financing_modality_id' => $modality->id, 'sale_date' => now()->toDateString(),
            'buyers' => collect($buyers)->map(fn ($pct, $index) => [
                'client_id' => $this->clients[$index]['id'], 'ownership_pct' => $pct, 'is_primary' => $index === $first,
            ])->values()->all(),
        ], mt_rand(1, 100) <= 30 ? ['PROMESA' => $this->pdf('Promesa de compraventa', "Lote {$lot->label} · precio $ ".number_format($price, 0, ',', '.'))] : []);
        $this->count('negociaciones');

        // La mayoría define el acuerdo en los días siguientes; las muy recientes pueden quedar pendientes.
        $recent = now()->gt($this->today->subDays(25));
        if (! ($recent && mt_rand(0, 1))) {
            $this->at(now()->toImmutable()->addDays(mt_rand(0, 3))->setTime(mt_rand(9, 16), 0), fn () => $this->defineAgreement($sale->id), $this->clerk());
        }
        $this->maybePlanCancellation($sale->id);
    }

    private function defineAgreement(int $saleId): void
    {
        $sale = Sale::with('modality', 'clients')->findOrFail($saleId);
        if (! $sale->isActive()) {
            return;
        }
        $price = (float) $sale->agreed_price;
        $code = $sale->modality->code;

        if ($code === 'CONTADO') {
            $terms = ['down_payment' => 0, 'financed_amount' => $price, 'installments_count' => 1, 'frequency_id' => $this->frequency('MENSUAL'),
                'monthly_interest_rate' => 0, 'late_interest_rate' => 2.0, 'first_due_date' => now()->addDays(mt_rand(10, 30))->toDateString()];
        } else {
            $downPct = $code === 'MIXTA' ? mt_rand(30, 50) : mt_rand(15, 40);
            $down = round($price * $downPct / 100, -5);
            $frequency = $this->weighted(['MENSUAL' => 85, 'TRIMESTRAL' => 10, 'BIMESTRAL' => 5]);
            $count = $code === 'MIXTA' ? mt_rand(6, 12) : (int) $this->weighted(['12' => 15, '24' => 25, '36' => 30, '48' => 15, '60' => 15]);
            if ($frequency !== 'MENSUAL') {
                $count = max(4, intdiv($count, $frequency === 'TRIMESTRAL' ? 3 : 2));
            }
            $terms = ['down_payment' => $down, 'financed_amount' => $price - $down, 'installments_count' => $count,
                'frequency_id' => $this->frequency($frequency), 'monthly_interest_rate' => mt_rand(0, 9) < 4 ? 0 : mt_rand(8, 15) / 10,
                'late_interest_rate' => 2.0, 'first_due_date' => now()->addMonthNoOverflow()->toDateString()];
        }

        $obligation = $this->agreements->create($sale, $terms);
        $this->count('acuerdos');

        if (mt_rand(1, 100) <= 85) {
            $this->at(now()->toImmutable()->addDays(mt_rand(1, 12))->setTime(11, 0), fn () => $this->agreements->sign($obligation->agreement,
                now()->toDateString(), mt_rand(1, 100) <= 25 ? $this->pdf('Acuerdo de pago firmado', $obligation->obligation_code) : null), $this->clerk());
        }

        $this->planPayments($obligation, $this->profile($sale));
    }

    private function maybePlanCancellation(int $saleId): void
    {
        $roll = mt_rand(1, 1000);
        if ($roll > 60) {   // ~6 % de las negociaciones
            return;
        }
        $request = now()->toImmutable()->addDays(mt_rand(20, 90))->setTime(10, 0);
        $type = mt_rand(0, 2) ? 'DESISTIMIENTO' : 'ANULACION';
        $reason = $type === 'DESISTIMIENTO'
            ? $this->pick(['El comprador desiste por pérdida de empleo', 'El comprador se traslada a otra ciudad', 'Problemas familiares del comprador'])
            : $this->pick(['Error en la identificación del lote negociado', 'Documentación del comprador incompleta', 'Negociación duplicada']);

        $this->at($request, function () use ($saleId, $type, $reason, $request) {
            $sale = Sale::find($saleId);
            if (! $sale?->isActive()) {
                return;
            }
            $req = $this->sales->requestCancellation($sale, $type, $reason);
            $this->count('solicitudes de anulación');
            $decision = $request->gt($this->today->subDays(20)) ? 'pendiente' : $this->weighted(['aprobar' => 60, 'rechazar' => 40]);
            if ($decision === 'pendiente') {
                return;
            }
            $this->at($request->addDays(mt_rand(2, 8)), function () use ($req, $decision) {
                $req = $req->fresh('sale');
                if ($decision === 'aprobar') {
                    $paid = $this->sales->appliedPayments($req->sale);
                    $this->sales->approveCancellation($req, $paid > 0 ? 'Se devuelve el 70 % de lo abonado; 30 % de penalidad según la cláusula octava de la promesa.' : null);
                } else {
                    $this->sales->rejectCancellation($req, 'El comprador firmó un acuerdo de pago; se ofrece reestructurar antes de desistir.');
                }
            }, 'demo.director');
        }, $this->advisor());
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Pagos
    // ══════════════════════════════════════════════════════════════════════

    /** Perfil de pago del comprador: puntual, atrasado, moroso, anticipado o nuevo. */
    private function profile(Sale $sale): string
    {
        if (now()->gt($this->today->subDays(45))) {
            return 'nuevo';
        }

        return $this->weighted(['puntual' => 45, 'atrasado' => 22, 'moroso' => 18, 'anticipado' => 15]);
    }

    private function planPayments(Obligation $obligation, string $profile, bool $afterRestructure = false): void
    {
        $installments = Installment::where('obligation_id', $obligation->id)->orderBy('installment_number')->get();
        $stopAfter = $profile === 'moroso' ? mt_rand(1, max(1, min(8, $installments->count() - 1))) : PHP_INT_MAX;
        $payoff = $profile === 'anticipado' ? mt_rand(2, 10) : null;

        foreach ($installments as $index => $installment) {
            $due = CarbonImmutable::parse($installment->due_date);
            $amount = (float) $installment->principal_amount + (float) $installment->interest_amount;
            if ($profile === 'nuevo' && (int) $installment->installment_number > 0) {
                break;
            }
            if ($index >= $stopAfter) {
                break;
            }
            if ($payoff !== null && $index === $payoff) {
                // Paga anticipadamente todo el saldo.
                $this->at($due->subDays(mt_rand(3, 15))->setTime(10, 0), fn () => $this->pay($obligation->agreement->sale_id, null, 'saldo'));
                break;
            }

            $date = match (true) {
                (int) $installment->installment_number === 0 => CarbonImmutable::now()->addDays(mt_rand(0, 2)),
                $profile === 'atrasado', $profile === 'moroso' => $due->addDays(mt_rand(6, 40)),
                default                                          => $due->subDays(mt_rand(0, 4)),
            };
            if ($profile === 'atrasado' && mt_rand(1, 100) <= 35) {
                $amount = round($amount * 0.5, -3);     // abono parcial
            }
            $this->at($date->setTime(mt_rand(8, 18), mt_rand(0, 59)), fn () => $this->pay($obligation->agreement->sale_id, $amount));
        }

        // Algunos morosos se reestructuran (CU-16) y desde entonces pagan puntualmente.
        if ($profile === 'moroso' && ! $afterRestructure && mt_rand(1, 100) <= 35) {
            $this->at($this->today->subDays(mt_rand(25, 80))->setTime(15, 0), fn () => $this->restructure($obligation->agreement->sale_id), $this->clerk());
        }
    }

    /** Registra un abono sobre la obligación vigente de la negociación ($amount null = saldo total). */
    private function pay(int $saleId, ?float $amount, string $kind = 'cuota'): void
    {
        $obligation = $this->activeObligation($saleId);
        if (! $obligation) {
            return;
        }
        $sale = $obligation->agreement->sale;
        $amount ??= round($obligation->summary()->balance);
        if ($amount <= 0) {
            return;
        }
        $payer = $sale->clients->count() > 1 && mt_rand(1, 100) <= 30 ? $sale->clients->random() : $sale->primaryClient();
        $method = $this->weighted(['CONSIGNACION' => 34, 'TRANSFERENCIA' => 34, 'EFECTIVO' => 14, 'PASARELA' => 18]);

        if ($method === 'PASARELA') {
            $this->gatewayPayment($obligation, $payer, $amount);

            return;
        }
        auth()->setUser($this->staff[$this->clerk()]);
        $payment = $this->paymentService->register($obligation, $payer, now()->toDateString(), $amount,
            (int) PaymentMethod::where('code', $method)->value('id'),
            $method === 'EFECTIVO' ? 'RC-'.mt_rand(10_000, 99_999) : ($method === 'CONSIGNACION' ? 'CNS-' : 'TRF-').mt_rand(1_000_000, 9_999_999),
            auth()->id());
        $this->payments[] = $payment->id;
        $this->count($kind === 'saldo' ? 'pagos anticipados del saldo' : 'abonos');
    }

    private function gatewayPayment(Obligation $obligation, Client $payer, float $amount, string $status = 'APPROVED', ?float $reported = null): GatewayTransaction
    {
        $tx = GatewayTransaction::create([
            'gateway_id' => PaymentGateway::wompi()->id, 'obligation_id' => $obligation->id, 'client_id' => $payer->id,
            'internal_reference' => 'LH'.$obligation->id.'-'.now()->format('ymdHis').'-'.Str::upper(Str::random(5)),
            'amount' => $amount, 'currency' => 'COP', 'status_id' => TransactionStatus::PENDIENTE, 'requested_at' => now(),
        ]);
        if ($status === 'PENDING') {
            return $tx;
        }
        $data = [
            'id' => mt_rand(10_000, 99_999).'-'.now()->timestamp.'-'.mt_rand(10_000, 99_999), 'reference' => $tx->internal_reference,
            'amount_in_cents' => (int) round(($reported ?? $amount) * 100), 'currency' => 'COP', 'status' => $status,
            'payment_method_type' => $this->weighted(['CARD' => 45, 'PSE' => 30, 'NEQUI' => 15, 'BANCOLOMBIA_TRANSFER' => 10]),
            'finalized_at' => now()->addMinutes(3)->toIso8601String(),
        ];
        GatewayNotification::create(['transaction_id' => $tx->id, 'external_reference' => $data['id'], 'event_type' => 'transaction.updated',
            'payload' => ['event' => 'transaction.updated', 'environment' => 'test', 'data' => ['transaction' => $data]],
            'signature_valid' => true, 'received_at' => now()->addMinutes(3), 'processed_at' => now()->addMinutes(3)]);
        $this->online->apply($tx, $data, 'datos de demostración');
        $this->count('pagos por pasarela');

        if ($payment = Payment::where('gateway_transaction_id', $tx->id)->first()) {
            $this->payments[] = $payment->id;
        }

        return $tx;
    }

    private function restructure(int $saleId): void
    {
        $obligation = $this->activeObligation($saleId);
        if (! $obligation) {
            return;
        }
        $balance = round($obligation->summary()->balance);
        if ($balance <= 0) {
            return;
        }
        $new = $this->agreements->restructure($obligation, [
            'down_payment' => 0, 'financed_amount' => $balance, 'installments_count' => $this->pick([24, 36, 48]), 'frequency_id' => $this->frequency('MENSUAL'),
            'monthly_interest_rate' => 1.0, 'late_interest_rate' => 2.0, 'first_due_date' => now()->addMonthNoOverflow()->toDateString(),
        ], $this->pick(['Disminución de ingresos del comprador', 'Acuerdo de normalización tras 3 cuotas vencidas', 'Solicitud del comprador por calamidad doméstica']));
        $this->count('reestructuraciones');
        $this->planPayments($new, 'puntual', afterRestructure: true);
    }

    private function activeObligation(int $saleId): ?Obligation
    {
        return Obligation::with('agreement.sale.clients')
            ->whereHas('agreement', fn ($q) => $q->where('sale_id', $saleId))
            ->where('status_id', ObligationStatus::ACTIVA)->latest('id')->first();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Tareas periódicas y comunicaciones
    // ══════════════════════════════════════════════════════════════════════

    private function planMonthlyTasks(): void
    {
        for ($month = $this->start; $month->lt($this->today); $month = $month->addMonth()) {
            // RN-19: liquidación de mora al cierre de cada mes (en producción es diaria).
            $this->at($month->endOfMonth()->setTime(23, 30), fn () => $this->lateCharges->run(CarbonImmutable::today()));
        }
        $this->at($this->today->subMinutes(30), fn () => $this->lateCharges->run(CarbonImmutable::today()));

        // Dos ajustes de lista de precios (+5 %) en los lotes disponibles (historial de precios, US-LOT-005).
        foreach ([$this->start->addMonths(6), $this->start->addMonths(12)] as $date) {
            $this->at($date->setTime(8, 0), fn () => LotPriceHistory::recording(LotPriceHistory::PER_M2, fn () => Lot::whereHas('project',
                fn ($q) => $q->whereIn('code', array_column(DemoDataSeeder::PROJECTS, 0)))->where('status_id', LotStatus::DISPONIBLE)
                ->update(['list_price' => DB::raw('ROUND(list_price * 1.05, -5)'), 'updated_at' => now()])), 'demo.director');
        }
    }

    private function planCommunications(): void
    {
        $from = $this->today->subDays(30)->startOfDay();
        $this->at($from->subMinute(), fn () => config(['lothub.notifications.payment_confirmations' => true]));
        for ($day = $from; $day->lte($this->today); $day = $day->addDay()) {
            $this->at($day->setTime(6, 0), fn () => $this->notifier->schedule(CarbonImmutable::today()));
            foreach ([9, 13, 17] as $hour) {
                $this->at($day->setTime($hour, 5), fn () => $this->notifier->send(CarbonImmutable::now()));
            }
        }
        // Consultas y reclamos de los titulares (CU-28) en los últimos 60 días.
        for ($i = 0; $i < (int) round(20 * self::$scale); $i++) {
            $this->at($this->today->subDays(mt_rand(1, 60))->setTime(mt_rand(8, 20), 0), fn () => $this->fileRequest(), 'demo.cartera1');
        }
    }

    private function fileRequest(): void
    {
        $client = Client::whereIn('id', DB::table('sale_clients')->pluck('client_id'))->inRandomOrder()->first();
        if (! $client) {
            return;
        }
        [$type, $subject, $text] = $this->pick([
            ['CONSULTA', 'Valor para pagar la totalidad del lote', 'Quisiera saber cuánto debo para cancelar la totalidad de mi lote este mes.'],
            ['CONSULTA', 'Certificado de paz y salvo', 'Necesito saber qué requisitos hay para obtener el paz y salvo cuando termine de pagar.'],
            ['RECLAMO', 'Abono no reflejado en el estado de cuenta', 'Consigné la cuota del mes y no la veo aplicada en el portal. Adjunto la referencia de la consignación.'],
            ['RECLAMO', 'Cobro de intereses de mora', 'Me cobraron intereses de mora aunque pagué dentro del plazo. Solicito revisar y corregir.'],
            ['RECTIFICACION', 'Actualizar mi número de celular', 'Cambié de número de celular y quiero que actualicen mis datos de contacto.'],
            ['SUPRESION', 'No recibir más mensajes por WhatsApp', 'Solicito que eliminen mi número de WhatsApp de sus bases de datos de contacto.'],
        ]);
        $request = $this->requests->file($client, $type, $subject, $text);
        $this->count('consultas y reclamos');

        $state = now()->gt($this->today->subDays(8)) ? 'RADICADA' : $this->weighted(['RESPONDIDA' => 45, 'CERRADA' => 25, 'EN_TRAMITE' => 15, 'RADICADA' => 15]);
        if ($state === 'RADICADA') {
            return;
        }
        $this->at(now()->toImmutable()->addDays(mt_rand(1, 6)), function () use ($request, $state) {
            $request = $request->fresh('client.person');
            $this->requests->startProcessing($request);
            if ($state === 'EN_TRAMITE') {
                return;
            }
            $this->requests->answer($request->fresh('client.person'), 'Revisamos tu solicitud con el área de cartera. '.$this->pick([
                'El abono quedó aplicado a la cuota correspondiente; ya puedes verlo en el portal.',
                'El valor para cancelar la totalidad se refleja en tu estado de cuenta en PDF con corte de hoy.',
                'Actualizamos tus datos de contacto según lo solicitado.',
                'Los intereses de mora corresponden a los días transcurridos después del vencimiento, según el acuerdo firmado.',
            ]));
            if ($state === 'CERRADA') {
                $this->requests->close($request->fresh());
            }
        }, 'demo.cartera1');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Hoy: vencimientos, extracto, pasarela, reversiones, migración y PDF
    // ══════════════════════════════════════════════════════════════════════

    private function afterTimeline(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        auth()->setUser($this->staff['demo.cartera1']);

        $this->stats['reservas vencidas liberadas'] = $this->reservations->expireOverdue();
        $this->reversals();
        $this->gatewayCases();
        $this->bankStatement();
        $this->legacyMigrationAndParallelRun();
        foreach (Obligation::where('status_id', ObligationStatus::ACTIVA)->inRandomOrder()->limit((int) max(2, round(6 * self::$scale)))->get() as $obligation) {
            app(AccountStatementService::class)->generate($obligation, auth()->id());
            $this->count('estados de cuenta en PDF');
        }
    }

    /** CU-19: algunos abonos mal registrados se reversan. */
    private function reversals(): void
    {
        $candidates = Payment::whereIn('id', $this->payments)->whereNull('gateway_transaction_id')->where('status_id', 1)
            ->where('payment_date', '>=', $this->today->subMonths(4)->toDateString())->inRandomOrder()->limit((int) max(2, round(6 * self::$scale)))->get();
        foreach ($candidates as $payment) {
            try {
                $this->paymentService->reverse($payment, $this->pick(['Consignación devuelta por el banco (cheque sin fondos)',
                    'El abono se registró en la obligación de otro cliente', 'Valor digitado incorrectamente; se registra de nuevo']));
                $this->count('abonos reversados');
            } catch (Throwable) {
            }
        }
    }

    /** CU-20 a CU-22: rechazadas, pendientes vencidas, diferencia de valor y notificaciones sin procesar. */
    private function gatewayCases(): void
    {
        $obligations = Obligation::with('agreement.sale.clients')->where('status_id', ObligationStatus::ACTIVA)->inRandomOrder()->limit(8)->get();
        foreach ($obligations as $i => $obligation) {
            $payer = $obligation->agreement->sale->primaryClient();
            $amount = round(min($obligation->summary()->balance, mt_rand(5, 25) * 100_000), -3);
            if ($amount <= 0) {
                continue;
            }
            Carbon::setTestNow(now()->subHours(mt_rand(3, 72)));
            match ($i % 4) {
                0       => $this->gatewayPayment($obligation, $payer, $amount, 'DECLINED'),
                1       => $this->gatewayPayment($obligation, $payer, $amount, 'ERROR'),
                2       => $this->gatewayPayment($obligation, $payer, $amount, 'PENDING'),
                default => $this->gatewayPayment($obligation, $payer, $amount, 'APPROVED', reported: $amount - 50_000),   // diferencia de valor
            };
            Carbon::setTestNow();
        }
        $this->stats['transacciones expiradas'] = $this->online->expireStale();

        GatewayNotification::create(['transaction_id' => null, 'external_reference' => '99999-'.time(), 'event_type' => 'transaction.updated',
            'payload' => ['event' => 'transaction.updated', 'data' => ['transaction' => ['id' => '99999', 'reference' => 'LH-REF-DESCONOCIDA', 'status' => 'APPROVED', 'amount_in_cents' => 150_000_000]]],
            'signature_valid' => true, 'received_at' => now()->subDay(), 'processed_at' => now()->subDay()]);
        GatewayNotification::create(['transaction_id' => null, 'event_type' => 'transaction.updated',
            'payload' => ['event' => 'transaction.updated', 'data' => ['transaction' => ['reference' => 'LH-FALSA', 'status' => 'APPROVED']]],
            'signature_valid' => false, 'received_at' => now()->subHours(5)]);
    }

    /** Extracto del banco con pagos que el motor asigna solo, otros a revisión y un duplicado. */
    private function bankStatement(): void
    {
        $lines = [];
        $obligations = Obligation::with('agreement.sale.clients.person')->where('status_id', ObligationStatus::ACTIVA)->inRandomOrder()->limit((int) max(6, round(22 * self::$scale)))->get();
        foreach ($obligations as $i => $obligation) {
            $next = $obligation->balances()->first(fn ($r) => (float) $r->balance > 0);
            if (! $next) {
                continue;
            }
            $person = $obligation->agreement->sale->primaryClient()->person;
            $date = now()->subDays(mt_rand(0, 6))->format('d/m/Y');
            $value = number_format(round((float) $next->balance), 0, ',', '.');
            $lines[] = match ($i % 4) {
                0       => [$date, 'CONSIGNACION NACIONAL', $obligation->obligation_code, $person->identification_number, '', $value],        // código + documento + valor → automática
                1       => [$date, 'CONSIGNACION NACIONAL', 'CNS'.mt_rand(100_000, 999_999), $person->identification_number, '', $value],          // documento + valor
                2       => [$date, 'TRANSF DE '.Str::upper(Str::ascii($person->full_name)), 'TRF'.mt_rand(100_000, 999_999), '', '', $value],     // nombre + valor
                default => [$date, 'PAGO '.$obligation->obligation_code, 'PAG'.mt_rand(100_000, 999_999), '', '', number_format(round((float) $next->balance * 0.6), 0, ',', '.')],
            };
        }
        $lines[] = [now()->subDays(2)->format('d/m/Y'), 'TRANSFERENCIA DESCONOCIDA', 'TRF000777', '', '', '777.000'];
        $lines[] = [now()->subDays(1)->format('d/m/Y'), 'RENDIMIENTOS FINANCIEROS', '', '', '', '18.450'];
        $lines[] = [now()->subDays(1)->format('d/m/Y'), 'COMISION MANEJO CUENTA', '', '', '23.800', ''];
        if ($ref = Payment::whereNotNull('bank_reference')->where('bank_reference', 'like', 'CNS-%')->where('status_id', 1)->latest('id')->first()) {
            $lines[] = [$ref->payment_date->format('d/m/Y'), 'CONSIGNACION YA REGISTRADA', $ref->bank_reference, '', '', number_format((float) $ref->amount, 0, ',', '.')];
        }

        $csv = "Fecha;Descripción;Referencia;NIT/Cédula;Débito;Crédito\n".implode("\n", array_map(fn ($l) => implode(';', $l), $lines))."\n";
        $path = tempnam(sys_get_temp_dir(), 'extracto').'.csv';
        file_put_contents($path, $csv);

        $result = app(BankStatementService::class)->import(new UploadedFile($path, 'extracto_bancolombia_'.now()->format('Ymd').'.csv', 'text/csv', null, true),
            'Bancolombia', 'Ahorros ****4821', (int) PaymentMethod::where('code', 'CONSIGNACION')->value('id'));
        $this->stats['extracto: asignados automáticamente'] = $result['assigned'];
        $this->stats['extracto: en revisión'] = $result['review'];
        $this->stats['extracto: duplicados'] = $result['duplicate'];
    }

    /** Sprint 10: migración de las ventas anteriores al sistema y un corte de comparación. */
    private function legacyMigrationAndParallelRun(): void
    {
        $lots = Lot::whereHas('project', fn ($q) => $q->where('code', 'DEM-006'))->where('block', 'C')->where('status_id', LotStatus::DISPONIBLE)
            ->orderByRaw('CAST(lot_number AS UNSIGNED)')->limit((int) max(4, round(12 * self::$scale)))->get();
        if ($lots->isEmpty()) {
            return;
        }

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Cartera');
        $rows = [];
        foreach ($lots as $i => $lot) {
            $female = (bool) mt_rand(0, 1);
            $price = round((float) $lot->list_price * 0.9, -5);
            $down = round($price * 0.3, -5);
            $count = $this->pick([24, 36, 48]);
            $saleDate = $this->today->subMonths(mt_rand(20, 30));
            $months = (int) $saleDate->addMonth()->diffInMonths($this->today);
            $paid = $down + round(($price - $down) / $count * max(0, $months - mt_rand(0, 3)), -3);
            $rows[] = ['HOJA-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'CC', (string) (79_000_000 + $i * 4_321), $this->pick($female ? self::FIRST_F : self::FIRST_M),
                $this->pick(self::LAST).' '.$this->pick(self::LAST), '', '3'.mt_rand(100_000_000, 229_999_999), 'SI', 'DEM-006', 'C', $lot->lot_number,
                $saleDate->toDateString(), $price, 'FINANCIACION_DIRECTA', $down, $price - $down, $count, 'MENSUAL', 0, 2,
                $saleDate->addMonth()->toDateString(), min($paid, $price), $this->today->subDays(mt_rand(5, 40))->toDateString(), max(0, $price - min($paid, $price))];
        }
        $sheet->fromArray($rows, null, 'A5');
        $control = new Worksheet($book, 'control');
        $book->addSheet($control);
        $control->setCellValue('A1', 'lothub-migracion');
        $path = tempnam(sys_get_temp_dir(), 'migra').'.xlsx';
        (new Xlsx($book))->save($path);

        $result = app(LegacyMigrationService::class)->import(new UploadedFile($path, 'cartera_tocaima_hoja_excel.xlsx', null, null, true));
        if ($result['errors']) {
            throw new RuntimeException('Migración de demostración con errores: '.json_encode(array_slice($result['errors'], 0, 3), JSON_UNESCAPED_UNICODE));
        }
        $this->stats['negociaciones migradas'] = $result['created'];

        // Corte: casi todas coinciden; una difiere y una referencia no fue migrada.
        $cut = new Spreadsheet();
        $cs = $cut->getActiveSheet();
        $cs->fromArray([['Referencia en la hoja', 'Obligación', 'Titular', 'Saldo según la hoja']]);
        $r = 2;
        foreach (DB::table('legacy_import_rows')->where('import_id', $result['import_id'])->get() as $i => $row) {
            $balance = round(Obligation::find($row->obligation_id)->summary()->balance);
            $cs->fromArray([[$row->legacy_reference, '', '', $i === 1 ? $balance + 350_000 : $balance]], null, 'A'.$r++);
        }
        $cs->fromArray([['HOJA-900', '', '', 12_500_000]], null, 'A'.$r);
        $cutPath = tempnam(sys_get_temp_dir(), 'corte').'.xlsx';
        (new Xlsx($cut))->save($cutPath);
        app(ParallelRunService::class)->compare(new UploadedFile($cutPath, 'corte_hoja_'.now()->format('Ymd').'.xlsx', null, null, true), CarbonImmutable::today(), 1000);
        $this->count('cortes de operación en paralelo');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Utilidades
    // ══════════════════════════════════════════════════════════════════════

    /** Cliente ya registrado en $date que aún no compró (o, si no hay, cualquiera registrado). */
    private function chooseClient(CarbonImmutable $date, ?int $exclude = null): ?int
    {
        $eligible = array_keys(array_filter($this->clients, fn ($c, $i) => $c['date']->lt($date->subHours(2)) && $i !== $exclude, ARRAY_FILTER_USE_BOTH));
        if (! $eligible) {
            return null;
        }
        $fresh = array_values(array_diff($eligible, array_keys($this->usedClients)));
        // Uno de cada diez compra un segundo lote.
        $index = $fresh && mt_rand(1, 10) > 1 ? $this->pick($fresh) : $this->pick($eligible);
        $this->usedClients[$index] = true;

        return $index;
    }

    /** @return array<int, float> compradores con su participación */
    private function chooseBuyers(CarbonImmutable $date): array
    {
        $first = $this->chooseClient($date);
        if ($first === null) {
            return [];
        }
        $roll = mt_rand(1, 100);
        if ($roll <= 75 || ($second = $this->chooseClient($date, $first)) === null) {
            return [$first => 100];
        }
        if ($roll <= 95 || ($third = $this->chooseClient($date, $first)) === null || $third === $second) {
            return $roll % 2 ? [$first => 50, $second => 50] : [$first => 60, $second => 40];
        }

        return [$first => 40, $second => 30, $third => 30];
    }

    private function clientCity(): int
    {
        static $regional = null, $all = null;
        $regional ??= DB::table('cities')->whereIn('dane_code', ['25307', '25612', '73275', '73449', '25488', '25815', '25001', '73268', '73001', '25290', '11001'])->pluck('id')->all();
        $all ??= DB::table('cities')->pluck('id')->all();

        return mt_rand(1, 100) <= 70 ? $this->pick($regional) : $this->pick($all);
    }

    private function cityId(string $dane): ?int
    {
        return DB::table('cities')->where('dane_code', $dane)->value('id');
    }

    private function frequency(string $code): int
    {
        return (int) PaymentFrequency::where('code', $code)->value('id');
    }

    private function advisor(): string
    {
        return 'demo.asesor'.mt_rand(1, 3);
    }

    private function clerk(): string
    {
        return 'demo.cartera'.mt_rand(1, 2);
    }

    private function pick(array $items): mixed
    {
        return $items[array_keys($items)[mt_rand(0, count($items) - 1)]];
    }

    private function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_last($weights);
    }

    /** PDF sencillo para los documentos soporte de demostración. */
    private function pdf(string $title, string $detail): UploadedFile
    {
        $pdf = new Dompdf();
        $pdf->loadHtml('<h2 style="font-family:DejaVu Sans">'.e($title).'</h2><p style="font-family:DejaVu Sans">'.e($detail).'</p><p style="font-family:DejaVu Sans;color:#667085">Documento de demostración generado por LotHub el '.now()->format('Y-m-d').'.</p>');
        $pdf->render();
        $path = tempnam(sys_get_temp_dir(), 'doc').'.pdf';
        file_put_contents($path, $pdf->output());

        return new UploadedFile($path, Str::slug($title).'.pdf', 'application/pdf', null, true);
    }

    private function count(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    private function report(): void
    {
        $tables = ['people', 'clients', 'users', 'data_consents', 'client_contact_channels', 'information_requests', 'reservations', 'sales', 'sale_clients',
            'sale_cancellation_requests', 'documents', 'payment_agreements', 'obligations', 'installments', 'late_charges', 'payments', 'payment_allocations',
            'gateway_transactions', 'gateway_notifications', 'bank_statements', 'bank_statement_lines', 'notifications', 'client_requests',
            'account_statements', 'lot_price_history', 'legacy_import_rows', 'parallel_runs', 'audit_logs', 'cities', 'states'];
        $this->command?->table(['Tabla', 'Registros'], array_map(fn ($t) => [$t, number_format(DB::table($t)->count(), 0, ',', '.')], $tables));
        $this->command?->table(['Evento simulado', 'Cantidad'], collect($this->stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->command?->info('Usuarios: demo.director, demo.asesor1..3, demo.cartera1..2 · contraseña '.self::STAFF_PASSWORD.' · clientes con portal: su correo · '.self::CLIENT_PASSWORD);
    }
}
