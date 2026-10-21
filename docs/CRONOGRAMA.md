# Cronograma por sprints

Planificación del proyecto en 11 sprints. Los puntos son puntos de historia de usuario; las fechas
detalladas están en el cronograma del equipo (Excel).

| Sprint | Alcance | Puntos | Cierre | Estado |
|---|---|---:|---|---|
| S1 | Seguridad: login, bloqueo por intentos, roles y permisos | 40 | — | ✅ Implementado |
| S2 | Catálogos, proyectos, etapas y lotes | 40 | — | ✅ Implementado |
| S3 | Clientes, autorización de datos y catálogo público | 45 | — | ✅ Implementado |
| — | Ajustes de S1 a S3 (US-AUTH-004/007/008, US-CLI-003, US-LOT-004/005/008, US-TEC-001) | — | — | ✅ Implementado |
| S4 | Reservas y negociaciones | 50 | — | ✅ Implementado |
| S5 | Acuerdos de pago y plan de cuotas | 58 | — | ✅ Implementado |
| S6 | Motor de asignación automática | 61 | — | ⏳ Pendiente |
| S7 | Pasarela de pagos (Wompi) y conciliación | 53 | 2026-10-20 | ⏳ Pendiente |
| S8 | Por definir | — | — | — |
| S9 | Por definir | — | — | — |
| S10 | Operación en paralelo | — | — | ⏳ Planificado |
| S11 | Puesta en producción | — | 2026-12-28 | ⏳ Planificado |

## Relación con el repositorio

- Cada sprint se desarrolla en una rama `feature/sNN-<tema>` que parte de `develop`.
- Al cerrar un sprint, `develop` se integra en `main` y se etiqueta la versión `v0.N.0`.
- El detalle de lo entregado en cada versión está en [CHANGELOG.md](../CHANGELOG.md).

## Observación

El esquema de base de datos (`database/schema/cartera_inmobiliaria.sql`) y la matriz de permisos
(`PermissionSeeder`) ya contemplan los módulos de S4 a S7; falta su implementación en la aplicación.
