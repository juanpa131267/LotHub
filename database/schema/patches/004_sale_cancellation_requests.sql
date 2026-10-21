-- =============================================================================
-- PARCHE 004 · Solicitudes de anulación o desistimiento de negociaciones (CU-15, RN-08)
-- El Asesor comercial solicita con un motivo y el Director comercial aprueba o rechaza.
-- Al aprobar, la aplicación cambia sales.status_id a ANULADA (3) o DESISTIDA (4) con
-- cancellation_reason y approved_by; el trigger trg_sale_after_update libera el lote.
-- Aplicar después del parche 003:
--   mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/004_sale_cancellation_requests.sql
-- =============================================================================
USE cartera_inmobiliaria;

CREATE TABLE IF NOT EXISTS sale_cancellation_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    request_type ENUM('ANULACION','DESISTIMIENTO') NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status ENUM('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
    requested_by INT NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    resolution_notes VARCHAR(500) NULL,      -- tratamiento de abonos (devolución o penalidad) o motivo del rechazo
    -- Solo una solicitud PENDIENTE por negociación
    pending_sale_id INT GENERATED ALWAYS AS (CASE WHEN status = 'PENDIENTE' THEN sale_id END) STORED,
    UNIQUE KEY unq_pending_cancellation (pending_sale_id),
    INDEX idx_scr_status (status, requested_at),
    CONSTRAINT fk_scr_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_scr_requester FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_scr_resolver FOREIGN KEY (resolved_by) REFERENCES users(id),
    CONSTRAINT chk_scr_resolution CHECK (status = 'PENDIENTE' OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL))
) ENGINE=InnoDB;

-- RN-02 / CU-11 4b: al anular una reserva ACTIVA el lote vuelve a DISPONIBLE.
-- (El vencimiento lo resuelve el evento ev_expire_reservations.)
DROP TRIGGER IF EXISTS trg_res_after_update;
DELIMITER $$
CREATE TRIGGER trg_res_after_update AFTER UPDATE ON reservations FOR EACH ROW
BEGIN
    IF OLD.status_id = 1 AND NEW.status_id = 4 THEN
        UPDATE lots SET status_id = 1 WHERE id = NEW.lot_id AND status_id = 2;
    END IF;
END$$
DELIMITER ;
