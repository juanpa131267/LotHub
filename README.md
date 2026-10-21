# LotHub — Gestión y automatización de cartera inmobiliaria

Sistema web para una empresa que vende lotes con **financiación directa**: proyectos, lotes,
clientes, negociaciones, acuerdos de pago, cuotas, abonos y estados de cuenta en una sola base
de datos, con un sitio público para que los visitantes consulten la disponibilidad.

![PHP 8.2](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Laravel 12](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![MySQL 8](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.10+-3776AB?logo=python&logoColor=white)

> Documento de referencia del proyecto: *Trabajo de Práctica — Cartera* (no se publica en el repositorio)
> (requerimientos RF/RNF, reglas de negocio RN-xx y casos de uso CU-xx que se citan en el código).
>
> Planificación por sprints: [docs/CRONOGRAMA.md](docs/CRONOGRAMA.md) · Cambios: [CHANGELOG.md](CHANGELOG.md) ·
> Flujo de trabajo: [CONTRIBUTING.md](CONTRIBUTING.md)

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2 (extensiones `gd`, `zip`, `pdo_mysql`) · Laravel 12 · PhpSpreadsheet 5 (planilla de precios) |
| Base de datos | MySQL 8 / MariaDB · esquema propio con vistas y triggers |
| Frontend | Blade + CSS y JS propios en `public/` (sin Node ni compilación) · íconos [Phosphor](https://phosphoricons.com) |
| Planos | Python (ezdxf + shapely) en `tools/plan-processor/` · LibreDWG opcional para DWG |

## Puesta en marcha

```bash
composer install
cp .env.example .env          # completar DB_PASSWORD (y opcionalmente ADMIN_PASSWORD)
php artisan key:generate
```

**1. Base de datos.** El esquema NO se crea con migraciones de Laravel: se carga desde el script
oficial, que incluye tablas, vistas, triggers y el evento de reservas vencidas.

```bash
mysql -u root -p < database/schema/cartera_inmobiliaria.sql
# Parches, en orden numérico; cada uno se aplica una sola vez (compatibles con MySQL 8 y MariaDB):
for f in database/schema/patches/*.sql; do mysql -u root -p cartera_inmobiliaria < "$f"; done
# El usuario de la app (cartera_admin) necesita privilegios sobre cartera_inmobiliaria.
# Después de aplicar un parche nuevo ejecuta de nuevo: php artisan db:seed --class=PermissionSeeder
```

**Plano interactivo** (opcional): requiere Python 3.10+ para el procesador de planos.

```bash
python -m venv tools/plan-processor/.venv
tools/plan-processor/.venv/Scripts/pip install -r tools/plan-processor/requirements.txt
# DWG además de DXF: LibreDWG en tools/plan-processor/bin/ (ver docs/PLANOS.md)
```

**2. Datos base** (permisos, matriz rol-permiso, departamentos/municipios y usuario administrador):

```bash
php artisan db:seed
# Si ADMIN_PASSWORD está vacío, la contraseña generada se imprime UNA vez en consola.

php artisan db:seed --class=DemoDataSeeder   # opcional: 3 proyectos con lotes de ejemplo
```

**3. Ejecutar:** `php artisan serve` → <http://localhost:8000>

| URL | Qué es |
|---|---|
| `/` | Sitio público: proyectos en venta, mapa de lotes, formulario de contacto |
| `/ingresar` | Inicio de sesión del personal y de clientes |
| `/panel` | Panel administrativo (según permisos) |
| `/mi-cuenta` | Portal del cliente (estado de cuenta) |
| `/mi-perfil` | Perfil del usuario y cambio de contraseña |

## Pruebas

Las pruebas automáticas (PHPUnit) corren sobre una base aparte, `test_lothub`, creada desde el
script oficial y sus parches, con los triggers reales. Cada prueba se revierte al terminar.

```bash
bash tests/setup-test-db.sh      # crea/recrea test_lothub (MYSQL=ruta/a/mysql si no está en el PATH)
composer test                    # o: php vendor/bin/phpunit
```

## Estructura

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Crud/CrudController.php   ← base de todos los módulos CRUD (leer primero)
│   │   ├── Auth/                     ← login, bloqueo por intentos, recuperar contraseña
│   │   ├── Security/                 ← usuarios, roles, permisos, matriz rol-permiso, auditoría
│   │   ├── Catalogs/                 ← catálogos parametrizables
│   │   ├── Commercial/               ← proyectos, etapas, lotes, clientes, solicitudes
│   │   ├── Dashboard/  Portal/  Public/
│   └── Middleware/CheckPermission.php
├── Models/{Security,Catalogs,Commercial}/   ← mapean el esquema SQL existente
├── Services/AuditLogger.php   Observers/AuditObserver.php   Mail/
└── Providers/AppServiceProvider.php         ← macro Route::crud, Gate, auditoría, rate limits
config/lothub.php                 ← parámetros de negocio (intentos de login, roles protegidos…)
database/schema/                  ← script SQL oficial + patches/ (fuente de verdad del modelo de datos)
tools/plan-processor/             ← procesador de planos DXF → JSON (Python)
database/seeders/                 ← permisos, geografía, admin, demo
lang/es/validation.php            ← mensajes de validación en español
routes/web.php + routes/modules/  ← rutas por área funcional
resources/views/
├── layouts/  (panel, public, auth)   partials/ (head, sidebar)
├── crud/     (index, form, trash)  ← vistas genéricas que usan todos los CRUD
└── auth/ dashboard/ security/ portal/ public/ emails/
public/css/app.css  public/js/{app,crud}.js
docs/                             ← guías para el equipo
```

## Documentación para el equipo

- [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) — decisiones de diseño, flujo de una petición, reglas que viven en la base de datos.
- [docs/PERMISOS.md](docs/PERMISOS.md) — roles, permisos y cómo se protege cada ruta y botón.
- [docs/NUEVO_MODULO.md](docs/NUEVO_MODULO.md) — paso a paso para agregar un CRUD nuevo (≈ 15 minutos).
- [docs/PLANOS.md](docs/PLANOS.md) — plano interactivo: cómo preparar el DWG/DXF, el algoritmo y su instalación.
- [docs/FICHA_DE_VENTA.md](docs/FICHA_DE_VENTA.md) — fotos del proyecto, precios (por m² o planilla de Excel) y nomenclatura MANZANA-NÚMERO.

- [docs/ASIGNACION.md](docs/ASIGNACION.md) — motor de asignación automática de pagos: extracto, puntaje y cola de revisión.
- [docs/PASARELA.md](docs/PASARELA.md) — pago en línea con Wompi, notificaciones firmadas y conciliación.
- [docs/CRONOGRAMA.md](docs/CRONOGRAMA.md) — sprints S1–S11, puntos de historia y estado de cada uno.

## Estado por sprint

| Sprint | Alcance | Estado |
|---|---|---|
| S1 | Autenticación, bloqueo RN-25, recuperación de contraseña · usuarios, roles, permisos, matriz rol-permiso, auditoría | ✅ |
| S2 | Catálogos parametrizables · proyectos, etapas y lotes · plano interactivo DWG/DXF · ficha de venta (fotos y precios) | ✅ |
| S3 | Clientes con autorización de datos (Ley 1581) · solicitudes de información · catálogo público con disponibilidad de lotes | ✅ |
| — | Panel con indicadores · portal del cliente (lectura de `v_obligation_balance`) | ✅ base |
| S4 – S7 | Reservas y negociaciones · acuerdos de pago y plan de cuotas · motor de asignación · pasarela Wompi y conciliación | ⏳ pendiente (esquema y permisos ya existen) |
| S8 – S11 | Por definir · operación en paralelo · puesta en producción | ⏳ planificado |
