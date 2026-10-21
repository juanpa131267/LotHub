# Motor de asignación automática de pagos

El área de cartera identificaba los pagos comparando a mano el extracto bancario con los soportes,
con errores frecuentes cuando había montos parecidos o pagos desde cuentas de terceros. El motor
automatiza esa comparación y deja a las personas solo los casos dudosos.

## Flujo

```
Cartera → Extractos bancarios → Cargar extracto (.xlsx, .xls o .csv)
  1. Se calcula el SHA-256 del archivo: el mismo extracto no se procesa dos veces.
  2. Se leen los movimientos crédito (los débitos y saldos se ignoran).
  3. Cada movimiento:
       ¿ya está registrado? (mismo día, valor y referencia en otro extracto, o referencia usada en un abono)
         └─ sí → DUPLICADA
       puntaje contra cada obligación activa (PaymentMatcher)
         ├─ mejor ≥ umbral y supera al segundo por el margen → abono automático → ASIGNADA
         └─ si no → REVISION (cola de revisión con los 5 mejores candidatos)
Cartera → Cola de revisión → asignar a un candidato, a otra obligación o descartar.
```

El abono se registra con `PaymentService` (CU-17/CU-18): se valida que la obligación esté activa
y que el pagador sea comprador, se aplica desde la cuota más antigua en el orden mora → interés
corriente → capital y, si el saldo llega a cero, la obligación queda CANCELADA y la negociación
PAGADA (RN-20). Si después se reversa un abono que vino del extracto, el movimiento vuelve a la cola.

## Puntaje (0 a 100)

| Señal | Puntos |
|---|---:|
| Documento del depositante igual a la identificación de un comprador | 45 |
| Referencia o descripción con el código de la obligación, la negociación o el cliente (`OBL-`, `VTA-`, `CLI-`) | 40 |
| … o con la identificación de un comprador | 35 |
| Valor igual a la cuota que sigue, a lo vencido o al saldo total (±1 peso) | 25 |
| … igual a otra cuota pendiente | 20 |
| … a ±2 % de la cuota que sigue | 10 |
| Nombre y apellido del comprador en la descripción | 15 |
| … solo el apellido | 8 |
| Fecha a ±10 días del vencimiento de la cuota que sigue | 5 |
| El valor supera el saldo total | −10 |

Decisión (`config/lothub.php` → `allocation`, variables `ALLOCATION_AUTO_THRESHOLD` y `ALLOCATION_MIN_MARGIN`):
se asigna automáticamente si el mejor candidato tiene **75 puntos o más** y supera al segundo por
**20 o más**. Con los pesos por defecto, un documento coincidente más el valor exacto de la cuota
(70) y la fecha cercana (5) bastan; un valor parecido sin otra señal nunca asigna solo.

## Formato del extracto

Se busca la fila de encabezados entre las primeras 20 y se reconocen los nombres más comunes:

| Campo | Encabezados reconocidos |
|---|---|
| Fecha (obligatorio) | Fecha, Fecha transacción, Fecha movimiento |
| Valor (obligatorio) | Valor, Monto, Importe — o la columna Crédito / Abono si el banco separa débitos y créditos |
| Descripción | Descripción, Concepto, Detalle |
| Referencia | Referencia, Ref, Número documento, Comprobante |
| Documento del depositante | NIT, Cédula, Identificación, Documento depositante |

Fechas `dd/mm/aaaa`, `aaaa-mm-dd` o fecha de Excel; valores `1.250.000`, `1,250,000.00` o `$ 1.250.000`.
Si el banco exporta otro formato, descarga la plantilla desde **Extractos bancarios → Plantilla**.

## Código

| Pieza | Responsabilidad |
|---|---|
| `app/Services/Payments/BankStatementParser.php` | Lectura del archivo y detección de columnas |
| `app/Services/Payments/PaymentMatcher.php` | Puntaje y decisión (clase pura, `tests/Unit/PaymentMatcherTest.php`) |
| `app/Services/Payments/BankStatementService.php` | Carga, duplicados, candidatos, asignación y descarte |
| `app/Services/Payments/PaymentService.php` | Registro, aplicación a cuotas, cierre y reversión de abonos |
| `database/schema/patches/005_bank_statements.sql` | Tablas `bank_statements` y `bank_statement_lines` |
