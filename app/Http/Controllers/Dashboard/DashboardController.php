<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\ProjectStatus;
use App\Models\Catalogs\SaleStatus;
use App\Models\Commercial\Client;
use App\Models\Commercial\InformationRequest;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Models\Commercial\Reservation;
use App\Models\Commercial\Sale;
use App\Models\Commercial\SaleCancellationRequest;
use App\Models\Payments\BankStatementLine;
use App\Models\Security\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Panel de inicio del personal. Cada bloque se muestra según los permisos. */
class DashboardController extends Controller
{
    /** Orden y nombre de los rangos de v_portfolio_aging. */
    private const AGING = ['AL_DIA' => 'Al día', '1_A_30' => '1 a 30 días', '31_A_60' => '31 a 60 días', '61_A_90' => '61 a 90 días', 'MAS_DE_90' => 'Más de 90 días'];

    public function __invoke(): View
    {
        $user = auth()->user();

        $lotsByStatus = Lot::select('status_id', DB::raw('COUNT(*) as total'))->groupBy('status_id')->pluck('total', 'status_id');

        $stats = [
            'projects_on_sale' => Project::onSale()->count(),
            'lots_available'   => (int) ($lotsByStatus[LotStatus::DISPONIBLE] ?? 0),
            'lots_reserved'    => (int) ($lotsByStatus[LotStatus::RESERVADO] ?? 0),
            'lots_sold'        => (int) ($lotsByStatus[LotStatus::VENDIDO] ?? 0),
            'lots_total'       => (int) $lotsByStatus->sum(),
            'clients'          => Client::count(),
            'requests_new'     => InformationRequest::where('status', 'NUEVA')->count(),
        ];

        $aging = $user->hasPermission('cartera.ver')
            ? collect(self::AGING)->map(fn ($label, $bucket) => ['label' => $label] + (array) (DB::table('v_portfolio_aging')->where('aging_bucket', $bucket)->first() ?? ['obligations' => 0, 'balance' => 0]))
            : null;

        $projects = Project::withLotStats()->whereIn('status_id', [ProjectStatus::EN_VENTA, ProjectStatus::EN_PLANEACION])
            ->orderByDesc('available_lots')->limit(5)->get();

        $requests = $user->hasPermission('solicitudes.ver')
            ? InformationRequest::with('project')->where('status', 'NUEVA')->latest()->limit(5)->get()
            : collect();

        $activity = $user->hasPermission('auditoria.ver')
            ? AuditLog::with('user')->latest('id')->limit(6)->get()
            : collect();

        $queues = collect($this->queues())->filter(fn ($q) => $user->hasPermission($q['permission']))->values();

        return view('dashboard.index', compact('stats', 'aging', 'projects', 'requests', 'activity', 'queues'));
    }

    /**
     * Bandejas de trabajo pendiente por rol: [etiqueta, ícono, cantidad, ruta, permiso].
     * Cada sprint agrega las suyas (reservas por vencer, anulaciones, acuerdos, pagos por revisar…).
     */
    private function queues(): array
    {
        return [
            ['label' => 'Reservas activas', 'icon' => 'ph-bookmark-simple', 'route' => 'reservations.index', 'permission' => 'reservas.ver',
             'count' => fn () => Reservation::active()->count(),
             'hint' => fn () => ($n = Reservation::active()->where('expires_at', '<=', now()->addDays(2))->count()) ? "{$n} vencen en 48 h" : null],
            ['label' => 'Anulaciones por aprobar', 'icon' => 'ph-seal-warning', 'route' => 'sales.cancellations', 'permission' => 'negociaciones.aprobar_anulacion',
             'count' => fn () => SaleCancellationRequest::where('status', SaleCancellationRequest::PENDING)->count()],
            ['label' => 'Negociaciones sin acuerdo', 'icon' => 'ph-file-dashed', 'route' => 'agreements.pending', 'permission' => 'acuerdos.ver',
             'count' => fn () => Sale::where('status_id', SaleStatus::VIGENTE)->whereDoesntHave('agreements')->count()],
            ['label' => 'Pagos por revisar', 'icon' => 'ph-list-checks', 'route' => 'allocation.queue', 'permission' => 'asignacion.gestionar',
             'count' => fn () => BankStatementLine::where('status', BankStatementLine::REVIEW)->count()],
        ];
    }
}
