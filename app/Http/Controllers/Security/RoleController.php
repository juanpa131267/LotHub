<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Security\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** CU-05 Gestionar roles. La asignación de permisos se hace en RolePermissionController. */
class RoleController extends CrudController
{
    const MODEL = Role::class;
    const ROUTE = 'roles';
    const PERMISSION = 'roles';

    protected array $searchable = ['name', 'description'];
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Roles',
            'subtitle' => 'Perfiles de acceso. Sus permisos se configuran en "Permisos por rol".',
            'icon'     => 'ph-shield-check',
            'singular' => 'rol',
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->withCount(['users', 'permissions']);
    }

    protected function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Rol', 'type' => 'strong'],
            ['key' => 'description', 'label' => 'Descripción', 'type' => 'muted'],
            ['key' => 'users_count', 'label' => 'Usuarios', 'type' => 'number'],
            ['key' => 'permissions_count', 'label' => 'Permisos', 'type' => 'number'],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'name', 'label' => 'Nombre del rol', 'required' => true, 'uppercase' => true, 'placeholder' => 'ASESOR_COMERCIAL'],
            ['name' => 'description', 'label' => 'Descripción', 'type' => 'textarea', 'rows' => 3],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'name'        => ['required', 'string', 'max:100', Rule::unique('roles')->ignore($record)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function deleteBlockedReason(Model $role): ?string
    {
        return match (true) {
            $role->isProtected()       => 'Este rol es del sistema y no puede eliminarse.',
            $role->users_count > 0     => 'No se puede eliminar: el rol está asignado a usuarios.',
            default                    => null,
        };
    }
}
