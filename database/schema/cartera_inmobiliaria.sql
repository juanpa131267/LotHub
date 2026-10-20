-- =============================================================================
-- INICIALIZACIÓN DE LA BASE DE DATOS
-- =============================================================================
CREATE DATABASE IF NOT EXISTS cartera_inmobiliaria
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cartera_inmobiliaria;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. CATÁLOGOS MAESTROS (tablas de dominio)
-- =============================================================================
CREATE TABLE identification_types (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;

INSERT INTO identification_types (code, name) VALUES
('CC','Cédula de ciudadanía'), ('CE','Cédula de extranjería'),
('PAS','Pasaporte'), ('PPT','Permiso por protección temporal'), ('NIT','NIT');

-- Estados: un catálogo por entidad para que la FK garantice el dominio
CREATE TABLE project_statuses (
    id TINYINT UNSIGNED PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;
INSERT INTO project_statuses VALUES
(1,'EN_PLANEACION','En planeación'), (2,'EN_VENTA','En venta'),
(3,'VENDIDO','Vendido en su totalidad'), (4,'SUSPENDIDO','Suspendido'), (5,'CERRADO','Cerrado');

CREATE TABLE lot_statuses (
    id TINYINT UNSIGNED PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;
INSERT INTO lot_statuses VALUES
(1,'DISPONIBLE','Disponible'), (2,'RESERVADO','Reservado'),
(3,'VENDIDO','Vendido'), (4,'BLOQUEADO','Bloqueado');

CREATE TABLE reservation_statuses (
    id TINYINT UNSIGNED PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;
INSERT INTO reservation_statuses VALUES
(1,'ACTIVA','Activa'), (2,'CONVERTIDA','Convertida en negociación'),
(3,'VENCIDA','Vencida'), (4,'ANULADA','Anulada');

CREATE TABLE sale_statuses (
    id TINYINT UNSIGNED PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;
INSERT INTO sale_statuses VALUES
(1,'VIGENTE','Vigente'), (2,'PAGADA','Pagada en su totalidad'),
(3,'ANULADA','Anulada'), (4,'DESISTIDA','Desistida por el comprador');

CREATE TABLE obligation_statuses (
    id TINYINT UNSIGNED PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;
INSERT INTO obligation_statuses VALUES
(1,'ACTIVA','Activa'), (2,'CANCELADA','Cancelada (saldo cero)'),
(3,'REESTRUCTURADA','Reestructurada'), (4,'ANULADA','Anulada');

CREATE TABLE payment_statuses (
    id TINYINT UNSIGNED PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;
INSERT INTO payment_statuses VALUES
(1,'APLICADO','Aplicado'), (2,'REVERSADO','Reversado');

CREATE TABLE transaction_statuses (
    id TINYINT UNSIGNED PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;
INSERT INTO transaction_statuses VALUES
(1,'PENDIENTE','Pendiente'), (2,'APROBADA','Aprobada'), (3,'RECHAZADA','Rechazada'),
(4,'FALLIDA','Fallida'), (5,'EXPIRADA','Expirada');

CREATE TABLE financing_modalities (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    requires_agreement BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;
INSERT INTO financing_modalities (code, name, requires_agreement) VALUES
('CONTADO','Pago de contado', FALSE),
('FINANCIACION_DIRECTA','Financiación directa con la empresa', TRUE),
('MIXTA','Cuota inicial más crédito de un tercero', TRUE);

CREATE TABLE payment_frequencies (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL,
    months_interval TINYINT UNSIGNED NOT NULL,
    CONSTRAINT chk_freq_months CHECK (months_interval BETWEEN 1 AND 12)
) ENGINE=InnoDB;
INSERT INTO payment_frequencies (code, name, months_interval) VALUES
('MENSUAL','Mensual',1), ('BIMESTRAL','Bimestral',2), ('TRIMESTRAL','Trimestral',3),
('SEMESTRAL','Semestral',6), ('ANUAL','Anual',12);

CREATE TABLE payment_methods (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL,
    is_online BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB;
INSERT INTO payment_methods (code, name, is_online) VALUES
('EFECTIVO','Efectivo en oficina',FALSE), ('CONSIGNACION','Consignación bancaria',FALSE),
('TRANSFERENCIA','Transferencia bancaria',FALSE), ('PASARELA','Pago en línea por pasarela',TRUE);

CREATE TABLE document_types (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;
INSERT INTO document_types (code, name) VALUES
('DOC_IDENTIDAD','Documento de identidad'), ('PROMESA','Promesa de compraventa'),
('ACUERDO_PAGO','Acuerdo de pago firmado'), ('SOPORTE_PAGO','Soporte de pago'),
('AUTORIZACION_DATOS','Autorización de tratamiento de datos'), ('OTRO','Otro');

-- =============================================================================
-- 2. DATOS GEOGRÁFICOS
-- =============================================================================
CREATE TABLE states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    dane_code CHAR(2) UNIQUE
) ENGINE=InnoDB;

CREATE TABLE cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    dane_code CHAR(5) UNIQUE,
    UNIQUE KEY unq_city_state (state_id, name),
    CONSTRAINT fk_city_state FOREIGN KEY (state_id)
        REFERENCES states(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =============================================================================
-- 3. IDENTIDAD Y GESTIÓN DE ACCESO (IAM)
-- =============================================================================
CREATE TABLE people (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identification_type_id TINYINT UNSIGNED NOT NULL,
    identification_number VARCHAR(25) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(191) UNIQUE,
    phone VARCHAR(20),
    city_id INT,
    address VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY unq_person_identification (identification_type_id, identification_number),
    CONSTRAINT fk_person_idtype FOREIGN KEY (identification_type_id)
        REFERENCES identification_types(id),
    CONSTRAINT fk_person_city FOREIGN KEY (city_id) REFERENCES cities(id)
) ENGINE=InnoDB;

-- Autenticación (personal de la empresa y clientes con acceso al portal)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    username VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    hash_algorithm VARCHAR(20) NOT NULL DEFAULT 'bcrypt',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_user_person FOREIGN KEY (person_id)
        REFERENCES people(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT
) ENGINE=InnoDB;
INSERT INTO roles (name, description) VALUES
('ADMINISTRADOR','Seguridad, usuarios, roles, permisos y catálogos'),
('DIRECTOR_COMERCIAL','Proyectos, lotes, aprobación de negociaciones y cartera'),
('ASESOR_COMERCIAL','Clientes, reservas y negociaciones'),
('AUXILIAR_CARTERA','Acuerdos de pago, abonos, conciliación y estados de cuenta'),
('CLIENTE','Portal del comprador');

CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,      -- p. ej. 'payments.create'
    description VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE role_user (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ru_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ru_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE permission_role (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_pr_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,     -- SHA-256 del token enviado por correo
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pwr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================================
-- 4. CLIENTES Y PROTECCIÓN DE DATOS (Ley 1581 de 2012 y Ley 2300 de 2023)
-- =============================================================================
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    client_code VARCHAR(20) NOT NULL UNIQUE,
    occupation VARCHAR(100),
    registered_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_client_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_user FOREIGN KEY (registered_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE data_consents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    policy_version VARCHAR(20) NOT NULL,
    channel ENUM('WEB','PRESENCIAL','TELEFONICO') NOT NULL,
    granted_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    ip_address VARCHAR(45),
    UNIQUE KEY unq_consent_version (person_id, policy_version),
    CONSTRAINT fk_consent_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT,
    CONSTRAINT chk_consent_dates CHECK (revoked_at IS NULL OR revoked_at >= granted_at)
) ENGINE=InnoDB;

-- Canales autorizados por el titular para recibir comunicaciones de cobro
CREATE TABLE client_contact_channels (
    client_id INT NOT NULL,
    channel ENUM('EMAIL','SMS','WHATSAPP','LLAMADA','PORTAL') NOT NULL,
    contact_value VARCHAR(191) NOT NULL,
    authorized_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (client_id, channel),
    CONSTRAINT fk_ccc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE client_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    request_type ENUM('CONSULTA','RECLAMO','RECTIFICACION','SUPRESION') NOT NULL,
    subject VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('RADICADA','EN_TRAMITE','RESPONDIDA','CERRADA') NOT NULL DEFAULT 'RADICADA',
    filed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_date DATE NOT NULL,                  -- 10 días hábiles consulta / 15 reclamo
    response TEXT,
    answered_by INT NULL,
    answered_at DATETIME NULL,
    CONSTRAINT fk_creq_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_creq_user FOREIGN KEY (answered_by) REFERENCES users(id)
) ENGINE=InnoDB;
-- =============================================================================
-- 5. PROYECTOS INMOBILIARIOS Y LOTES
-- =============================================================================
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    city_id INT NOT NULL,
    address VARCHAR(255),
    description TEXT,
    total_area_m2 DECIMAL(12,2),
    launch_date DATE,
    status_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_proj_city FOREIGN KEY (city_id) REFERENCES cities(id),
    CONSTRAINT fk_proj_status FOREIGN KEY (status_id) REFERENCES project_statuses(id),
    CONSTRAINT fk_proj_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_proj_area CHECK (total_area_m2 IS NULL OR total_area_m2 > 0)
) ENGINE=InnoDB;
CREATE TABLE project_stages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    display_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY unq_stage_name (project_id, name),
    UNIQUE KEY unq_stage_project (id, project_id),   -- soporta la FK compuesta de lots
    CONSTRAINT fk_stage_project FOREIGN KEY (project_id)
        REFERENCES projects(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE lots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    stage_id INT NULL,
    block VARCHAR(10) NOT NULL,                      -- manzana
    lot_number VARCHAR(10) NOT NULL,
    area_m2 DECIMAL(10,2) NOT NULL,
    list_price DECIMAL(14,2) NOT NULL,
    status_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unq_lot_identity (project_id, block, lot_number),
    CONSTRAINT fk_lot_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_lot_stage FOREIGN KEY (stage_id, project_id)
        REFERENCES project_stages(id, project_id),
    CONSTRAINT fk_lot_status FOREIGN KEY (status_id) REFERENCES lot_statuses(id),
    CONSTRAINT chk_lot_area CHECK (area_m2 > 0),
    CONSTRAINT chk_lot_price CHECK (list_price > 0)
) ENGINE=InnoDB;
-- =============================================================================
-- 6. COMERCIALIZACIÓN: RESERVAS Y NEGOCIACIONES
-- =============================================================================
CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lot_id INT NOT NULL,
    client_id INT NOT NULL,
    advisor_id INT NOT NULL,
    reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    notes VARCHAR(255),
    -- Solo una reserva ACTIVA por lote (los NULL no colisionan en UNIQUE)
    active_lot_id INT GENERATED ALWAYS AS (CASE WHEN status_id = 1 THEN lot_id END) STORED,
    UNIQUE KEY unq_active_reservation (active_lot_id),
    CONSTRAINT fk_res_lot FOREIGN KEY (lot_id) REFERENCES lots(id),
    CONSTRAINT fk_res_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_res_advisor FOREIGN KEY (advisor_id) REFERENCES users(id),
    CONSTRAINT fk_res_status FOREIGN KEY (status_id) REFERENCES reservation_statuses(id),
    CONSTRAINT chk_res_dates CHECK (expires_at > reserved_at),
    CONSTRAINT chk_res_deposit CHECK (deposit_amount >= 0)
) ENGINE=InnoDB;
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_code VARCHAR(20) NOT NULL UNIQUE,
    lot_id INT NOT NULL,
    reservation_id INT NULL UNIQUE,
    advisor_id INT NOT NULL,
    sale_date DATE NOT NULL,
    agreed_price DECIMAL(14,2) NOT NULL,             -- precio pactado (puede diferir del de lista)
    financing_modality_id TINYINT UNSIGNED NOT NULL,
    status_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    cancellation_reason VARCHAR(255),
    approved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    -- Un lote solo puede tener una negociación VIGENTE o PAGADA
    active_lot_id INT GENERATED ALWAYS AS (CASE WHEN status_id IN (1,2) THEN lot_id END) STORED,
    UNIQUE KEY unq_active_sale (active_lot_id),
    CONSTRAINT fk_sale_lot FOREIGN KEY (lot_id) REFERENCES lots(id),
    CONSTRAINT fk_sale_res FOREIGN KEY (reservation_id) REFERENCES reservations(id),
    CONSTRAINT fk_sale_advisor FOREIGN KEY (advisor_id) REFERENCES users(id),
    CONSTRAINT fk_sale_modality FOREIGN KEY (financing_modality_id) REFERENCES financing_modalities(id),
    CONSTRAINT fk_sale_status FOREIGN KEY (status_id) REFERENCES sale_statuses(id),
    CONSTRAINT fk_sale_approver FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT chk_sale_price CHECK (agreed_price > 0),
    CONSTRAINT chk_sale_cancel CHECK (status_id NOT IN (3,4) OR cancellation_reason IS NOT NULL)
) ENGINE=InnoDB;
-- Compradores de la negociación (uno o varios titulares)
CREATE TABLE sale_clients (
    sale_id INT NOT NULL,
    client_id INT NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    ownership_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    primary_sale_id INT GENERATED ALWAYS AS (CASE WHEN is_primary THEN sale_id END) STORED,
    PRIMARY KEY (sale_id, client_id),
    UNIQUE KEY unq_one_primary (primary_sale_id),   -- un único titular principal
    CONSTRAINT fk_sc_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sc_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT chk_sc_pct CHECK (ownership_pct > 0 AND ownership_pct <= 100)
) ENGINE=InnoDB;
-- =============================================================================
-- 7. FINANCIACIÓN: ACUERDOS DE PAGO, OBLIGACIONES Y CUOTAS
-- =============================================================================
CREATE TABLE payment_agreements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,     -- >1 cuando hay reestructuración
    down_payment DECIMAL(14,2) NOT NULL DEFAULT 0,    -- cuota inicial
    financed_amount DECIMAL(14,2) NOT NULL,           -- capital financiado en esta versión
    installments_count SMALLINT UNSIGNED NOT NULL,
    frequency_id TINYINT UNSIGNED NOT NULL,
    monthly_interest_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
    late_interest_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
    first_due_date DATE NOT NULL,
    signed_at DATE,
    restructure_reason VARCHAR(255),
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unq_agreement_version (sale_id, version),
    CONSTRAINT fk_pa_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_pa_freq FOREIGN KEY (frequency_id) REFERENCES payment_frequencies(id),
    CONSTRAINT fk_pa_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_pa_amounts CHECK (down_payment >= 0 AND financed_amount >= 0),
    CONSTRAINT chk_pa_count CHECK (installments_count BETWEEN 1 AND 360),
    CONSTRAINT chk_pa_rates CHECK (monthly_interest_rate >= 0 AND late_interest_rate >= 0),
    CONSTRAINT chk_pa_restructure CHECK (version = 1 OR restructure_reason IS NOT NULL)
) ENGINE=InnoDB;
CREATE TABLE obligations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    obligation_code VARCHAR(20) NOT NULL UNIQUE,
    agreement_id INT NOT NULL UNIQUE,                 -- 1:1 con el acuerdo de pago
    status_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    opened_at DATE NOT NULL,
    closed_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_obl_agreement FOREIGN KEY (agreement_id) REFERENCES payment_agreements(id),
    CONSTRAINT fk_obl_status FOREIGN KEY (status_id) REFERENCES obligation_statuses(id),
    CONSTRAINT chk_obl_dates CHECK (closed_at IS NULL OR closed_at >= opened_at)
) ENGINE=InnoDB;
CREATE TABLE installments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    obligation_id INT NOT NULL,
    installment_number SMALLINT UNSIGNED NOT NULL,    -- 0 = cuota inicial
    due_date DATE NOT NULL,
    principal_amount DECIMAL(14,2) NOT NULL,
    interest_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    UNIQUE KEY unq_installment (obligation_id, installment_number),
    INDEX idx_inst_due (due_date),
    CONSTRAINT fk_inst_obl FOREIGN KEY (obligation_id)
        REFERENCES obligations(id) ON DELETE RESTRICT,
    CONSTRAINT chk_inst_amounts CHECK (principal_amount >= 0 AND interest_amount >= 0)
) ENGINE=InnoDB;
CREATE TABLE late_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    installment_id INT NOT NULL,
    calculated_on DATE NOT NULL,
    days_overdue SMALLINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    UNIQUE KEY unq_late_charge (installment_id, calculated_on),
    CONSTRAINT fk_lc_inst FOREIGN KEY (installment_id) REFERENCES installments(id),
    CONSTRAINT chk_lc_amount CHECK (amount >= 0)
) ENGINE=InnoDB;
-- =============================================================================
-- 8. PAGOS, PASARELA Y APLICACIÓN DE ABONOS
-- =============================================================================
CREATE TABLE payment_gateways (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    environment ENUM('SANDBOX','PRODUCCION') NOT NULL DEFAULT 'SANDBOX',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY unq_gateway (name, environment)
) ENGINE=InnoDB;
CREATE TABLE gateway_transactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    gateway_id TINYINT UNSIGNED NOT NULL,
    obligation_id INT NOT NULL,
    client_id INT NOT NULL,
    internal_reference VARCHAR(40) NOT NULL UNIQUE,   -- referencia enviada a la pasarela
    external_reference VARCHAR(80) NULL UNIQUE,       -- id devuelto por la pasarela
    amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'COP',
    status_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    CONSTRAINT fk_gt_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id),
    CONSTRAINT fk_gt_obl FOREIGN KEY (obligation_id) REFERENCES obligations(id),
    CONSTRAINT fk_gt_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_gt_status FOREIGN KEY (status_id) REFERENCES transaction_statuses(id),
    CONSTRAINT chk_gt_amount CHECK (amount > 0)
) ENGINE=InnoDB;
-- Notificaciones (webhooks) recibidas; nunca contienen datos de tarjeta
CREATE TABLE gateway_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT NULL,
    event_type VARCHAR(60) NOT NULL,
    payload JSON NOT NULL,
    signature_valid BOOLEAN NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    CONSTRAINT fk_gn_tx FOREIGN KEY (transaction_id) REFERENCES gateway_transactions(id)
) ENGINE=InnoDB;
CREATE TABLE payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    obligation_id INT NOT NULL,
    payer_client_id INT NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_method_id TINYINT UNSIGNED NOT NULL,
    bank_reference VARCHAR(80),
    gateway_transaction_id BIGINT NULL UNIQUE,        -- idempotencia: 1 transacción = 1 abono
    status_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    registered_by INT NULL,                           -- NULL = registro automático
    reversal_reason VARCHAR(255),
    reversed_by INT NULL,
    reversed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pay_date (payment_date),
    CONSTRAINT fk_pay_obl FOREIGN KEY (obligation_id) REFERENCES obligations(id),
    CONSTRAINT fk_pay_client FOREIGN KEY (payer_client_id) REFERENCES clients(id),
    CONSTRAINT fk_pay_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    CONSTRAINT fk_pay_tx FOREIGN KEY (gateway_transaction_id) REFERENCES gateway_transactions(id),
    CONSTRAINT fk_pay_status FOREIGN KEY (status_id) REFERENCES payment_statuses(id),
    CONSTRAINT fk_pay_reg FOREIGN KEY (registered_by) REFERENCES users(id),
    CONSTRAINT fk_pay_rev FOREIGN KEY (reversed_by) REFERENCES users(id),
    CONSTRAINT chk_pay_amount CHECK (amount > 0),
    CONSTRAINT chk_pay_origin CHECK (registered_by IS NOT NULL OR gateway_transaction_id IS NOT NULL),
    CONSTRAINT chk_pay_reversal CHECK (status_id <> 2 OR (reversal_reason IS NOT NULL
        AND reversed_by IS NOT NULL AND reversed_at IS NOT NULL))
) ENGINE=InnoDB;
-- Aplicación del abono a cada cuota y concepto (N:M abono–cuota)
CREATE TABLE payment_allocations (
    payment_id BIGINT NOT NULL,
    installment_id INT NOT NULL,
    concept ENUM('INTERES_MORA','INTERES_CORRIENTE','CAPITAL') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (payment_id, installment_id, concept),
    CONSTRAINT fk_alloc_pay FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_alloc_inst FOREIGN KEY (installment_id) REFERENCES installments(id),
    CONSTRAINT chk_alloc_amount CHECK (amount > 0)
) ENGINE=InnoDB;
-- =============================================================================
-- 9. DOCUMENTOS, ESTADOS DE CUENTA, NOTIFICACIONES Y SOLICITUDES
-- =============================================================================
CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type_id TINYINT UNSIGNED NOT NULL,
    client_id INT NULL,
    sale_id INT NULL,
    payment_id BIGINT NULL,
    title VARCHAR(255),
    storage_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_hash CHAR(64) NOT NULL,                      -- SHA-256 (integridad, Ley 527)
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_type FOREIGN KEY (document_type_id) REFERENCES document_types(id),
    CONSTRAINT fk_doc_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_doc_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_doc_pay FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
    -- cada documento pertenece exactamente a un propietario
    CONSTRAINT chk_doc_owner CHECK ((client_id IS NOT NULL) + (sale_id IS NOT NULL)
        + (payment_id IS NOT NULL) = 1)
) ENGINE=InnoDB;
CREATE TABLE account_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    obligation_id INT NOT NULL,
    cut_off_date DATE NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    generated_by INT NULL,                            -- NULL = generado por el cliente o el sistema
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_as_obl FOREIGN KEY (obligation_id) REFERENCES obligations(id),
    CONSTRAINT fk_as_user FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    installment_id INT NULL,
    channel ENUM('EMAIL','SMS','WHATSAPP','LLAMADA','PORTAL') NOT NULL,
    notification_type ENUM('RECORDATORIO_VENCIMIENTO','AVISO_MORA',
        'CONFIRMACION_PAGO','GENERAL') NOT NULL,
    scheduled_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    status ENUM('PROGRAMADA','ENVIADA','FALLIDA','CANCELADA') NOT NULL DEFAULT 'PROGRAMADA',
    error_message VARCHAR(255),
    INDEX idx_notif_client_date (client_id, scheduled_at),
    -- solo por canales autorizados por el titular (Ley 2300 de 2023)
    CONSTRAINT fk_notif_channel FOREIGN KEY (client_id, channel)
        REFERENCES client_contact_channels(client_id, channel),
    CONSTRAINT fk_notif_inst FOREIGN KEY (installment_id) REFERENCES installments(id)
) ENGINE=InnoDB;
CREATE TABLE information_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(191) NOT NULL,
    phone VARCHAR(20),
    message TEXT,
    consent_accepted BOOLEAN NOT NULL,
    status ENUM('NUEVA','ATENDIDA','DESCARTADA') NOT NULL DEFAULT 'NUEVA',
    attended_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ir_project FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_ir_user FOREIGN KEY (attended_by) REFERENCES users(id),
    CONSTRAINT chk_ir_consent CHECK (consent_accepted = TRUE)
) ENGINE=InnoDB;
-- =============================================================================
-- 10. AUDITORÍA
-- =============================================================================
CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,                                 -- NULL = acción del sistema
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(100) NOT NULL,
    record_id BIGINT NOT NULL,
    previous_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_record (table_name, record_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
-- =============================================================================
-- 11. VISTAS DE DATOS DERIVADOS (no se almacenan para evitar redundancia)
-- =============================================================================
-- Acuerdo de pago vigente = última versión de cada negociación
CREATE VIEW v_current_agreement AS
SELECT pa.*
FROM payment_agreements pa
JOIN (SELECT sale_id, MAX(version) AS max_version
      FROM payment_agreements GROUP BY sale_id) m
  ON m.sale_id = pa.sale_id AND m.max_version = pa.version;
-- Saldo, días de mora y estado calculado de cada cuota
CREATE VIEW v_installment_balance AS
SELECT x.*,
       x.amount_due - x.amount_paid AS balance,
       CASE WHEN x.amount_due - x.amount_paid <= 0 THEN 0
            ELSE GREATEST(DATEDIFF(CURDATE(), x.due_date), 0) END AS days_overdue,
       CASE WHEN x.amount_due - x.amount_paid <= 0 THEN 'PAGADA'
            WHEN x.due_date < CURDATE() THEN 'VENCIDA'
            WHEN x.amount_paid > 0 THEN 'PAGO_PARCIAL'
            ELSE 'PENDIENTE' END AS computed_status
FROM (
    SELECT i.id AS installment_id, i.obligation_id, i.installment_number, i.due_date,
           i.principal_amount + i.interest_amount + COALESCE(lc.total, 0) AS amount_due,
           COALESCE(ap.total, 0) AS amount_paid
    FROM installments i
    LEFT JOIN (SELECT installment_id, SUM(amount) AS total
               FROM late_charges GROUP BY installment_id) lc
           ON lc.installment_id = i.id
    LEFT JOIN (SELECT a.installment_id, SUM(a.amount) AS total
               FROM payment_allocations a
               JOIN payments p ON p.id = a.payment_id AND p.status_id = 1
               GROUP BY a.installment_id) ap
           ON ap.installment_id = i.id
) x;
-- Consolidado por obligación (base del estado de cuenta)
CREATE VIEW v_obligation_balance AS
SELECT o.id AS obligation_id, o.obligation_code, s.id AS sale_id, s.sale_code,
       sc.client_id AS primary_client_id,
       SUM(b.amount_due) AS total_due,
       SUM(b.amount_paid) AS total_paid,
       SUM(b.balance) AS balance,
       SUM(b.days_overdue > 0) AS overdue_installments,
       MAX(b.days_overdue) AS max_days_overdue,
       MIN(CASE WHEN b.balance > 0 THEN b.due_date END) AS next_due_date
FROM obligations o
JOIN payment_agreements pa ON pa.id = o.agreement_id
JOIN sales s ON s.id = pa.sale_id
JOIN sale_clients sc ON sc.sale_id = s.id AND sc.is_primary = TRUE
JOIN v_installment_balance b ON b.obligation_id = o.id
WHERE o.status_id = 1
GROUP BY o.id, o.obligation_code, s.id, s.sale_code, sc.client_id;
-- Cartera por edades de mora
CREATE VIEW v_portfolio_aging AS
SELECT CASE WHEN max_days_overdue = 0 THEN 'AL_DIA'
            WHEN max_days_overdue <= 30 THEN '1_A_30'
            WHEN max_days_overdue <= 60 THEN '31_A_60'
            WHEN max_days_overdue <= 90 THEN '61_A_90'
            ELSE 'MAS_DE_90' END AS aging_bucket,
       COUNT(*) AS obligations, SUM(balance) AS balance
FROM v_obligation_balance
GROUP BY aging_bucket;
-- Disponibilidad pública de lotes (proyectos en venta)
CREATE VIEW v_lot_availability AS
SELECT p.id AS project_id, p.name AS project_name, c.name AS city,
       st.name AS stage_name, l.id AS lot_id, l.block, l.lot_number,
       l.area_m2, l.list_price, ls.code AS lot_status
FROM lots l
JOIN projects p ON p.id = l.project_id
JOIN cities c ON c.id = p.city_id
JOIN lot_statuses ls ON ls.id = l.status_id
LEFT JOIN project_stages st ON st.id = l.stage_id
WHERE p.status_id = 2 AND p.deleted_at IS NULL;
-- =============================================================================
-- 12. DISPARADORES Y EVENTOS (reglas de negocio en el motor)
-- =============================================================================
DELIMITER $$
-- RN-04: solo se reserva un lote DISPONIBLE; al reservar pasa a RESERVADO
CREATE TRIGGER trg_res_before_insert BEFORE INSERT ON reservations FOR EACH ROW
BEGIN
    IF (SELECT status_id FROM lots WHERE id = NEW.lot_id) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El lote no está disponible para reserva';
    END IF;
END$$
CREATE TRIGGER trg_res_after_insert AFTER INSERT ON reservations FOR EACH ROW
BEGIN
    UPDATE lots SET status_id = 2 WHERE id = NEW.lot_id;
END$$
-- RN-05: solo se negocia un lote DISPONIBLE o RESERVADO por la misma reserva
CREATE TRIGGER trg_sale_before_insert BEFORE INSERT ON sales FOR EACH ROW
BEGIN
    DECLARE v_status TINYINT;
    SELECT status_id INTO v_status FROM lots WHERE id = NEW.lot_id;
    IF v_status IN (3, 4) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El lote está vendido o bloqueado';
    END IF;
    IF v_status = 2 AND NOT EXISTS (SELECT 1 FROM reservations r
        WHERE r.id = NEW.reservation_id AND r.lot_id = NEW.lot_id AND r.status_id = 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El lote está reservado por otra negociación';
    END IF;
END$$
CREATE TRIGGER trg_sale_after_insert AFTER INSERT ON sales FOR EACH ROW
BEGIN
    UPDATE lots SET status_id = 3 WHERE id = NEW.lot_id;
    IF NEW.reservation_id IS NOT NULL THEN
        UPDATE reservations SET status_id = 2 WHERE id = NEW.reservation_id;
    END IF;
END$$
-- RN-08: al anular o desistir, el lote vuelve a DISPONIBLE
CREATE TRIGGER trg_sale_after_update AFTER UPDATE ON sales FOR EACH ROW
BEGIN
    IF NEW.status_id IN (3, 4) AND OLD.status_id NOT IN (3, 4) THEN
        UPDATE lots SET status_id = 1 WHERE id = NEW.lot_id;
    END IF;
END$$
-- RN-13 y RN-14: la aplicación no excede el abono y la cuota es de la misma obligación
CREATE TRIGGER trg_alloc_before_insert BEFORE INSERT ON payment_allocations FOR EACH ROW
BEGIN
    DECLARE v_pay_obl INT; DECLARE v_inst_obl INT; DECLARE v_status TINYINT;
    DECLARE v_amount DECIMAL(14,2); DECLARE v_applied DECIMAL(14,2);
    SELECT obligation_id, amount, status_id INTO v_pay_obl, v_amount, v_status
      FROM payments WHERE id = NEW.payment_id;
    SELECT obligation_id INTO v_inst_obl FROM installments WHERE id = NEW.installment_id;
    IF v_status <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se aplica un abono reversado';
    END IF;
    IF v_pay_obl <> v_inst_obl THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La cuota no pertenece a la obligación del abono';
    END IF;
    SELECT COALESCE(SUM(amount), 0) INTO v_applied
      FROM payment_allocations WHERE payment_id = NEW.payment_id;
    IF v_applied + NEW.amount > v_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La aplicación excede el valor del abono';
    END IF;
END$$
-- RN-15: los abonos no se eliminan ni se alteran; solo se reversan
CREATE TRIGGER trg_pay_before_delete BEFORE DELETE ON payments FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Los abonos no se eliminan; deben reversarse';
END$$
CREATE TRIGGER trg_pay_before_update BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
    IF NEW.amount <> OLD.amount OR NEW.obligation_id <> OLD.obligation_id
       OR NEW.payment_date <> OLD.payment_date OR OLD.status_id = 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se permite reversar el abono';
    END IF;
END$$
-- RN-03: liberación automática de reservas vencidas (Planificador del sistema)
CREATE EVENT ev_expire_reservations ON SCHEDULE EVERY 1 HOUR DO
BEGIN
    UPDATE lots l JOIN reservations r ON r.lot_id = l.id
       SET l.status_id = 1
     WHERE r.status_id = 1 AND r.expires_at < NOW();
    UPDATE reservations SET status_id = 3
     WHERE status_id = 1 AND expires_at < NOW();
END$$
DELIMITER ;
SET FOREIGN_KEY_CHECKS = 1;
