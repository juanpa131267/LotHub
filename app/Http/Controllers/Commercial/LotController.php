<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\LotStatus;
use App\Models\Commercial\Lot;
use App\Models\Commercial\LotPriceHistory;
use App\Models\Commercial\Project;
use App\Models\Commercial\ProjectStage;
use App\Services\AuditLogger;
use App\Services\Lots\LotInventoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU-08 Gestionar lotes (RF-05). Identidad única proyecto + manzana + número (RN-01).
 * Solo quien tiene "lotes.bloquear" alterna DISPONIBLE ↔ BLOQUEADO; RESERVADO y
 * VENDIDO los fija la base de datos con sus triggers.
 */
class LotController extends CrudController
{
    const MODEL = Lot::class;
    const ROUTE = 'lots';
    const PERMISSION = 'lotes';

    protected array $with = ['project', 'stage', 'status'];
    protected array $searchable = ['block', 'lot_number', 'project.name', 'notes'];

    protected function meta(): array
    {
        return [
            'title'    => 'Lotes',
            'subtitle' => 'Inventario por proyecto, manzana y número con su estado de comercialización.',
            'icon'     => 'ph-squares-four',
            'singular' => 'lote',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'project.name', 'label' => 'Proyecto', 'type' => 'strong'],
            ['key' => 'stage.name', 'label' => 'Etapa', 'type' => 'muted'],
            ['key' => 'block', 'label' => 'Manzana'],
            ['key' => 'lot_number', 'label' => 'Lote'],
            ['key' => 'area_m2', 'label' => 'Área m²', 'type' => 'number'],
            ['key' => 'list_price', 'label' => 'Precio de lista', 'type' => 'money'],
            ['key' => 'status.name', 'label' => 'Estado', 'type' => 'badge', 'colors' => [
                'Disponible' => 'success', 'Reservado' => 'warning', 'Vendido' => 'info', 'Bloqueado' => 'neutral',
            ]],
        ];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'project_id', 'label' => 'Proyecto', 'options' => Project::orderBy('name')->pluck('name', 'id')->all()],
            ['name' => 'status_id', 'label' => 'Estado', 'options' => LotStatus::pluck('name', 'id')->all()],
        ];
    }

    protected function headerActions(): array
    {
        return [
            ['label' => 'Carga masiva', 'icon' => 'ph-upload-simple', 'route' => 'lots.import', 'permission' => 'lotes.importar'],
            ['label' => 'Exportar', 'icon' => 'ph-microsoft-excel-logo', 'route' => 'lots.export', 'permission' => 'lotes.exportar', 'keep_filters' => true],
        ];
    }

    protected function rowActions(): array
    {
        return [
            ['label' => 'Historial de precios', 'icon' => 'ph-clock-counter-clockwise', 'route' => 'lots.history', 'permission' => 'lotes.ver'],
        ];
    }

    /** US-LOT-008 Exporta a Excel el inventario con la búsqueda y los filtros del listado. */
    public function export(Request $request, LotInventoryService $inventory): BinaryFileResponse
    {
        $project = $request->filled('project_id') ? Project::find($request->query('project_id')) : null;
        $title = $project ? "Proyecto {$project->name}" : 'Todos los proyectos';
        if ($request->filled('status_id')) {
            $title .= ' · estado '.LotStatus::whereKey($request->query('status_id'))->value('name');
        }

        $path = $inventory->export($this->filteredQuery($request), $title);
        AuditLogger::log('EXPORTAR_INVENTARIO', 'lots', 0, [], $request->only(['q', 'project_id', 'status_id']));

        return response()->download($path, 'inventario_lotes_'.now()->format('Ymd_His').'.xlsx')->deleteFileAfterSend();
    }

    /** US-LOT-005 Historial de cambios del precio de lista. */
    public function history(int $id): View
    {
        $lot = Lot::with('project', 'status')->findOrFail($id);

        return view('commercial.lot-history', [
            'lot'     => $lot,
            'history' => LotPriceHistory::with('user.person')->where('lot_id', $lot->id)->latest('changed_at')->latest('id')->get(),
            'sources' => LotPriceHistory::SOURCES,
        ]);
    }

    protected function save(array $data, ?Model $lot): Model
    {
        return LotPriceHistory::recording(LotPriceHistory::FORM, fn () => parent::save($data, $lot));
    }

    protected function recordLabel(Model $lot): string
    {
        return "{$lot->project->name} · {$lot->label}";
    }

    protected function fields(?Model $lot): array
    {
        $fields = [
            ['name' => 'project_id', 'label' => 'Proyecto', 'type' => 'select', 'required' => true, 'width' => 'half',
             'options' => Project::orderBy('name')->pluck('name', 'id')->all()],
            ['name' => 'stage_id', 'label' => 'Etapa', 'type' => 'select', 'width' => 'half',
             'options' => $this->stageOptions(), 'help' => 'Opcional. Debe pertenecer al mismo proyecto.'],
            ['name' => 'block', 'label' => 'Manzana', 'required' => true, 'width' => 'third', 'uppercase' => true, 'placeholder' => 'A'],
            ['name' => 'lot_number', 'label' => 'Número de lote', 'required' => true, 'width' => 'third', 'placeholder' => '12'],
            ['name' => 'area_m2', 'label' => 'Área (m²)', 'type' => 'number', 'step' => '0.01', 'required' => true, 'width' => 'third'],
            ['name' => 'list_price', 'label' => 'Precio de lista (COP)', 'type' => 'number', 'step' => '1', 'required' => true, 'width' => 'half'],
        ];

        if ($this->canChangeStatus($lot)) {
            $fields[] = ['name' => 'status_id', 'label' => 'Estado', 'type' => 'select', 'required' => true, 'width' => 'half',
                'default' => LotStatus::DISPONIBLE,
                'options' => LotStatus::whereIn('id', [LotStatus::DISPONIBLE, LotStatus::BLOQUEADO])->pluck('name', 'id')->all()];
        } elseif ($lot) {
            $fields[] = ['type' => 'note', 'width' => 'half', 'label' => 'Estado: '.$lot->status->name,
                'text' => $lot->hasManualStatus() ? 'No tienes permiso para bloquear/desbloquear lotes.' : 'Lo administra la reserva o la negociación asociada.'];
        }

        $fields[] = ['name' => 'notes', 'label' => 'Observaciones', 'type' => 'textarea', 'rows' => 2];

        return $fields;
    }

    protected function rules(?Model $lot): array
    {
        $rules = [
            'project_id' => ['required', 'exists:projects,id'],
            'stage_id'   => ['nullable', Rule::exists('project_stages', 'id')->where('project_id', request('project_id'))],
            'block'      => ['required', 'string', 'max:10'],
            'lot_number' => ['required', 'string', 'max:10',
                Rule::unique('lots')->where('project_id', request('project_id'))->where('block', request('block'))->ignore($lot)],
            'area_m2'    => ['required', 'numeric', 'gt:0'],
            'list_price' => ['required', 'numeric', 'gt:0'],
            'notes'      => ['nullable', 'string', 'max:255'],
        ];

        if ($this->canChangeStatus($lot)) {
            $rules['status_id'] = ['required', Rule::in([LotStatus::DISPONIBLE, LotStatus::BLOQUEADO])];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'lot_number.unique' => 'Ya existe ese número de lote en la misma manzana del proyecto (RN-01).',
            'stage_id.exists'   => 'La etapa seleccionada no pertenece al proyecto.',
        ];
    }

    protected function deleteBlockedReason(Model $lot): ?string
    {
        $inUse = DB::table('reservations')->where('lot_id', $lot->id)->exists()
            || DB::table('sales')->where('lot_id', $lot->id)->exists();

        return $inUse ? 'No se puede eliminar: el lote tiene reservas o negociaciones. Puedes bloquearlo.' : null;
    }

    private function canChangeStatus(?Model $lot): bool
    {
        return auth()->user()->hasPermission('lotes.bloquear') && (! $lot || $lot->hasManualStatus());
    }

    private function stageOptions(): array
    {
        return ProjectStage::with('project')->orderBy('project_id')->orderBy('display_order')->get()
            ->mapWithKeys(fn ($s) => [$s->id => "{$s->project->name} — {$s->name}"])->all();
    }
}
