# Arquitectura

## Principios

1. **La base de datos es la fuente de verdad.** El modelo está en
   `database/schema/cartera_inmobiliaria.sql` (sección 10.3.5 del documento). Las reglas que el
   motor puede garantizar (UNIQUE, FK, CHECK, triggers) **no se duplican** en PHP; la aplicación
   solo las traduce a mensajes legibles (`CrudController::dbErrorMessage`).
2. **Declarar en vez de repetir.** Cada módulo CRUD es una clase que declara modelo, columnas,
   campos y reglas. Listado, búsqueda, paginación, formularios, papelera y permisos los resuelve
   `CrudController` + las vistas `resources/views/crud/*`.
3. **Mínimo de piezas.** Laravel 12 lee su configuración por defecto desde el framework y el
   `.env`; por eso `config/` solo contiene lo que cambia (`app.php` para la zona horaria y
   `lothub.php` para el negocio). No hay migraciones, Node, Vite ni paquetes extra.

## Flujo de una petición CRUD

```
GET /comercial/lotes                     Route::crud('/lotes', LotController::class)
  └─ middleware auth + permission:lotes.ver
      └─ CrudController::index()  → view crud.index  (columnas, filtros, permisos de botones)
          └─ public/js/crud.js  → GET /comercial/lotes/datos?q=&page=&project_id=
              └─ CrudController::data()  → query() + búsqueda + filtros + paginación → JSON
                  └─ LotController::row()  (valores calculados por fila)

POST /comercial/lotes  → rules() → DB::transaction(save()) → redirect con mensaje
                         └─ un trigger/FK falla → QueryException → mensaje de negocio
```

## Reglas de negocio y dónde viven

| Regla | Dónde | Notas |
|---|---|---|
| RN-01 lote único por proyecto/manzana/número | UNIQUE en BD + regla `unique` en `LotController` | La regla de Laravel da un mensaje claro antes de llegar al motor. |
| RN-04/05/08 estado del lote al reservar/vender/anular | Triggers | Por eso el formulario de lotes solo alterna DISPONIBLE ↔ BLOQUEADO. |
| RN-03 vencimiento de reservas | Evento `ev_expire_reservations` | Requiere `event_scheduler=ON` en el servidor MySQL. |
| RN-13/14/15 abonos | Triggers | Los abonos nunca se borran ni editan: solo se reversan. |
| RN-21 el cliente solo ve lo suyo | `PortalController` (filtra por `sale_clients`) | |
| RN-22 autorización de datos | `ClientController` crea `data_consents` | |
| RN-25 bloqueo e intentos | `LoginController` + `config/lothub.php` | 5 intentos → 15 min. Token de recuperación de un uso, 30 min. |
| RN-26 no borrar lo que está en uso | `deleteBlockedReason()` + FK RESTRICT + borrado lógico | Las entidades con `deleted_at` van a la papelera; no hay borrado definitivo. |
| RN-27 auditoría | `AuditObserver` (automático) + `AuditLogger::log()` (eventos) | Login, bloqueos, cambios de rol y de permisos también se registran. |

## Catálogos cerrados

Los catálogos de **estados** (`lot_statuses`, `project_statuses`, `sale_statuses`…) tienen ids
fijos que usan los triggers y las vistas. Están modelados (`LotStatus`, `ProjectStatus` con
constantes) pero **no tienen CRUD** a propósito. Si se agrega un estado, hacerlo en el script SQL.

## Seguridad

- Contraseñas con bcrypt en `users.password_hash` (RNF-01). La tabla no tiene `remember_token`,
  por eso no existe "recordarme".
- CSRF en todos los formularios y en las peticiones `fetch` (`LotHub.request`).
- Todo lo que se pinta en tablas pasa por `LotHub.esc()`; Blade escapa con `{{ }}`.
- Límites de peticiones con nombre: `login`, `password`, `public-form` (`AppServiceProvider`).
  No usar `throttle:N,M` anónimo: comparte un solo contador por IP entre todas las rutas.

## Frontend

- `public/css/app.css`: tokens de diseño en `:root`, derivados del logo (azul `#305FB0`, lima `#BCCE1B`, vía `#11151C`), componentes, panel,
  CRUD, autenticación y sitio público. Usar siempre las variables, nunca colores sueltos.
- Logo: `public/img/logo-mark.svg` (fondo claro), `logo-badge.svg` (fondos oscuros y favicon) y `logo.svg` (horizontal con nombre).
- `public/js/app.js`: `LotHub.request / toast / confirm / modal / esc` y el comportamiento del layout.
- `public/js/crud.js`: tabla genérica. Tipos de columna: `text, strong, muted, code, number, money, date, bool, badge, json`.
- El menú lateral se edita en un solo lugar: el arreglo `$menu` de `resources/views/partials/sidebar.blade.php`.
