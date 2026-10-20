<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Commercial\Project;
use App\Models\Commercial\ProjectPlan;
use App\Services\Plans\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Plano interactivo del proyecto (panel interno): cargar DWG/DXF, revisar el
 * resultado del procesamiento, crear los lotes desde el plano y publicarlo.
 * Guía: docs/PLANOS.md
 */
class ProjectPlanController extends Controller
{
    public function __construct(private PlanService $plans) {}

    public function show(int $id, Request $request): View
    {
        $project = Project::with(['plans.uploader', 'stages'])->withLotStats()->findOrFail($id);
        $plan = $project->plans->firstWhere('id', (int) $request->query('version')) ?? $project->plans->first();

        return view('commercial.plan', [
            'project'    => $project,
            'plan'       => $plan,
            'planData'   => $plan?->data(),
            'lotStates'  => $this->plans->lotStates($project),
            'acceptsDwg' => PlanService::acceptsDwg(),
            // Precio por m² promedio de los lotes existentes, como valor inicial al sincronizar.
            'suggestedPrice' => ($avg = $project->lots()->avg(DB::raw('list_price / area_m2'))) ? round($avg, -3) : null,
        ]);
    }

    public function store(int $id, Request $request): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $request->validate(['plan_file' => self::fileRules()], [], ['plan_file' => 'plano']);

        $plan = $this->plans->upload($project, $request->file('plan_file'), $request->user()->id);

        return redirect()->route('projects.plan', [$project->id, 'version' => $plan->id])
            ->with('success', 'Plano cargado. Lo estamos procesando; esta página se actualiza sola.');
    }

    /** GET: avance del procesamiento (lo consulta la pantalla cada pocos segundos). */
    public function status(int $id, int $planId): JsonResponse
    {
        return response()->json($this->plans->status($this->planOf($id, $planId)));
    }

    public function sync(int $id, int $planId, Request $request): RedirectResponse
    {
        $plan = $this->planOf($id, $planId);
        $data = $request->validate([
            'price_per_m2' => ['required', 'numeric', 'gt:0'],
            'stage_id'     => ['nullable', 'exists:project_stages,id'],
            'update_areas' => ['boolean'],
        ], [], ['price_per_m2' => 'precio por m²', 'stage_id' => 'etapa']);

        $c = $this->plans->syncLots($plan, (float) $data['price_per_m2'], $data['stage_id'] ?? null, $request->boolean('update_areas'));

        return back()->with('success', "Lotes sincronizados: {$c['created']} creados, {$c['renamed']} renombrados a MANZANA-NÚMERO, {$c['updated']} actualizados, {$c['unchanged']} sin cambios.");
    }

    public function publish(int $id, int $planId): RedirectResponse
    {
        $plan = $this->planOf($id, $planId);
        abort_unless($plan->isReady(), 422, 'Solo se publican planos procesados correctamente.');
        $this->plans->publish($plan);

        return back()->with('success', 'Plano publicado: ya se muestra en el sitio público.');
    }

    public function unpublish(int $id, int $planId): RedirectResponse
    {
        $this->plans->unpublish($this->planOf($id, $planId));

        return back()->with('success', 'El plano dejó de mostrarse en el sitio público.');
    }

    public function reprocess(int $id, int $planId): RedirectResponse
    {
        $plan = $this->plans->reprocess($this->planOf($id, $planId));

        return redirect()->route('projects.plan', [$id, 'version' => $plan->id])->with('success', $plan->wasRecentlyCreated
            ? 'Procesando una nueva versión con el mismo archivo. La versión publicada sigue visible hasta que publiques la nueva.'
            : 'Procesando el plano de nuevo…');
    }

    /** Reglas de validación del archivo (se reutilizan en el formulario de proyectos). */
    public static function fileRules(): array
    {
        $extensions = PlanService::acceptsDwg() ? 'dxf,dwg' : 'dxf';

        return ['file', 'extensions:'.$extensions, 'max:'.intdiv(PlanService::maxUploadBytes(), 1024)];
    }

    private function planOf(int $projectId, int $planId): ProjectPlan
    {
        return ProjectPlan::where('project_id', $projectId)->findOrFail($planId);
    }
}
