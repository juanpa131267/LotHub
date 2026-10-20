# Cómo agregar un módulo CRUD

Ejemplo: un catálogo de **pasarelas de pago** (`payment_gateways`, ya existe en el esquema).

## 1. Modelo — `app/Models/Catalogs/PaymentGateway.php`

```php
class PaymentGateway extends Model
{
    protected $table = 'payment_gateways';
    public $timestamps = false;                 // la tabla no tiene created_at/updated_at
    protected $fillable = ['name', 'environment', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
}
```

Revisa en el script SQL qué columnas de fecha tiene la tabla:
`created_at + updated_at` → nada; solo `created_at` → `const UPDATED_AT = null;`; ninguna →
`$timestamps = false`. Si tiene `deleted_at`, agrega `use SoftDeletes;` y obtendrás papelera.

## 2. Controlador — `app/Http/Controllers/Catalogs/PaymentGatewayController.php`

```php
class PaymentGatewayController extends CrudController
{
    const MODEL = PaymentGateway::class;
    const ROUTE = 'payment-gateways';          // nombres de ruta: payment-gateways.index, …
    const PERMISSION = 'catalogos';            // usa catalogos.ver / crear / editar / eliminar

    protected array $searchable = ['name'];

    protected function meta(): array
    {
        return ['title' => 'Pasarelas de pago', 'subtitle' => '…', 'icon' => 'ph-plugs-connected', 'singular' => 'pasarela'];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Nombre', 'type' => 'strong'],
            ['key' => 'environment', 'label' => 'Ambiente', 'type' => 'badge', 'colors' => ['SANDBOX' => 'warning', 'PRODUCCION' => 'success']],
            ['key' => 'is_active', 'label' => 'Activa', 'type' => 'bool'],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'name', 'label' => 'Nombre', 'required' => true, 'width' => 'half'],
            ['name' => 'environment', 'label' => 'Ambiente', 'type' => 'select', 'required' => true, 'width' => 'half',
             'options' => ['SANDBOX' => 'Pruebas', 'PRODUCCION' => 'Producción']],
            ['name' => 'is_active', 'label' => 'Estado', 'type' => 'checkbox', 'checkbox_label' => 'Activa', 'default' => true],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'name'        => ['required', 'string', 'max:60'],
            'environment' => ['required', Rule::in(['SANDBOX', 'PRODUCCION'])],
            'is_active'   => ['boolean'],
        ];
    }
}
```

## 3. Ruta — `routes/modules/catalogs.php`

```php
Route::crud('/pasarelas', Catalogs\PaymentGatewayController::class);
```

## 4. Menú — `resources/views/partials/sidebar.blade.php`

```php
['Pasarelas de pago', 'ph-plugs-connected', 'payment-gateways.index', 'catalogos.ver', 'payment-gateways.*'],
```

## 5. Auditoría

Agrega el modelo a `AppServiceProvider::AUDITED_MODELS`.

Listo: listado con búsqueda y paginación, formularios con validación, eliminación protegida y
permisos por acción.

## Ganchos disponibles en `CrudController`

| Método / propiedad | Para qué |
|---|---|
| `$with`, `$searchable`, `$orderBy`, `$orderDir` | Relaciones, búsqueda (`'relacion.columna'`), orden. |
| `$actions` | Desactivar crear/editar/eliminar (p. ej. auditoría es solo lectura). |
| `query()` | Agregar `withCount`, scopes o restricciones. |
| `filters()` | Desplegables sobre el listado (`name` = columna). |
| `row()` | Agregar valores calculados a cada fila del JSON. |
| `save()` | Guardar en varias tablas (ver `UserController`, `ClientController`). Corre dentro de una transacción. |
| `deleteBlockedReason()` | Impedir eliminar con un mensaje (RN-26). |
| `messages()` | Mensajes de validación específicos. |
| `recordLabel()` | Texto del chip "Editando: …". |

**Tipos de campo:** `text, email, number, date, password, textarea, select, checkbox, checkboxes,
section` (título separador) y `note` (texto de solo lectura). Anchos: `full, half, third, two-thirds`.

## Pantallas que no son CRUD

Para flujos con lógica propia (p. ej. registrar una negociación con varios compradores, o el
plan de cuotas) crea un controlador normal que extienda `Controller`, una vista con
`@extends('layouts.panel')` y protege sus rutas con `->middleware('permission:…')`.
`RolePermissionController` es un buen ejemplo.
