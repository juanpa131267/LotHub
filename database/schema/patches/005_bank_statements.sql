-- =============================================================================
-- PARCHE 005 · Motor de asignación automática de pagos (Sprint 6)
-- El Auxiliar de cartera carga el extracto bancario; cada movimiento crédito recibe un
-- puntaje contra las obligaciones activas. Si el mejor candidato es claro se crea el abono
-- automáticamente; si no, el movimiento queda en la cola de revisión.
-- Aplicar después del parche 004:
--   mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/005_bank_statements.sql
-- =============================================================================
USE cartera_inmobiliaria;

CREATE TABLE IF NOT EXISTS bank_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(80) NOT NULL,
    account_label VARCHAR(80) NULL,                   -- p. ej. «Ahorros ****1234» (nunca el número completo)
    original_name VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL UNIQUE,               -- SHA-256: el mismo archivo no se procesa dos veces
    storage_path VARCHAR(255) NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    lines_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    credits_total DECIMAL(16,2) NOT NULL DEFAULT 0,
    payment_method_id TINYINT UNSIGNED NOT NULL,      -- medio con el que se registran los abonos (consignación / transferencia)
    uploaded_by INT NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bs_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    CONSTRAINT fk_bs_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bank_statement_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    statement_id INT NOT NULL,
    line_number SMALLINT UNSIGNED NOT NULL,           -- fila del archivo
    transaction_date DATE NOT NULL,
    description VARCHAR(255) NULL,
    reference VARCHAR(80) NULL,
    payer_document VARCHAR(25) NULL,                  -- identificación del depositante, si el banco la informa
    amount DECIMAL(14,2) NOT NULL,
    -- PENDIENTE: sin procesar · ASIGNADA: generó un abono · REVISION: requiere decisión humana
    -- DESCARTADA: no corresponde a cartera · DUPLICADA: el movimiento ya se había registrado
    status ENUM('PENDIENTE','ASIGNADA','REVISION','DESCARTADA','DUPLICADA') NOT NULL DEFAULT 'PENDIENTE',
    assignment_mode ENUM('AUTOMATICA','MANUAL') NULL,
    matched_obligation_id INT NULL,
    score TINYINT UNSIGNED NULL,                      -- puntaje del mejor candidato (0 a 100)
    candidates JSON NULL,                             -- candidatos evaluados con el detalle del puntaje
    payment_id BIGINT NULL UNIQUE,                    -- un movimiento genera como máximo un abono
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(255) NULL,
    UNIQUE KEY unq_statement_line (statement_id, line_number),
    INDEX idx_bsl_status (status),
    INDEX idx_bsl_match (transaction_date, amount),
    CONSTRAINT fk_bsl_statement FOREIGN KEY (statement_id) REFERENCES bank_statements(id) ON DELETE CASCADE,
    CONSTRAINT fk_bsl_obligation FOREIGN KEY (matched_obligation_id) REFERENCES obligations(id),
    CONSTRAINT fk_bsl_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_bsl_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
    CONSTRAINT chk_bsl_amount CHECK (amount > 0),
    CONSTRAINT chk_bsl_assigned CHECK (status <> 'ASIGNADA' OR (payment_id IS NOT NULL AND matched_obligation_id IS NOT NULL))
) ENGINE=InnoDB;
