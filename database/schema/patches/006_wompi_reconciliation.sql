-- =============================================================================
-- PARCHE 006 · Pasarela Wompi y conciliación (Sprint 7, CU-20 a CU-22)
--   · Registra la pasarela Wompi en sus dos ambientes.
--   · gateway_transactions.reported_amount: valor que informó la pasarela; si difiere del
--     solicitado no se crea el abono y la transacción queda para conciliación (CU-17.2 1a).
--   · Marcas de revisión de la conciliación en transacciones y notificaciones (CU-22 paso 3).
-- Aplicar después del parche 005:
--   mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/006_wompi_reconciliation.sql
-- =============================================================================
USE cartera_inmobiliaria;

INSERT IGNORE INTO payment_gateways (name, environment, is_active) VALUES
('Wompi', 'SANDBOX', TRUE), ('Wompi', 'PRODUCCION', TRUE);

ALTER TABLE gateway_transactions
    ADD COLUMN reported_amount DECIMAL(14,2) NULL AFTER amount,
    ADD COLUMN payment_method_type VARCHAR(30) NULL AFTER currency,   -- CARD, PSE, NEQUI… (nunca datos de la tarjeta, RN-18)
    ADD COLUMN reviewed_by INT NULL,
    ADD COLUMN reviewed_at DATETIME NULL,
    ADD COLUMN review_notes VARCHAR(255) NULL,
    ADD INDEX idx_gt_status_date (status_id, requested_at),
    ADD CONSTRAINT fk_gt_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id);

ALTER TABLE gateway_notifications
    ADD COLUMN external_reference VARCHAR(80) NULL AFTER transaction_id,
    ADD COLUMN reviewed_by INT NULL,
    ADD COLUMN reviewed_at DATETIME NULL,
    ADD COLUMN review_notes VARCHAR(255) NULL,
    ADD INDEX idx_gn_received (received_at),
    ADD CONSTRAINT fk_gn_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id);
