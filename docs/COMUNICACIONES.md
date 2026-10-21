# Comunicaciones al cliente y atención de solicitudes

## Canales autorizados (Ley 2300 de 2023)

El titular decide por qué canales recibe comunicaciones de cobro: correo, WhatsApp, SMS, portal o
llamada. Se registran en **Clientes → Canales de contacto** o los gestiona el propio cliente en
**Portal → Atención al cliente**. Sin autorización de tratamiento de datos vigente no se habilita
ningún canal (RN-22). Revocar un canal conserva la fecha de la autorización como evidencia.

## Recordatorios y avisos de mora (CU-27, RN-23)

| Tarea | Cuándo | Qué hace |
|---|---|---|
| `notifications:schedule` | 6:00 a. m. todos los días | Programa los recordatorios (cuotas que vencen en `NOTIFY_REMINDER_DAYS` días) y los avisos de mora (obligaciones que cumplen `NOTIFY_OVERDUE_DAYS` días de mora) |
| `notifications:send` | cada 15 minutos | Envía lo programado cuya hora llegó; si está fuera del horario permitido lo reprograma |

Reglas que aplica el sistema a las comunicaciones de cobro:

- Solo por canales autorizados y vigentes (la llave foránea `fk_notif_channel` lo exige en la base).
- Lunes a viernes de 7:00 a. m. a 7:00 p. m. y sábados de 8:00 a. m. a 3:00 p. m.; nunca domingos ni
  festivos (calendario de festivos de Colombia, Ley 51 de 1983, en `App\Support\ColombianCalendar`).
- Máximo una comunicación de cobro por día y por cliente (el aviso de mora tiene prioridad sobre el recordatorio).
- Un solo canal por semana: si ese canal se revoca, no se cambia a otro hasta la semana siguiente.

La confirmación de pago no es una comunicación de cobro: se programa al aplicarse cada abono
(manual, extracto o pasarela) y sale en el siguiente envío.

Canales automáticos: correo y portal. SMS y WhatsApp se usan solo cuando se configure su proveedor
(`SMS_PROVIDER`, `WHATSAPP_PROVIDER`); la llamada siempre la hace una persona.
El texto exacto enviado queda en `notifications.message` y se consulta en **Atención → Comunicaciones**.

## Consultas y reclamos (CU-28, CU-29, RN-24)

El titular radica desde el portal una consulta, un reclamo, una rectificación o una supresión de datos.
La fecha límite se calcula en días hábiles: **10 para consultas** y **15 para reclamos** (Ley 1581 de 2012,
arts. 14 y 15). El personal las atiende en **Atención → Consultas y reclamos**: las vencidas y las que
vencen en los próximos 2 días se resaltan, y el panel muestra cuántas están abiertas. La respuesta se
envía al correo del titular y queda visible en su portal.
