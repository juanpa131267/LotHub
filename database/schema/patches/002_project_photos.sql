-- =============================================================================
-- PARCHE 002 · Fotos de proyecto (galería de la ficha de venta)
-- Aplicar después del parche 001:
--   mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/002_project_photos.sql
-- =============================================================================
USE cartera_inmobiliaria;

-- Los archivos viven en storage/app/photos/{project_id}/ (versión optimizada + miniatura);
-- aquí se guarda su ubicación, la anotación opcional, el orden y cuál es la portada.
CREATE TABLE IF NOT EXISTS project_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    path VARCHAR(255) NOT NULL,                       -- imagen optimizada (máx. 2000 px)
    thumb_path VARCHAR(255) NOT NULL,                 -- miniatura (máx. 560 px)
    original_name VARCHAR(255) NULL,
    caption VARCHAR(255) NULL,                        -- anotación: "Piscina", "Logo del conjunto"…
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_cover BOOLEAN NOT NULL DEFAULT FALSE,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    -- Una sola portada por proyecto (los NULL no colisionan en UNIQUE)
    cover_project_id INT GENERATED ALWAYS AS (CASE WHEN is_cover THEN project_id END) STORED,
    UNIQUE KEY unq_project_cover (cover_project_id),
    INDEX idx_photo_project (project_id, sort_order),
    CONSTRAINT fk_photo_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    CONSTRAINT fk_photo_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;
