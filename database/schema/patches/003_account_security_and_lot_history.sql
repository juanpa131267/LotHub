-- =============================================================================
-- PARCHE 003 · Ajustes de los sprints 1 a 3
--   · Cambio obligatorio de contraseña en el primer ingreso (US-AUTH-004)
--   · Activación de la cuenta del portal por enlace (US-AUTH-008, US-CLI-003)
--   · Historial de precios de los lotes (US-LOT-005)
-- Aplicar después del parche 002:
--   mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/003_account_security_and_lot_history.sql
-- =============================================================================
USE cartera_inmobiliaria;

-- La contraseña que asigna el administrador es temporal: el usuario debe cambiarla al ingresar.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE AFTER is_active,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER must_change_password;

-- La misma tabla de tokens sirve para recuperar la contraseña y para activar la cuenta del portal.
-- Ambos son de un solo uso y solo se guarda su SHA-256 (RN-25).
ALTER TABLE password_resets
    ADD COLUMN IF NOT EXISTS purpose ENUM('RECUPERACION','ACTIVACION') NOT NULL DEFAULT 'RECUPERACION' AFTER user_id;

-- Cada cambio del precio de lista queda registrado, sin importar desde dónde se hizo
-- (formulario, valor por m², planilla o carga masiva). La aplicación informa el usuario y el
-- origen con las variables de sesión @lothub_user_id y @lothub_price_source.
CREATE TABLE IF NOT EXISTS lot_price_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    lot_id INT NOT NULL,
    previous_price DECIMAL(14,2) NOT NULL,
    new_price DECIMAL(14,2) NOT NULL,
    source ENUM('FORMULARIO','POR_M2','PLANILLA','CARGA_MASIVA','SISTEMA') NOT NULL DEFAULT 'SISTEMA',
    changed_by INT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lph_lot (lot_id, changed_at),
    CONSTRAINT fk_lph_lot FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE,
    CONSTRAINT fk_lph_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

DROP TRIGGER IF EXISTS trg_lot_price_history;
DELIMITER $$
CREATE TRIGGER trg_lot_price_history AFTER UPDATE ON lots FOR EACH ROW
BEGIN
    IF NEW.list_price <> OLD.list_price THEN
        INSERT INTO lot_price_history (lot_id, previous_price, new_price, source, changed_by)
        VALUES (NEW.id, OLD.list_price, NEW.list_price, COALESCE(@lothub_price_source, 'SISTEMA'), @lothub_user_id);
    END IF;
END$$
DELIMITER ;
