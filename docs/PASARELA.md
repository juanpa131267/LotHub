# Pasarela de pagos Wompi y conciliación

El comprador paga desde el portal (**Mi estado de cuenta → Pagar en línea**) con los medios que
ofrece Wompi (tarjeta, PSE, Nequi, Bancolombia…). LotHub nunca recibe ni guarda datos de tarjetas
ni credenciales bancarias (RN-18): el pago ocurre en el checkout de Wompi.

## Configuración

1. En el panel de comercios de Wompi → *Desarrolladores → Llaves y secretos* copia al `.env`:

   ```dotenv
   WOMPI_ENV=sandbox                 # production en el ambiente real
   WOMPI_PUBLIC_KEY=pub_test_...
   WOMPI_PRIVATE_KEY=prv_test_...    # opcional: permite consultar transacciones por referencia
   WOMPI_INTEGRITY_SECRET=test_integrity_...
   WOMPI_EVENTS_SECRET=test_events_...
   ```

2. En *Desarrolladores → URL de eventos* registra `https://<dominio>/pasarela/wompi/eventos`.
   La URL debe ser pública y con HTTPS (`FORCE_HTTPS=true`, ver US-TEC-001).
3. Programa el planificador (`php artisan schedule:run` cada minuto): vence las transacciones
   abandonadas (`gateway:expire`) y las reservas (`reservations:expire`).

Sin las llaves, el botón de pago no aparece en el portal.

## Flujo (CU-20, CU-21, CU-17.2)

```
Portal ─ elige lo vencido, la próxima cuota, el saldo o un valor libre
  └─ OnlinePaymentService::start → gateway_transactions PENDIENTE (referencia LH{obligación}-{fecha}-{azar})
       └─ redirección a checkout.wompi.co con signature:integrity = SHA-256(referencia + centavos + COP + vencimiento + secreto)
Wompi ─ el cliente paga ─ vuelve a /mi-cuenta/pagos/{referencia}   (solo muestra el estado)
Wompi ─ POST /pasarela/wompi/eventos  (transaction.updated)
  └─ se guarda la notificación depurada y se valida signature.checksum
       = SHA-256(valores de signature.properties + timestamp + secreto de eventos)
       ├─ firma inválida → se guarda con signature_valid = false, responde 401 y no se procesa
       ├─ referencia desconocida → queda para conciliación
       └─ APPROVED con el mismo valor → abono automático (medio PASARELA, registered_by nulo)
            aplicado a cuotas como cualquier abono; una transacción = un abono (idempotencia)
```

Si el valor aprobado no coincide con el solicitado, no se crea el abono: la transacción aparece en
la conciliación para que el Auxiliar decida (CU-17.2 1a).

## Conciliación (CU-22)

**Cartera → Conciliación pasarela**, por periodo, muestra solo lo que necesita atención:

| Sección | Acción |
|---|---|
| Transacciones aprobadas sin abono (incluye diferencias de valor) | Reprocesar · Aplicar valor informado · Marcar revisada |
| Abonos de pasarela sin transacción aprobada (o anulada en Wompi) | Reversar el abono · Marcar revisada |
| Pendientes fuera de tiempo | Consultar en Wompi · Marcar revisada |
| Notificaciones con firma inválida o referencia desconocida | Marcar revisada |

Sin diferencias se informa «Conciliación completa». Todas las acciones quedan en auditoría.

## Código

| Pieza | Responsabilidad |
|---|---|
| `app/Services/Payments/WompiClient.php` | URL del checkout, firma de integridad, verificación de eventos y API |
| `app/Services/Payments/OnlinePaymentService.php` | Transacciones, eventos, abono automático y vencimiento |
| `app/Services/Payments/ReconciliationService.php` | Reporte y acciones de conciliación |
| `app/Http/Controllers/Payments/WompiWebhookController.php` | Endpoint de eventos (sin CSRF, con límite de peticiones) |
| `database/schema/patches/006_wompi_reconciliation.sql` | Pasarela registrada, valor informado y marcas de revisión |

Pruebas: `tests/Feature/OnlinePaymentTest.php` (firma, idempotencia, diferencias, referencias
desconocidas, RN-21 y vencimiento).
