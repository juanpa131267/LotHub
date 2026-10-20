<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Catalogs\LotStatus;
use App\Models\Commercial\InformationRequest;
use App\Models\Commercial\Project;
use App\Services\Plans\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sitio público sin autenticación (CU-30, RF-21): proyectos en venta,
 * disponibilidad de lotes y formulario de solicitud de información.
 */
class SiteController extends Controller
{
    public function __construct(private PlanService $plans) {}

    public function home(): View
    {
        $projects = Project::onSale()->with(['city.state', 'cover', 'photos' => fn ($q) => $q->limit(1)])
            ->withExists('publishedPlan as has_plan')->withLotStats()->orderBy('name')->get();

        $totals = [
            'projects'  => $projects->count(),
            'available' => $projects->sum('available_lots'),
            'cities'    => $projects->pluck('city_id')->unique()->count(),
        ];

        return view('public.home', compact('projects', 'totals'));
    }

    public function project(int $id): View
    {
        $project = Project::onSale()->with(['city.state', 'stages', 'publishedPlan', 'photos', 'cover'])->withLotStats()->findOrFail($id);
        $planData = $project->publishedPlan?->data();

        // Con plano publicado la lista se arma en el navegador a partir de él; sin plano, aquí.
        $lots = $planData ? collect() : $project->lots()->with('stage')
            ->whereIn('status_id', [LotStatus::DISPONIBLE, LotStatus::RESERVADO, LotStatus::VENDIDO])
            ->orderBy('block')->orderByRaw('CAST(lot_number AS UNSIGNED), lot_number')
            ->get(['id', 'stage_id', 'block', 'lot_number', 'area_m2', 'list_price', 'status_id']);

        return view('public.project', [
            'project'   => $project,
            'blocks'    => $lots->groupBy('block'),
            'lots'      => $lots,
            'planData'  => $planData,
            'lotStates' => $planData ? $this->plans->lotStates($project) : [],
        ]);
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id'       => ['nullable', 'exists:projects,id'],
            'full_name'        => ['required', 'string', 'max:150'],
            'email'            => ['required', 'email', 'max:191'],
            'phone'            => ['nullable', 'string', 'max:20'],
            'message'          => ['nullable', 'string', 'max:2000'],
            'consent_accepted' => ['accepted'],
        ], [
            'consent_accepted.accepted' => 'Debes aceptar la política de tratamiento de datos.',
        ], ['full_name' => 'nombre', 'email' => 'correo', 'phone' => 'teléfono', 'message' => 'mensaje']);

        InformationRequest::create(['consent_accepted' => true, 'status' => 'NUEVA'] + $data);

        return back()->with('success', '¡Gracias! Recibimos tu solicitud; un asesor te contactará pronto.');
    }
}
