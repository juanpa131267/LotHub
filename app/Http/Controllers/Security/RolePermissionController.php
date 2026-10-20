<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\Security\Permission;
use App\Models\Security\Role;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Matriz visual rol ↔ permisos (CU-05 «include» Asignar permisos a roles). */
class RolePermissionController extends Controller
{
    /** Nombres legibles de los módulos (prefijo del código de permiso). */
    private const MODULES = [
        'sistema' => 'Sistema', 'panel' => 'Panel', 'usuarios' => 'Usuarios', 'roles' => 'Roles',
        'permisos' => 'Permisos', 'auditoria' => 'Auditoría', 'catalogos' => 'Catálogos',
        'proyectos' => 'Proyectos', 'lotes' => 'Lotes', 'clientes' => 'Clientes',
        'solicitudes' => 'Solicitudes de información', 'reservas' => 'Reservas',
        'negociaciones' => 'Negociaciones', 'acuerdos' => 'Acuerdos de pago', 'abonos' => 'Abonos',
        'conciliacion' => 'Conciliación', 'cartera' => 'Cartera', 'estados_cuenta' => 'Estados de cuenta',
        'reclamos' => 'Consultas y reclamos', 'documentos' => 'Documentos', 'portal' => 'Portal del cliente',
    ];

    public function index(Request $request): View
    {
        $roles = Role::withCount('permissions')->orderBy('id')->get();
        $role = $roles->firstWhere('id', (int) $request->query('rol')) ?? $roles->first();

        $assigned = $role?->permissions()->pluck('permissions.id')->all() ?? [];

        $groups = Permission::orderBy('code')->get()
            ->groupBy('module')
            ->map(fn ($perms, $module) => [
                'label'       => self::MODULES[$module] ?? ucfirst($module),
                'permissions' => $perms,
            ]);

        return view('security.role-permissions', compact('roles', 'role', 'assigned', 'groups'));
    }

    /** POST: activa o desactiva un permiso para un rol. */
    public function toggle(Request $request, Role $role, Permission $permission): JsonResponse
    {
        $enabled = $request->boolean('enabled');

        if (! $enabled && $role->isProtected() && $permission->code === config('lothub.super_permission')) {
            return response()->json(['ok' => false, 'message' => 'El administrador debe conservar el acceso total.'], 422);
        }

        $enabled
            ? $role->permissions()->syncWithoutDetaching([$permission->id])
            : $role->permissions()->detach($permission->id);

        AuditLogger::log($enabled ? 'ASIGNAR_PERMISO' : 'QUITAR_PERMISO', 'permission_role', $role->id, [], [
            'rol' => $role->name, 'permiso' => $permission->code,
        ]);

        return response()->json(['ok' => true, 'count' => $role->permissions()->count()]);
    }
}
