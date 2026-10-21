-- =============================================================================
-- PARCHE 008 · Operación en paralelo (Sprint 10)
--   · Medio de pago para el valor pagado antes de entrar al sistema (saldo migrado).
--   · Registro de cada migración de cartera desde las hojas de cálculo (trazabilidad fila a fila).
--   · Cortes de la operación en paralelo: saldo según la hoja vs. saldo según el sistema.
-- Aplicar después del parche 007:
--   mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/008_legacy_migration.sql
-- =============================================================================
USE cartera_inmobiliaria;

INSERT IGNORE INTO payment_methods (code, name, is_online) VALUES
('SALDO_MIGRADO', 'Pagado antes del sistema (migración)', FALSE);

CREATE TABLE IF NOT EXISTS legacy_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL UNIQUE,
    rows_count SMALLINT UNSIGNED NOT NULL,
    late_charges_from_migration BOOLEAN NOT NULL DEFAULT TRUE,   -- la mora se liquida desde la migración, no desde el vencimiento
    imported_by INT NOT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_li_user FOREIGN KEY (imported_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS legacy_import_rows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT NOT NULL,
    sheet_row SMALLINT UNSIGNED NOT NULL,                        -- fila de la hoja (row_number es palabra reservada en MySQL 8)
    legacy_reference VARCHAR(40) NOT NULL UNIQUE,                -- identificador en la hoja anterior
    client_id INT NOT NULL,
    sale_id INT NOT NULL UNIQUE,
    obligation_id INT NOT NULL UNIQUE,
    paid_before DECIMAL(14,2) NOT NULL DEFAULT 0,
    legacy_balance DECIMAL(14,2) NULL,                           -- saldo que traía la hoja al migrar
    CONSTRAINT fk_lir_import FOREIGN KEY (import_id) REFERENCES legacy_imports(id),
    CONSTRAINT fk_lir_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_lir_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_lir_obligation FOREIGN KEY (obligation_id) REFERENCES obligations(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS parallel_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cut_off_date DATE NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    compared SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    matched SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    differences SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tolerance DECIMAL(14,2) NOT NULL DEFAULT 1000,
    result JSON NOT NULL,                                        -- detalle por obligación del corte
    run_by INT NOT NULL,
    run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_cutoff (cut_off_date),
    CONSTRAINT fk_pr_user FOREIGN KEY (run_by) REFERENCES users(id)
) ENGINE=InnoDB;
