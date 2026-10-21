-- =============================================================================
-- PARCHE 007 · Comunicaciones al cliente (Sprint 9, CU-27, RN-23)
--   · notifications.message: texto exacto enviado (evidencia de lo que se comunicó al titular).
--   · notifications.payment_id: abono que originó una CONFIRMACION_PAGO.
-- Aplicar después del parche 006:
--   mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/007_notification_messages.sql
-- =============================================================================
USE cartera_inmobiliaria;

ALTER TABLE notifications
    ADD COLUMN message VARCHAR(500) NULL AFTER notification_type,
    ADD COLUMN payment_id BIGINT NULL AFTER installment_id,
    ADD INDEX idx_notif_status_date (status, scheduled_at),
    ADD CONSTRAINT fk_notif_payment FOREIGN KEY (payment_id) REFERENCES payments(id);

-- Una sola confirmación por abono.
ALTER TABLE notifications ADD UNIQUE KEY unq_notif_payment (payment_id, channel);
