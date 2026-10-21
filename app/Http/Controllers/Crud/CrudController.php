<?php

namespace App\Http\Controllers\Crud;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Controlador base para los módulos CRUD.
 *
 * Una subclase solo DECLARA el módulo (modelo, columnas, campos, reglas) y la
 * vista genérica resources/views/crud/* se encarga de pintarlo. Las rutas se
 * registran con Route::crud($uri, MiController::class) (ver AppServiceProvider).
 *
 * Ejemplo mínimo: App\Http\Controllers\Catalogs\DocumentTypeController.
 * Guía completa: docs/NUEVO_MODULO.md
 */
abstract class CrudController extends Controller
{
    /** Modelo Eloquent administrado. */
    const MODEL = Model::class;

    /** Prefijo de nombre de ruta: 'lots' → lots.index, lots.data, lots.store... */
    const ROUTE = '';

    /** Prefijo de permisos: 'lotes' → lotes.ver, lotes.crear, lotes.editar, lotes.eliminar. */
    const PERMISSION = '';

    /** Relaciones precargadas en listados y formularios. */
    protected array $with = [];

    /** Columnas para el buscador. Admite relaciones: 'person.first_name'. */
    protected array $searchable = [];

    protected string $orderBy = 'id';

    protected string $orderDir = 'desc';

    /** Acciones que ofrece el módulo (se combinan con los permisos del usuario). */
    protected array $actions = ['create' => true, 'edit' => true, 'delete' => true];

    // ═════════════════════════════════════════════════════════════════════
    //  Lo que define cada módulo
    // ═════════════════════════════════════════════════════════════════════

    /** ['title' => ..., 'subtitle' => ..., 'icon' => 'ph-...', 'singular' => ...] */
    abstract protected function meta(): array;

    /**
     * Columnas del listado: ['key' => 'person.first_name', 'label' => 'Nombre', 'type' => 'text']
     * Tipos: text | strong | muted | money | number | date | bool | badge (con 'colors') | json
     */
    abstract protected function columns(): array;

    /**
     * Campos del formulario: ['name', 'label', 'type', 'required', 'width', 'options', 'value'...]
     * Tipos: text | email | number | date | password | textarea | select | checkbox | checkboxes | file | section | note
     * width: full (por defecto) | half | third
     */
    protected function fields(?Model $record): array
    {
        return [];
    }

    /** Reglas de validación de Laravel para crear ($record = null) o editar. */
    protected function rules(?Model $record): array
    {
        return [];
    }

    /** Filtros desplegables del listado: [['name' => 'status_id', 'label' => 'Estado', 'options' => [...]]] */
    protected function filters(): array
    {
        return [];
    }

    /** Consulta base del módulo (sobrescribir para joins o restricciones). */
    protected function query(): Builder
    {
        return (static::MODEL)::query()->with($this->with);
    }

    /** Fila serializada para la tabla. Sobrescribir para agregar valores calculados. */
    protected function row(Model $record): array
    {
        return $record->toArray();
    }

    /** Persiste los datos validados. Se ejecuta dentro de una transacción. */
    protected function save(array $data, ?Model $record): Model
    {
        $record ??= new (static::MODEL);
        $record->fill($data)->save();

        return $record;
    }

    /** Devuelve un mensaje si el registro no puede eliminarse (RN-26). */
    protected function deleteBlockedReason(Model $record): ?string
    {
        return null;
    }

    /**
     * Acciones extra por fila en el listado:
     * [['label' => 'Plano', 'icon' => 'ph-map-trifold', 'route' => 'projects.plan', 'permission' => 'proyectos.ver']]
     * La ruta recibe el id del registro como primer parámetro.
     */
    protected function rowActions(): array
    {
        return [];
    }

    /**
     * Botones extra en el encabezado del listado:
     * [['label' => 'Exportar', 'icon' => 'ph-download', 'route' => 'lots.export', 'permission' => 'lotes.ver', 'keep_filters' => true]]
     * Con keep_filters el enlace recibe la búsqueda y los filtros que el usuario tenga aplicados.
     */
    protected function headerActions(): array
    {
        return [];
    }

    /** Vista parcial opcional sobre el formulario: ['view' => 'nombre.vista', 'data' => [...]]. */
    protected function formTop(?Model $record): ?array
    {
        return null;
    }

    /** A dónde ir después de guardar (por defecto, al listado). */
    protected function afterSave(Model $record, string $message): RedirectResponse
    {
        return redirect()->route(static::ROUTE.'.index')->with('success', $message);
    }

    /** Texto con el que se identifica el registro en el formulario de edición. */
    protected function recordLabel(Model $record): string
    {
        return (string) ($record->name ?? $record->code ?? "#{$record->getKey()}");
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Acciones HTTP (no deberían necesitar cambios en las subclases)
    // ═════════════════════════════════════════════════════════════════════

    public function index(): View
    {
        return view('crud.index', ['page' => $this->page()]);
    }

    /** JSON paginado que consume la tabla (public/js/crud.js). */
    public function data(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request);

        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $page = $query->orderBy($this->orderBy, $this->orderDir)->paginate($perPage);

        return response()->json([
            'data'      => collect($page->items())->map(fn ($r) => $this->row($r))->values(),
            'total'     => $page->total(),
            'from'      => $page->firstItem(),
            'to'        => $page->lastItem(),
            'page'      => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function create(): View
    {
        abort_unless($this->actions['create'], 404);

        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->actions['create'], 404);

        $data = $request->validate($this->rules(null), $this->messages(), $this->attributes());

        return $this->persist($data, null, 'Registro creado correctamente.');
    }

    public function edit(int $id): View
    {
        abort_unless($this->actions['edit'], 404);

        return $this->form($this->query()->findOrFail($id));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        abort_unless($this->actions['edit'], 404);

        $record = $this->query()->findOrFail($id);
        $data = $request->validate($this->rules($record), $this->messages(), $this->attributes());

        return $this->persist($data, $record, 'Cambios guardados correctamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless($this->actions['delete'], 404);

        $record = $this->query()->findOrFail($id);

        if ($reason = $this->deleteBlockedReason($record)) {
            return response()->json(['ok' => false, 'message' => $reason], 422);
        }

        try {
            $record->delete();
        } catch (QueryException $e) {
            return response()->json(['ok' => false, 'message' => $this->dbErrorMessage($e)], 422);
        }

        $message = $this->usesSoftDeletes() ? 'Registro enviado a la papelera.' : 'Registro eliminado.';

        return response()->json(['ok' => true, 'message' => $message]);
    }

    public function trash(): View
    {
        abort_unless($this->usesSoftDeletes(), 404);

        $records = $this->query()->onlyTrashed()->latest('deleted_at')->get();

        return view('crud.trash', [
            'page' => $this->page(),
            'rows' => $records->map(fn ($r) => $this->row($r) + ['deleted_at' => $r->deleted_at?->format('Y-m-d H:i')]),
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $this->query()->onlyTrashed()->findOrFail($id)->restore();

        return response()->json(['ok' => true, 'message' => 'Registro restaurado.']);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Internos
    // ═════════════════════════════════════════════════════════════════════

    /** Consulta base con la búsqueda (?q=) y los filtros del listado aplicados. */
    protected function filteredQuery(Request $request): Builder
    {
        $query = $this->query();

        if ($term = trim((string) $request->query('q'))) {
            $this->applySearch($query, $term);
        }

        foreach ($this->filters() as $filter) {
            $value = $request->query($filter['name']);
            if ($value !== null && $value !== '') {
                $query->where($filter['column'] ?? $filter['name'], $value);
            }
        }

        return $query;
    }

    protected function messages(): array
    {
        return [];
    }

    /** Nombres legibles de los campos para los mensajes de validación. */
    protected function attributes(): array
    {
        return collect($this->fields(null))
            ->filter(fn ($f) => isset($f['name'], $f['label']))
            ->mapWithKeys(fn ($f) => [$f['name'] => mb_strtolower($f['label'])])
            ->all();
    }

    protected function form(?Model $record): View
    {
        $fields = collect($this->fields($record))->map(function (array $field) use ($record) {
            if (isset($field['name']) && ! array_key_exists('value', $field)) {
                $field['value'] = $record ? data_get($record, $field['name']) : ($field['default'] ?? null);
            }

            return $field;
        });

        return view('crud.form', [
            'page'   => $this->page(),
            'fields' => $fields,
            'record' => $record,
            'label'  => $record ? $this->recordLabel($record) : null,
            'top'    => $this->formTop($record),
            'action' => $record ? route(static::ROUTE.'.update', $record->getKey()) : route(static::ROUTE.'.store'),
        ]);
    }

    private function persist(array $data, ?Model $record, string $message): RedirectResponse
    {
        try {
            $saved = DB::transaction(fn () => $this->save($data, $record));
        } catch (QueryException $e) {
            return back()->withInput()->with('error', $this->dbErrorMessage($e));
        }

        return $this->afterSave($saved, $message);
    }

    /** Configuración que reciben las vistas genéricas. */
    protected function page(): array
    {
        $user = auth()->user();
        $can = fn (string $action, string $perm) => ($this->actions[$action] ?? false)
            && $user->hasPermission(static::PERMISSION.'.'.$perm);

        $routes = [
            'index' => route(static::ROUTE.'.index'),
            'data'  => route(static::ROUTE.'.data'),
            'create'=> route(static::ROUTE.'.create'),
            'edit'  => route(static::ROUTE.'.edit', '__ID__'),
            'destroy' => route(static::ROUTE.'.destroy', '__ID__'),
        ];

        if ($this->usesSoftDeletes()) {
            $routes['trash'] = route(static::ROUTE.'.trash');
            $routes['restore'] = route(static::ROUTE.'.restore', '__ID__');
        }

        return $this->meta() + [
            'columns'  => $this->columns(),
            'row_actions' => collect($this->rowActions())
                ->filter(fn ($a) => $user->hasPermission($a['permission']))
                ->map(fn ($a) => $a + ['url' => route($a['route'], '__ID__')])->values()->all(),
            'header_actions' => collect($this->headerActions())
                ->filter(fn ($a) => $user->hasPermission($a['permission']))
                ->map(fn ($a) => $a + ['url' => route($a['route'])])->values()->all(),
            'filters'  => $this->filters(),
            'routes'   => $routes,
            'trash'    => $this->usesSoftDeletes(),
            'can'      => [
                'create' => $can('create', 'crear'),
                'edit'   => $can('edit', 'editar'),
                'delete' => $can('delete', 'eliminar'),
            ],
        ];
    }

    private function applySearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $w) use ($term) {
            foreach ($this->searchable as $column) {
                if (str_contains($column, '.')) {
                    $w->orWhereHas(Str::beforeLast($column, '.'),
                        fn (Builder $r) => $r->where(Str::afterLast($column, '.'), 'like', "%{$term}%"));
                } else {
                    $w->orWhere($column, 'like', "%{$term}%");
                }
            }
        });
    }

    protected function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive(static::MODEL), true);
    }

    /** Traduce los errores del motor (FK, UNIQUE, CHECK, triggers) a un mensaje legible. */
    protected function dbErrorMessage(QueryException $e): string
    {
        $driverCode = $e->errorInfo[1] ?? null;
        $sqlState = $e->errorInfo[0] ?? null;

        Log::warning('Error de base de datos en '.static::class, ['code' => $driverCode, 'error' => $e->getMessage()]);

        return match (true) {
            $sqlState === '45000'                 => $e->errorInfo[2], // SIGNAL de un trigger: mensaje de negocio
            $driverCode === 1062                  => 'Ya existe un registro con esos datos (valor duplicado).',
            in_array($driverCode, [1451, 1217])   => 'No se puede eliminar: el registro está siendo usado por otros datos.',
            in_array($driverCode, [1452, 1216])   => 'Uno de los valores seleccionados no existe o no es válido.',
            in_array($driverCode, [3819, 4025])   => 'Los datos no cumplen una regla de integridad de la base de datos.',
            default                               => 'No se pudo completar la operación en la base de datos.',
        };
    }
}
