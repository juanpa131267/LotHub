<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\City;
use App\Models\Catalogs\ProjectStatus;
use App\Models\Commercial\Project;
use App\Models\Commercial\ProjectPlan;
use App\Services\Plans\PlanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

/** CU-07 Gestionar proyectos (RF-04). Borrado lógico; bloqueado si tiene lotes. */
class ProjectController extends CrudController
{
    const MODEL = Project::class;
    const ROUTE = 'projects';
    const PERMISSION = 'proyectos';

    protected array $with = ['city', 'status'];
    protected array $searchable = ['code', 'name', 'city.name'];

    private ?ProjectPlan $uploadedPlan = null;

    protected function meta(): array
    {
        return [
            'title'    => 'Proyectos',
            'subtitle' => 'Desarrollos urbanísticos. Solo los que están "En venta" aparecen en el sitio público.',
            'icon'     => 'ph-buildings',
            'singular' => 'proyecto',
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->withLotStats();
    }

    protected function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Código', 'type' => 'code'],
            ['key' => 'name', 'label' => 'Proyecto', 'type' => 'strong'],
            ['key' => 'city.name', 'label' => 'Municipio'],
            ['key' => 'lots_summary', 'label' => 'Lotes disp. / total', 'type' => 'muted'],
            ['key' => 'status.name', 'label' => 'Estado', 'type' => 'badge', 'colors' => [
                'En planeación' => 'info', 'En venta' => 'success', 'Vendido en su totalidad' => 'neutral',
                'Suspendido' => 'warning', 'Cerrado' => 'neutral',
            ]],
        ];
    }

    protected function filters(): array
    {
        return [['name' => 'status_id', 'label' => 'Estado', 'options' => ProjectStatus::pluck('name', 'id')->all()]];
    }

    protected function row(Model $project): array
    {
        return $project->toArray() + ['lots_summary' => "{$project->available_lots} / {$project->total_lots}"];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'code', 'label' => 'Código', 'required' => true, 'width' => 'third', 'uppercase' => true, 'placeholder' => 'PRY-001'],
            ['name' => 'name', 'label' => 'Nombre del proyecto', 'required' => true, 'width' => 'two-thirds'],
            ['name' => 'city_id', 'label' => 'Municipio', 'type' => 'select', 'required' => true, 'width' => 'half', 'options' => City::options()],
            ['name' => 'address', 'label' => 'Dirección / vereda', 'width' => 'half'],
            ['name' => 'total_area_m2', 'label' => 'Área total (m²)', 'type' => 'number', 'step' => '0.01', 'width' => 'third'],
            ['name' => 'launch_date', 'label' => 'Fecha de lanzamiento', 'type' => 'date', 'width' => 'third'],
            ['name' => 'status_id', 'label' => 'Estado', 'type' => 'select', 'required' => true, 'width' => 'third',
             'options' => ProjectStatus::pluck('name', 'id')->all(), 'default' => ProjectStatus::EN_PLANEACION],
            ['name' => 'description', 'label' => 'Descripción pública', 'type' => 'textarea', 'rows' => 4,
             'help' => 'Se muestra en el sitio público cuando el proyecto está en venta.'],

            ['type' => 'section', 'label' => 'Plano interactivo'],
            ['type' => 'note', 'label' => 'Después de guardar', 'text' => 'En las pestañas del proyecto podrás cargar las fotos de la ficha de venta (en bloque o una por una con anotación) y definir los precios: un valor general por m² o una planilla de Excel lote por lote.'],
            ['name' => 'plan_file', 'label' => 'Plano arquitectónico', 'type' => 'file', 'value' => null,
             'accept' => PlanService::acceptsDwg() ? '.dwg,.dxf' : '.dxf',
             'help' => (PlanService::acceptsDwg() ? 'DWG o DXF' : 'DXF (exportado desde AutoCAD)')
                .' · máx. '.PlanService::maxUploadMb().' MB. El sistema detecta manzanas, lotes, vías y orientación, y te muestra el resultado para revisarlo antes de publicarlo.',
             'max_bytes' => PlanService::maxUploadBytes()],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code'          => ['required', 'string', 'max:20', Rule::unique('projects')->ignore($record)],
            'name'          => ['required', 'string', 'max:150'],
            'city_id'       => ['required', 'exists:cities,id'],
            'address'       => ['nullable', 'string', 'max:255'],
            'total_area_m2' => ['nullable', 'numeric', 'gt:0'],
            'launch_date'   => ['nullable', 'date'],
            'status_id'     => ['required', 'exists:project_statuses,id'],
            'description'   => ['nullable', 'string', 'max:5000'],
            'plan_file'     => ['nullable', ...ProjectPlanController::fileRules()],
        ];
    }

    protected function save(array $data, ?Model $record): Model
    {
        $record ??= new Project(['created_by' => auth()->id()]);
        $record->fill($data)->save();

        if (! empty($data['plan_file'])) {
            $this->uploadedPlan = app(PlanService::class)->upload($record, $data['plan_file'], auth()->id());
        }

        return $record;
    }

    /** Si se cargó un plano, se lleva al usuario a revisarlo. */
    protected function afterSave(Model $project, string $message): RedirectResponse
    {
        if (! $this->uploadedPlan) {
            // Al crear, se sigue con las fotos; al editar, se vuelve al listado.
            return $project->wasRecentlyCreated
                ? redirect()->route('projects.photos', $project->id)->with('success', 'Proyecto creado. Ahora agrega las fotos de la ficha de venta; luego carga el plano y define los precios.')
                : parent::afterSave($project, $message);
        }

        $plan = $this->uploadedPlan;

        return redirect()->route('projects.plan', [$project->id, 'version' => $plan->id])
            ->with('success', 'Proyecto guardado. Estamos procesando el plano; esta página se actualiza sola.');
    }

    protected function formTop(?Model $project): ?array
    {
        return $project ? ['view' => 'commercial.partials.project-tabs', 'data' => ['project' => $project, 'active' => 'data']] : null;
    }

    protected function rowActions(): array
    {
        return [
            ['label' => 'Plano interactivo', 'icon' => 'ph-map-trifold', 'route' => 'projects.plan', 'permission' => 'proyectos.ver'],
            ['label' => 'Fotos', 'icon' => 'ph-images', 'route' => 'projects.photos', 'permission' => 'proyectos.ver'],
            ['label' => 'Precios', 'icon' => 'ph-currency-circle-dollar', 'route' => 'projects.prices', 'permission' => 'proyectos.ver'],
        ];
    }

    protected function deleteBlockedReason(Model $project): ?string
    {
        return $project->total_lots > 0 ? 'No se puede eliminar: el proyecto tiene lotes registrados.' : null;
    }
}
