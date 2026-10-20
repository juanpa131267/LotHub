<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Commercial\Project;
use App\Models\Commercial\ProjectStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Etapas de un proyecto (CU-07). Comparte permisos con proyectos. */
class ProjectStageController extends CrudController
{
    const MODEL = ProjectStage::class;
    const ROUTE = 'stages';
    const PERMISSION = 'proyectos';

    protected array $with = ['project'];
    protected array $searchable = ['name', 'project.name'];
    protected string $orderBy = 'project_id';

    protected function meta(): array
    {
        return [
            'title'    => 'Etapas',
            'subtitle' => 'Fases comerciales en las que se agrupan los lotes de cada proyecto.',
            'icon'     => 'ph-stack',
            'singular' => 'etapa',
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->withCount('lots');
    }

    protected function columns(): array
    {
        return [
            ['key' => 'project.name', 'label' => 'Proyecto', 'type' => 'strong'],
            ['key' => 'name', 'label' => 'Etapa'],
            ['key' => 'display_order', 'label' => 'Orden', 'type' => 'number'],
            ['key' => 'lots_count', 'label' => 'Lotes', 'type' => 'number'],
        ];
    }

    protected function filters(): array
    {
        return [['name' => 'project_id', 'label' => 'Proyecto', 'options' => Project::orderBy('name')->pluck('name', 'id')->all()]];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'project_id', 'label' => 'Proyecto', 'type' => 'select', 'required' => true,
             'options' => Project::orderBy('name')->pluck('name', 'id')->all(), 'default' => request('project_id')],
            ['name' => 'name', 'label' => 'Nombre de la etapa', 'required' => true, 'width' => 'two-thirds', 'placeholder' => 'Etapa 1'],
            ['name' => 'display_order', 'label' => 'Orden', 'type' => 'number', 'required' => true, 'width' => 'third', 'min' => 1, 'default' => 1],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'project_id'    => ['required', 'exists:projects,id'],
            'name'          => ['required', 'string', 'max:100',
                Rule::unique('project_stages')->where('project_id', request('project_id'))->ignore($record)],
            'display_order' => ['required', 'integer', 'between:1,255'],
        ];
    }

    protected function recordLabel(Model $stage): string
    {
        return "{$stage->project->name} — {$stage->name}";
    }

    protected function deleteBlockedReason(Model $stage): ?string
    {
        return $stage->lots_count > 0 ? 'No se puede eliminar: la etapa tiene lotes asignados.' : null;
    }
}
