-- =============================================================================
-- PARCHE 001 · Planos de proyecto (módulo de plano interactivo)
-- Aplicar después de cartera_inmobiliaria.sql:
--   mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/001_project_plans.sql
-- =============================================================================
USE cartera_inmobiliaria;

-- Cada carga de plano (DWG/DXF) queda como una versión. El archivo original y
-- el JSON procesado viven en storage/app/plans/{project_id}/; aquí se guarda
-- su ubicación, el resultado del procesamiento y cuál versión está publicada.
CREATE TABLE IF NOT EXISTS project_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    source_path VARCHAR(255) NOT NULL,
    source_hash CHAR(64) NOT NULL,                    -- SHA-256 del archivo cargado
    data_path VARCHAR(255) NULL,                      -- JSON del plano procesado
    status ENUM('PROCESANDO','LISTO','ERROR') NOT NULL DEFAULT 'PROCESANDO',
    error_message VARCHAR(500) NULL,
    lots_detected SMALLINT UNSIGNED NULL,
    report JSON NULL,                                 -- advertencias y validaciones
    is_published BOOLEAN NOT NULL DEFAULT FALSE,
    uploaded_by INT NOT NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    -- Solo un plano publicado por proyecto (los NULL no colisionan en UNIQUE)
    published_project_id INT GENERATED ALWAYS AS (CASE WHEN is_published THEN project_id END) STORED,
    UNIQUE KEY unq_published_plan (published_project_id),
    INDEX idx_plan_project (project_id),
    CONSTRAINT fk_plan_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    CONSTRAINT fk_plan_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT chk_plan_publish CHECK (is_published = FALSE OR status = 'LISTO')
) ENGINE=InnoDB;
