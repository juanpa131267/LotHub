# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y [SemVer](https://semver.org/lang/es/).

## [Unreleased]

### Pendiente
- S4 Reservas y negociaciones · S5 Acuerdos de pago y plan de cuotas · S6 Motor de asignación automática · S7 Pasarela Wompi y conciliación.

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
