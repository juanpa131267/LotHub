# LotHub — Gestión y automatización de cartera inmobiliaria

Sistema web para una empresa que vende lotes con **financiación directa**: proyectos, lotes,
clientes, negociaciones, acuerdos de pago, cuotas, abonos y estados de cuenta en una sola base
de datos, con un sitio público para que los visitantes consulten la disponibilidad.

[![Pruebas](https://github.com/juanpa131267/LotHub/actions/workflows/tests.yml/badge.svg)](https://github.com/juanpa131267/LotHub/actions/workflows/tests.yml)
![PHP 8.2](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Laravel 12](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![MySQL 8](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.10+-3776AB?logo=python&logoColor=white)

> Documento de referencia del proyecto: *Trabajo de Práctica — Cartera* (no se publica en el repositorio)
> (requerimientos RF/RNF, reglas de negocio RN-xx y casos de uso CU-xx que se citan en el código).
>
> Planificación por sprints: [docs/CRONOGRAMA.md](docs/CRONOGRAMA.md) · Cambios: [CHANGELOG.md](CHANGELOG.md) ·
> Flujo de trabajo: [CONTRIBUTING.md](CONTRIBUTING.md)

## Funcionalidades

| Área | Qué hace |
|---|---|
| Seguridad | Ingreso con bloqueo por intentos, contraseña temporal obligatoria, recuperación y activación por enlace, roles y permisos, auditoría, HTTPS obligatorio |
| Comercial | Proyectos, etapas y lotes (plano interactivo, carga masiva, historial de precios, exportación), clientes con autorización de datos, reservas, negociaciones con varios compradores y anulación aprobada |
| Financiación | Acuerdos de pago con plan de cuotas (cuota 0, amortización francesa), reestructuración por versiones, liquidación diaria de mora con tope de usura |
| Pagos | Abonos manuales con soporte, aplicación mora → interés → capital, reversión, **motor de asignación automática** desde el extracto bancario, **pago en línea con Wompi** y conciliación |
| Cartera | Tablero de cartera y mora por edades, exportación a Excel, estado de cuenta en PDF |
| Atención | Canales de contacto autorizados, recordatorios y avisos de cobro conforme a la Ley 2300, consultas y reclamos con plazo legal (Ley 1581) |
| Portal del cliente | Estado de cuenta, plan de cuotas, pago en línea, PDF, avisos, preferencias de contacto y reclamos |
| Operación | Migración de la cartera vigente, cortes de la operación en paralelo, verificación de producción y respaldos |

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2 (extensiones `gd`, `zip`, `pdo_mysql`) · Laravel 12 · PhpSpreadsheet 5 (Excel) · Dompdf 3 (PDF) |
| Base de datos | MySQL 8 / MariaDB · esquema propio con vistas y triggers |
| Frontend | Blade + CSS y JS propios en `public/` (sin Node ni compilación) · íconos [Phosphor](https://phosphoricons.com) |
| Planos | Python (ezdxf + shapely) en `tools/plan-processor/` · LibreDWG opcional para DWG |
| Pagos en línea | Wompi (Web Checkout y eventos firmados) |
| Calidad | PHPUnit sobre el esquema real · GitHub Actions |

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

**2. Datos base** (permisos, matriz rol-permiso, los 33 departamentos y 1.122 municipios del DANE y usuario administrador):

```bash
php artisan db:seed
# Si ADMIN_PASSWORD está vacío, la contraseña generada se imprime UNA vez en consola.

php artisan db:seed --class=DemoDataSeeder   # opcional: datos de demostración (ver abajo). NUNCA en producción
```

**Datos de demostración.** `DemoDataSeeder` crea 6 proyectos (PRY-001 a PRY-003 y DEM-004 a DEM-006,
~310 lotes) y simula 18 meses de operación con los servicios reales del sistema, en orden
cronológico: unos 230 clientes, 60 reservas, 160 negociaciones (contado, financiadas y mixtas, con
uno a tres compradores), sus acuerdos y planes de cuotas (~4.500 cuotas), ~850 abonos de clientes
puntuales, atrasados, morosos y que pagan anticipado, pagos por Wompi, mora liquidada,
anulaciones, reestructuraciones, reversiones, un extracto bancario con asignación automática y
cola de revisión, comunicaciones de cobro, consultas y reclamos, una migración de cartera y un
corte de la operación en paralelo (más de 15.000 registros). Tarda cerca de un minuto.

| Usuario | Rol | Contraseña |
|---|---|---|
| `demo.director` | Director comercial | `Demo2026*` |
| `demo.asesor1`, `demo.asesor2`, `demo.asesor3` | Asesor comercial | `Demo2026*` |
| `demo.cartera1`, `demo.cartera2` | Auxiliar de cartera | `Demo2026*` |
| correo del cliente (ver Seguridad → Usuarios, rol CLIENTE) | Cliente con portal | `Cliente2026*` |

`DEMO_SCALE=0.3 php artisan db:seed --class=DemoDataSeeder` genera un volumen menor.

**3. Ejecutar:** `php artisan serve` → <http://localhost:8000> y, para las tareas programadas
(vencimientos, mora, comunicaciones, respaldos), `php artisan schedule:work` en otra terminal.

| URL | Qué es |
|---|---|
| `/` | Sitio público: proyectos en venta, mapa de lotes, formulario de contacto |
| `/ingresar` | Inicio de sesión del personal y de clientes |
| `/panel` | Panel administrativo (según permisos) |
| `/mi-cuenta` | Portal del cliente: estado de cuenta, plan de cuotas, pago en línea y PDF |
| `/mi-cuenta/atencion` | Portal del cliente: avisos, preferencias de contacto y reclamos |
| `/mi-perfil` | Perfil del usuario y cambio de contraseña |
| `/pasarela/wompi/eventos` | URL de eventos para Wompi (sin sesión; se valida la firma) |

Para producción sigue [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md) y verifica con `php artisan lothub:check`.

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
├── Http/Controllers/
│   ├── Crud/CrudController.php   ← base de todos los módulos CRUD (leer primero)
│   ├── Auth/  Account/           ← ingreso, recuperación, activación, perfil y contraseña
│   ├── Security/  Catalogs/      ← usuarios, roles, permisos, auditoría y catálogos
│   ├── Commercial/               ← proyectos, lotes, clientes, reservas, negociaciones, reclamos
│   ├── Financing/                ← acuerdos, obligaciones, cartera y estados de cuenta
│   ├── Payments/                 ← abonos, extractos y asignación, Wompi y conciliación
│   ├── Migration/                ← operación en paralelo
│   └── Dashboard/  Portal/  Public/  Documents/
├── Services/                     ← reglas de negocio por área (Commercial, Financing, Payments, Communications…)
├── Models/{Security,Catalogs,Commercial,Financing,Payments,Documents}/
├── Console/Commands/             ← tareas programadas, respaldo y lothub:check
└── Support/ColombianCalendar.php ← festivos y días hábiles de Colombia
config/lothub.php                 ← parámetros de negocio (usura, mora, asignación, Wompi, comunicaciones…)
database/schema/                  ← script SQL oficial + patches/ 001 a 008 (fuente de verdad del modelo de datos)
routes/web.php + routes/modules/  ← rutas por área funcional · routes/console.php ← planificador
resources/views/                  ← Blade por área + pdf/ y emails/
tests/Feature  tests/Unit         ← pruebas sobre el esquema real
tools/plan-processor/  tools/deploy/
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
- [docs/COMUNICACIONES.md](docs/COMUNICACIONES.md) — canales autorizados, recordatorios y avisos de cobro (Ley 2300) y consultas y reclamos.
- [docs/OPERACION_PARALELA.md](docs/OPERACION_PARALELA.md) — migración de la cartera vigente, cortes de comparación y criterios de salida.
- [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md) — servidor, configuración de producción, despliegues, respaldos y lista de chequeo del 28 de diciembre.
- [docs/CRONOGRAMA.md](docs/CRONOGRAMA.md) — sprints S1–S11, puntos de historia y estado de cada uno.

## Estado por sprint

| Sprint | Alcance | Versión | Estado |
|---|---|---|---|
| S1 | Seguridad: ingreso, bloqueo, roles, permisos y auditoría | v0.1.0 | ✅ |
| S2 | Catálogos, proyectos, etapas y lotes · plano interactivo · ficha de venta | v0.2.0 | ✅ |
| S3 | Clientes, autorización de datos y catálogo público | v0.3.0 | ✅ |
| — | Ajustes de S1 a S3 (contraseña temporal, perfil, activación, carga masiva, historial y exportación de lotes, HTTPS) | v0.3.1 | ✅ |
| S4 | Reservas y negociaciones | v0.4.0 | ✅ |
| S5 | Acuerdos de pago y plan de cuotas | v0.5.0 | ✅ |
| S6 | Motor de asignación automática | v0.6.0 | ✅ |
| S7 | Pasarela Wompi y conciliación | v0.7.0 | ✅ |
| S8 | Cartera y mora, liquidación de mora y estados de cuenta en PDF | v0.8.0 | ✅ |
| S9 | Comunicaciones de cobro (Ley 2300) y consultas y reclamos | v0.9.0 | ✅ |
| S10 | Operación en paralelo: migración y cortes de comparación | v0.10.0 | ✅ herramientas |
| S11 | Puesta en producción: verificación, respaldos, CI y despliegue | v1.0.0-rc.1 | ✅ herramientas · salida a producción el 28 de diciembre |

El contenido de S8 y S9 no estaba definido en el cronograma; se propuso con los casos de uso que
quedaban pendientes (ver [docs/CRONOGRAMA.md](docs/CRONOGRAMA.md)). La operación en paralelo y la
salida a producción son actividades del equipo con la empresa; el repositorio aporta las
herramientas y los procedimientos para realizarlas.
