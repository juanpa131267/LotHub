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
| S6 | Motor de asignación automática | 61 | — | ✅ Implementado |
| S7 | Pasarela de pagos (Wompi) y conciliación | 53 | 2026-10-20 | ✅ Implementado |
| S8 | Cartera y mora, liquidación de intereses de mora y estados de cuenta en PDF (CU-23, CU-24, RN-19) | — | — | ✅ Implementado |
| S9 | Canales de contacto, recordatorios y avisos de cobro (Ley 2300) y consultas y reclamos (CU-27 a CU-29) | — | — | ✅ Implementado |
| S10 | Operación en paralelo: migración de la cartera vigente y cortes de comparación | — | — | ✅ Herramientas implementadas |
| S11 | Puesta en producción: verificación, respaldos, CI y guía de despliegue | — | 2026-12-28 | ✅ Herramientas implementadas · salida pendiente |

## Relación con el repositorio

- Cada sprint se desarrolla en una rama `feature/sNN-<tema>` que parte de `develop`.
- Al cerrar un sprint, `develop` se integra en `main` y se etiqueta la versión `v0.N.0`.
- El detalle de lo entregado en cada versión está en [CHANGELOG.md](../CHANGELOG.md).

> El documento del proyecto no define el contenido de S8 y S9; se propusieron con los casos de uso
> que quedaban pendientes del alcance (CU-23, CU-24, CU-27, CU-28 y CU-29) para revisión del Product Owner.

## Observación

S10 y S11 incluyen actividades del equipo con la empresa (operar en paralelo y salir a producción).
El repositorio entrega las herramientas y los procedimientos: migración y cortes de comparación
([OPERACION_PARALELA.md](OPERACION_PARALELA.md)) y verificación, respaldos y despliegue
([DESPLIEGUE.md](DESPLIEGUE.md)). La versión `v1.0.0` se etiqueta el día de la salida a producción.
