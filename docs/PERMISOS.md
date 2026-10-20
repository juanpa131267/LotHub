# Roles y permisos

## Modelo

`users ─< role_user >─ roles ─< permission_role >─ permissions`

Un usuario puede tener varios roles; sus permisos efectivos son la unión de los permisos
**activos** de esos roles (`User::permissionCodes()`, calculado una vez por petición).

El permiso `sistema.acceso_total` (configurable en `config/lothub.php`) concede todo. Lo tiene
el rol `ADMINISTRADOR`, que además está protegido contra eliminación.

## Convención de códigos

`modulo.accion`, en minúsculas. Los módulos CRUD usan cuatro acciones estándar:

| Acción | Rutas que protege |
|---|---|
| `{modulo}.ver` | listado y `/datos` |
| `{modulo}.crear` | formulario y guardado de nuevos |
| `{modulo}.editar` | edición |
| `{modulo}.eliminar` | eliminar, papelera y restaurar |

`Route::crud()` aplica estos middleware automáticamente usando la constante `PERMISSION` del
controlador. Permisos especiales (p. ej. `lotes.bloquear`, `roles.asignar_permisos`) se
verifican explícitamente donde se usan.

## Cómo se verifica

| Lugar | Forma |
|---|---|
| Ruta | `->middleware('permission:cartera.ver')` (varios separados por coma = basta uno) |
| Blade | `@can('clientes.crear') … @endcan` (gracias a `Gate::before`) |
| PHP | `auth()->user()->hasPermission('lotes.bloquear')` |
| Botones del CRUD | automático: `CrudController::page()['can']` |
| Menú lateral | cada opción declara su permiso en `partials/sidebar.blade.php` |

## Matriz inicial (según la Tabla 9 del documento)

| Rol | Alcance |
|---|---|
| ADMINISTRADOR | Todo (`sistema.acceso_total`). |
| ASESOR_COMERCIAL | Panel, ver proyectos y lotes, clientes (ver/crear/editar), solicitudes, reservas, negociaciones, documentos. |
| DIRECTOR_COMERCIAL | Todo lo del Asesor (generalización de actores) + proyectos/lotes completos, bloquear lotes, aprobar anulaciones, cartera. |
| AUXILIAR_CARTERA | Panel, consulta comercial, acuerdos, abonos, conciliación, cartera, estados de cuenta, reclamos, documentos. |
| CLIENTE | Portal: estado de cuenta, pago en línea, reclamos. |

La matriz se define en `database/seeders/PermissionSeeder.php`. El seeder es **idempotente**
y solo agrega asignaciones (no quita las que se hicieron a mano en la interfaz).
Para cambios puntuales usa **Seguridad → Permisos por rol** en el panel.

## Agregar un permiso nuevo

1. Agrégalo a `PermissionSeeder::PERMISSIONS` (y al rol que corresponda).
2. `php artisan db:seed --class=PermissionSeeder`
3. Úsalo en la ruta, la vista o el controlador.
