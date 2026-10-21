# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y [SemVer](https://semver.org/lang/es/).

## [Unreleased]

### Pendiente
- S4 Reservas y negociaciones · S5 Acuerdos de pago y plan de cuotas · S6 Motor de asignación automática · S7 Pasarela Wompi y conciliación.

## [0.10.0] — Sprint 10: operación en paralelo

### Added
- Migración de la cartera vigente desde una plantilla de Excel: cliente, negociación sobre el inventario, acuerdo con su
  plan de cuotas y abono «Pagado antes del sistema» por lo ya recaudado; atómica, con reporte de errores por fila y
  liquidación de mora desde la migración (opcional).
- Cortes de la operación en paralelo: saldo según la hoja vs. saldo del sistema por negociación (coincide, diferencia,
  no migrada, solo en el sistema), con tolerancia configurable, historial y exportación a Excel.
- Plan de operación en paralelo con rutinas y criterios de salida (`docs/OPERACION_PARALELA.md`).

### Database
- Parche `008_legacy_migration.sql`: medio de pago `SALDO_MIGRADO`, `legacy_imports`, `legacy_import_rows` y `parallel_runs`.

## [0.9.0] — Sprint 9: comunicaciones de cobro y atención al titular

### Added
- Canales de contacto autorizados por el titular (correo, WhatsApp, SMS, portal, llamada), gestionables por el
  personal y por el cliente desde el portal, siempre con autorización de datos vigente (RN-22).
- Recordatorios de vencimiento y avisos de mora (CU-27) que cumplen la Ley 2300 de 2023 (RN-23): canales autorizados,
  horario permitido, sin domingos ni festivos, una comunicación de cobro al día y un solo canal por semana.
- Calendario de festivos de Colombia (Ley 51 de 1983 y fiestas móviles según la Pascua) y cálculo de días hábiles.
- Confirmación automática de cada abono aplicado al cliente.
- Consultas y reclamos (CU-28, CU-29): radicación desde el portal con fecha límite en días hábiles (10 o 15, RN-24),
  bandeja de atención con plazos resaltados, respuesta por correo y en el portal.
- Registro de comunicaciones enviadas con el texto exacto de cada una.

### Database
- Parche `007_notification_messages.sql`: `notifications.message` y `notifications.payment_id`.

## [0.8.0] — Sprint 8: cartera, mora y estados de cuenta

### Added
- Liquidación diaria de intereses de mora (RN-19, CU-27): sobre capital e interés corriente vencidos, con la tasa
  pactada sin superar la usura vigente, días de gracia configurables e idempotente por fecha (`portfolio:late-charges`).
- Tablero de cartera y mora (CU-23): saldo, índice de mora, edades de mora (al día, 1-30, 31-60, 61-90, más de 90),
  filtros por proyecto, rango y cliente, y exportación a Excel del resultado filtrado.
- Estado de cuenta en PDF (CU-24) para el personal de cartera y para el cliente desde el portal (RN-21), archivado
  con su huella SHA-256 en `account_statements`.

### Fixed
- Los archivos privados (documentos, extractos y estados de cuenta) se ignoran en Git (`storage/app/private`).

## [0.7.0] — Sprint 7: pasarela Wompi y conciliación

### Added
- Pago en línea desde el portal (CU-20): lo vencido, la próxima cuota, el saldo o un valor libre; redirección al
  Web Checkout de Wompi con firma de integridad y página de resultado que se actualiza mientras llega la confirmación.
- Notificaciones de Wompi (CU-21): endpoint de eventos sin CSRF, validación de la firma, payload depurado (RN-18),
  idempotencia ante notificaciones repetidas y referencias desconocidas para conciliación.
- Abono automático (CU-17.2) solo con transacción APROBADA, firma válida y el valor solicitado (RN-17).
- Vencimiento de transacciones abandonadas con consulta previa a Wompi (`gateway:expire` en el planificador).
- Conciliación por periodo (CU-22): aprobadas sin abono, diferencias de valor, abonos sin transacción aprobada,
  pendientes fuera de tiempo y notificaciones inválidas, con reproceso, aplicación del valor informado y revisión.

### Fixed
- Parches de base de datos compatibles con MySQL 8 (sin `ADD COLUMN IF NOT EXISTS`).
- Vistas que mezclaban `@php(...)` en línea con bloques `@php`, que Blade compila de forma incorrecta.

### Database
- Parche `006_wompi_reconciliation.sql`: pasarela Wompi, `reported_amount`, tipo de medio y marcas de revisión.

## [0.6.0] — Sprint 6: motor de asignación automática

### Added
- Abonos (CU-17 a CU-19): registro manual con soporte de pago y advertencia de referencia duplicada, aplicación
  automática desde la cuota más antigua en el orden mora → interés corriente → capital con saldo a favor (RN-14),
  cierre de la obligación y la negociación al llegar a saldo cero (RN-20) y reversión con motivo (RN-15).
- Carga del extracto bancario (Excel o CSV) con detección de columnas, solo créditos y control de archivo repetido.
- Cálculo del puntaje de asignación por documento del depositante, códigos en la referencia, valor, nombre y fecha;
  asignación automática con umbral y margen configurables (ver `docs/ASIGNACION.md`).
- Cola de revisión con candidatos recalculados, asignación manual a cualquier obligación y descarte con motivo;
  los movimientos repetidos se marcan como duplicados.
- Bandeja «Pagos por revisar» en el panel.

### Database
- Parche `005_bank_statements.sql`: tablas `bank_statements` y `bank_statement_lines`.

## [0.5.0] — Sprint 5: acuerdos de pago y plan de cuotas

### Added
- Acuerdos de pago (CU-13): cuota inicial, capital financiado, número de cuotas, periodicidad, tasa corriente y de mora,
  primer vencimiento y firma con el acuerdo firmado como documento soporte. Vista previa del plan antes de guardar.
- Generación de la obligación y del plan de cuotas (CU-14): cuota 0 para la cuota inicial, amortización francesa a la
  tasa equivalente del periodo, fechas sin desbordar el mes y ajuste del redondeo en la última cuota (RN-12).
- Reglas del acuerdo: cuota inicial + capital = precio pactado (RN-09), contado en una sola cuota sin intereses (RN-10)
  y tasas que no superan el límite de usura configurable `USURY_MONTHLY_RATE` (RN-19).
- Reestructuración (CU-16): nueva versión sobre el saldo con motivo; la obligación anterior queda REESTRUCTURADA (RN-11).
- Obligaciones: listado con saldo, estado (al día / en mora) y detalle del plan con saldo, mora y estado de cada cuota.
- Bandeja «Acuerdos pendientes» y bandejas de trabajo en el panel (reservas activas, anulaciones, acuerdos).
- Portal del cliente con el plan de cuotas de cada obligación (RN-21).

## [0.4.0] — Sprint 4: reservas y negociaciones

### Added
- Reservas de lotes (CU-11): registro con buscador de lotes disponibles y de clientes, detalle, anulación con motivo
  y vencimiento automático (comando `reservations:expire` en el planificador como respaldo del evento de la base).
- Negociaciones (CU-12): uno o varios compradores con titular principal y participaciones que suman 100 % (RN-07),
  conversión de la reserva (RN-05), precio pactado y modalidad de pago.
- Anulación o desistimiento (CU-15): el asesor solicita con motivo, el director aprueba o rechaza desde su bandeja;
  al aprobar se anula la obligación y se libera el lote (RN-08). Con abonos aplicados se exige registrar su tratamiento.
- Documentos soporte (CU-25): promesa, identidad y otros sobre la negociación, en almacenamiento privado con huella
  SHA-256 que se verifica al descargar (RN-28).

### Database
- Parche `004_sale_cancellation_requests.sql`: tabla `sale_cancellation_requests` y trigger `trg_res_after_update`
  (al anular una reserva el lote vuelve a DISPONIBLE).

## [0.3.1] — Ajustes de los sprints 1 a 3

### Added
- Cambio obligatorio de contraseña en el primer ingreso cuando la contraseña es temporal (US-AUTH-004).
- Pantalla «Mi perfil»: datos de contacto propios y cambio de contraseña (US-AUTH-007).
- Activación de la cuenta del portal por enlace de un solo uso; el asesor invita, reenvía o revoca el acceso
  desde la ficha del cliente, siempre con autorización de datos vigente (US-AUTH-008, US-CLI-003, RN-22).
- Carga masiva de lotes desde una plantilla de Excel por proyecto, atómica y con reporte de errores (US-LOT-004).
- Historial de precios de cada lote con origen y usuario, registrado por trigger (US-LOT-005).
- Exportación del inventario de lotes a Excel con los filtros del listado (US-LOT-008).
- HTTPS obligatorio en producción: redirección 301, HSTS y soporte de proxies de confianza (US-TEC-001).
- Pruebas automáticas con PHPUnit sobre una base de pruebas con el esquema real.

### Database
- Parche `003_account_security_and_lot_history.sql`: `users.must_change_password`, `users.password_changed_at`,
  `password_resets.purpose`, tabla `lot_price_history` y trigger `trg_lot_price_history`.

## [0.3.0] — Sprint 3: clientes y catálogo público

### Added
- Clientes con registro de autorización de tratamiento de datos (Ley 1581 de 2012).
- Solicitudes de información desde el sitio público (con límite de envíos).
- Sitio público: proyectos en venta, disponibilidad de lotes sobre el plano y formulario de contacto.
- Portal del cliente (`/mi-cuenta`) y panel con indicadores (base).

## [0.2.0] — Sprint 2: catálogos, proyectos, etapas y lotes

### Added
- Catálogos parametrizables: departamentos, municipios, tipos de identificación, tipos de documento,
  modalidades de financiación, periodicidades y medios de pago.
- Proyectos, etapas y lotes.
- Plano interactivo: carga DWG/DXF, reconstrucción de lotes con `tools/plan-processor`, revisión y sincronización.
- Ficha de venta: fotos del proyecto y precios por m² o por planilla de Excel.
- Datos de geografía y datos de demostración.

## [0.1.0] — Sprint 1: seguridad

### Added
- Inicio de sesión, cierre de sesión y bloqueo de cuenta por intentos fallidos (RN-25).
- Recuperación de contraseña por correo.
- Usuarios, roles, permisos y matriz rol-permiso; middleware `permission:*`.
- Auditoría de cambios (`AuditObserver`, `AuditLogger`).
- Base CRUD declarativa (`CrudController`) y layout del panel.
- Esquema de base de datos oficial (`database/schema/cartera_inmobiliaria.sql`).
