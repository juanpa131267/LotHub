<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\ProjectStatus;
use App\Models\Commercial\Client;
use App\Models\Commercial\InformationRequest;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
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

        return view('dashboard.index', compact('stats', 'aging', 'projects', 'requests', 'activity'));
    }
}
