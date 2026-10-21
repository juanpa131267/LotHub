# Operación en paralelo (Sprint 10)

Antes de la puesta en producción, LotHub opera junto a las hojas de cálculo actuales. Durante ese
periodo cada pago se registra en los dos lados y, en cada corte, se comparan los saldos. Solo se
apaga la hoja cuando los cortes coinciden de forma sostenida.

## 1. Preparación

1. Cargar el inventario de lotes de cada proyecto (plano interactivo o **Lotes → Carga masiva**).
2. Registrar usuarios y roles del personal (Seguridad → Usuarios); cada uno cambia su contraseña
   temporal al primer ingreso.
3. Descargar **Operación en paralelo → Plantilla de migración** y copiar desde la hoja una fila por
   negociación vigente: cliente, lote, precio, condiciones del acuerdo, total pagado y saldo según la hoja.
4. Cargar la plantilla. La migración es atómica: si una fila tiene un error no se migra ninguna y se
   indica qué corregir. Por cada fila el sistema crea el cliente (o reutiliza el existente), la
   negociación, el acuerdo con su plan de cuotas y un abono **«Pagado antes del sistema»** por el total
   pagado, aplicado desde la cuota más antigua.
5. Por defecto la mora se liquida desde la fecha de la migración (no se recalcula la mora anterior,
   que ya está reflejada en el total pagado de la hoja).

Limitaciones conocidas de la migración:

- Una negociación con varios compradores se migra con su titular principal; los demás compradores
  se registran después en la ficha del cliente y la negociación.
- El detalle histórico de cada pago anterior no se reconstruye: queda un único abono con el total.

## 2. Rutina durante el paralelo

| Frecuencia | Responsable | Actividad |
|---|---|---|
| Diaria | Auxiliar de cartera | Registrar cada pago en la hoja y en LotHub (abono manual, extracto bancario o pasarela) |
| Diaria | Auxiliar de cartera | Revisar la cola de revisión del motor de asignación y la conciliación de la pasarela |
| Semanal | Auxiliar de cartera | Cargar un corte: **Plantilla del corte** → saldo según la hoja → **Comparar corte** |
| Semanal | Director comercial | Revisar las diferencias del corte y decidir la corrección (en la hoja o en el sistema) |
| Cada corte | Product Owner | Validar el avance contra los criterios de salida |

La comparación clasifica cada negociación como **Coincide**, **Diferencia**, **No migrada** (está en
la hoja pero no en el sistema) o **Solo en el sistema**. La tolerancia por defecto es de $ 1.000 para
absorber redondeos. Cada corte queda guardado y se puede exportar a Excel.

## 3. Criterios para salir del paralelo

- Dos cortes semanales consecutivos con **0 diferencias** y **0 negociaciones no migradas**.
- Cola de revisión del motor de asignación y conciliación de la pasarela al día.
- Cero reclamos abiertos por errores de saldo atribuibles al sistema.
- Aprobación del Product Owner en la revisión del sprint.

Con los criterios cumplidos se congela la hoja (solo lectura, como respaldo) y se procede con la
puesta en producción (S11, ver [DESPLIEGUE.md](DESPLIEGUE.md)).
