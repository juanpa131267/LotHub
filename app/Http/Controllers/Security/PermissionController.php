<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Security\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Catálogo de permisos. El código sigue la convención "modulo.accion" porque
 * las rutas CRUD exigen {modulo}.ver / .crear / .editar / .eliminar.
 */
class PermissionController extends CrudController
{
    const MODEL = Permission::class;
    const ROUTE = 'permissions';
    const PERMISSION = 'permisos';

    protected array $searchable = ['code', 'description'];
    protected string $orderBy = 'code';
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Permisos',
            'subtitle' => 'Acciones protegidas del sistema. Código con el formato modulo.accion.',
            'icon'     => 'ph-key',
            'singular' => 'permiso',
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->withCount('roles');
    }

    protected function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Código', 'type' => 'code'],
            ['key' => 'description', 'label' => 'Descripción', 'type' => 'muted'],
            ['key' => 'roles_count', 'label' => 'Roles', 'type' => 'number'],
            ['key' => 'is_active', 'label' => 'Activo', 'type' => 'bool'],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'code', 'label' => 'Código', 'required' => true, 'placeholder' => 'lotes.exportar',
             'help' => 'Minúsculas, separado por puntos: modulo.accion'],
            ['name' => 'description', 'label' => 'Descripción', 'required' => true],
            ['name' => 'is_active', 'label' => 'Estado', 'type' => 'checkbox', 'checkbox_label' => 'Permiso activo', 'default' => true],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code'        => ['required', 'string', 'max:100', 'regex:/^[a-z_]+(\.[a-z_]+)+$/', Rule::unique('permissions')->ignore($record)],
            'description' => ['required', 'string', 'max:255'],
            'is_active'   => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return ['code.regex' => 'El código debe tener el formato modulo.accion (minúsculas y guion bajo).'];
    }

    protected function deleteBlockedReason(Model $permission): ?string
    {
        if ($permission->code === config('lothub.super_permission')) {
            return 'El permiso de acceso total no puede eliminarse.';
        }

        return $permission->roles_count > 0 ? 'No se puede eliminar: el permiso está asignado a roles. Desactívalo o quítalo primero.' : null;
    }
}
