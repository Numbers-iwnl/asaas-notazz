-- ============================================================
-- ASAAS → NOTAZZ : schema completo
-- MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- ------------------------------------------------------------
-- Usuários do painel administrativo
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(160) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Catálogo de produtos (alimentado a partir da planilha)
-- keywords = lista separada por ; usada para match no description
-- fiscal_group define o grupo de NFS-e (pos_grad / extensao / mentoria / outro)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(200) NOT NULL,
    asaas_description   VARCHAR(255) NOT NULL,
    keywords            TEXT NOT NULL,
    notazz_product_id   VARCHAR(40) NULL,
    notazz_service_id   VARCHAR(40) NULL,
    fiscal_group        ENUM('pos_grad','extensao','mentoria','outro') NOT NULL DEFAULT 'outro',
    active              TINYINT(1) NOT NULL DEFAULT 1,
    ignored             TINYINT(1) NOT NULL DEFAULT 0,
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_products_active (active, ignored)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Log bruto de TODA chamada recebida no endpoint do webhook.
-- Mantém integridade para auditoria mesmo que o payload seja inválido.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    received_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    remote_ip       VARCHAR(45) NULL,
    method          VARCHAR(10) NULL,
    headers         MEDIUMTEXT NULL,
    raw_body        MEDIUMTEXT NULL,
    token_valid     TINYINT(1) NOT NULL DEFAULT 0,
    json_valid      TINYINT(1) NOT NULL DEFAULT 0,
    event           VARCHAR(60) NULL,
    asaas_payment_id VARCHAR(60) NULL,
    response_code   INT NULL,
    response_body   TEXT NULL,
    KEY idx_webhook_logs_payment (asaas_payment_id),
    KEY idx_webhook_logs_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pagamentos extraídos do payload do Asaas (estruturado, 1 linha por payment)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asaas_payments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asaas_payment_id    VARCHAR(60) NOT NULL,
    asaas_customer_id   VARCHAR(60) NULL,
    asaas_subscription_id VARCHAR(60) NULL,
    asaas_installment_id  VARCHAR(60) NULL,
    event               VARCHAR(60) NOT NULL,
    status              VARCHAR(40) NULL,
    billing_type        VARCHAR(40) NULL,
    description         VARCHAR(500) NULL,
    value               DECIMAL(12,2) NULL,
    net_value           DECIMAL(12,2) NULL,
    due_date            DATE NULL,
    payment_date        DATE NULL,
    installment_number  INT NULL,
    installment_count   INT NULL,
    customer_name       VARCHAR(200) NULL,
    customer_cpfcnpj    VARCHAR(20) NULL,
    customer_person_type ENUM('F','J') NULL,
    customer_email      VARCHAR(160) NULL,
    customer_phone      VARCHAR(40) NULL,
    customer_address    VARCHAR(200) NULL,
    customer_address_number VARCHAR(20) NULL,
    customer_complement VARCHAR(120) NULL,
    customer_province   VARCHAR(120) NULL,
    customer_postal_code VARCHAR(20) NULL,
    customer_city       VARCHAR(120) NULL,
    customer_state      VARCHAR(2) NULL,
    raw_payload         MEDIUMTEXT NOT NULL,
    matched_product_id  INT UNSIGNED NULL,
    received_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_event (asaas_payment_id, event),
    KEY idx_payments_status (status),
    KEY idx_payments_received (received_at),
    CONSTRAINT fk_payments_product FOREIGN KEY (matched_product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Documentos a emitir/emitidos na Notazz.
-- 1 NF-e + 1 NFS-e por pagamento (quando o produto tem ambos os IDs).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notazz_documents (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asaas_payment_id    VARCHAR(60) NOT NULL,
    payment_row_id      BIGINT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED NULL,
    document_type       ENUM('nfe','nfse') NOT NULL,
    status              ENUM('pending','processing','sent','error','ignored','manual') NOT NULL DEFAULT 'pending',
    notazz_external_id  VARCHAR(80) NULL,
    notazz_document_id  VARCHAR(80) NULL,
    notazz_number       VARCHAR(60) NULL,
    notazz_key          VARCHAR(80) NULL,
    pdf_url             VARCHAR(500) NULL,
    xml_url             VARCHAR(500) NULL,
    payload_sent        MEDIUMTEXT NULL,
    payload_response    MEDIUMTEXT NULL,
    last_error          TEXT NULL,
    attempts            INT NOT NULL DEFAULT 0,
    locked_at           TIMESTAMP NULL,
    next_retry_at       TIMESTAMP NULL,
    sent_at             TIMESTAMP NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_payment_type (asaas_payment_id, document_type),
    KEY idx_doc_status (status),
    KEY idx_doc_created (created_at),
    CONSTRAINT fk_doc_payment FOREIGN KEY (payment_row_id) REFERENCES asaas_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Log granular de cada operação relevante (webhook, fila, emissão).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS processing_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    level           ENUM('info','warning','error','debug') NOT NULL DEFAULT 'info',
    source          VARCHAR(40) NOT NULL,
    document_id     BIGINT UNSIGNED NULL,
    asaas_payment_id VARCHAR(60) NULL,
    message         TEXT NOT NULL,
    context         MEDIUMTEXT NULL,
    KEY idx_logs_created (created_at),
    KEY idx_logs_level (level),
    KEY idx_logs_payment (asaas_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED: produtos da planilha
-- Os 3 produtos sem id de serviço ficam com notazz_service_id = NULL
-- (gera só NF-e). Planner, Apostila e Livro ficam ignored = 1.
-- ============================================================
INSERT INTO products (name, asaas_description, keywords, notazz_product_id, notazz_service_id, fiscal_group, active, ignored, notes) VALUES
('Gestão de Clínicas na Prática', 'Gestão de Clínicas na Prática', 'gestao de clinicas;gestão de clínicas',           '100001',      '100001',      'pos_grad', 1, 0, NULL),
('Mentoria Premium',                     'Mentoria Premium',                     'mentoria premium',              '100002',    '200002',    'mentoria', 1, 0, NULL),
('Mentoria Essencial',                              'Mentoria Essencial',                              'mentoria essencial',                                      '100003', '200003', 'mentoria', 1, 0, NULL),
('Pós-graduação em Fisioterapia Avançada',   'Pós-graduação em Fisioterapia Avançada', 'pos-graduacao em fisioterapia avancada;pós-graduação em fisioterapia avançada', '200005', '200004', 'pos_grad', 1, 0, NULL),
('Renovação da pós-graduação em Fisioterapia Avançada',            'Renovação da pós-graduação em Fisioterapia Avançada', 'renovacao da pos-graduacao;renovação da pós-graduação;renovacao da pos;renovação da pós', '100004',      '100004',      'pos_grad', 1, 0, NULL),
('Pós-graduação em Ortopedia Funcional',           'Pós-graduação em Ortopedia Funcional', 'ortopedia funcional', '100005',      '100005',      'pos_grad', 1, 0, NULL),
('Curso Online Plus',                                  'Curso Online Plus',                                  'curso online plus',                                          '100006', '200006', 'pos_grad', 1, 0, NULL),
('Curso de Extensão',                                     'Curso de Extensão',                                     'curso de extensao;curso de extensão',                                      '100007',       '100007',       'extensao',      1, 0, NULL),
('Curso de Extensão Ilimitado',                             'Curso de Extensão Ilimitado',                             'curso ilimitado;extensao ilimitado',          '100008',      '100008',      'extensao',      1, 0, NULL),
('Curso de Extensão Vitalício',                            'Curso de Extensão Vitalício',                            'curso vitalicio;curso vitalício', '100009',     '100009',       'extensao',      1, 0, NULL),
('Renovação do Curso de Extensão - 1 ano',                       'Renovação do Curso de Extensão - 1 ano',                       'renovacao do curso;renovação do curso', '100010', '100010', 'extensao', 1, 0, NULL),
('Curso de avaliação',                          'Curso de avaliação',                          'curso de avaliacao;curso de avaliação',              '100011',       '100011',       'outro',    1, 0, NULL),
('Curso de Técnicas Manuais',                 'Curso de Técnicas Manuais',                 'tecnicas manuais;técnicas manuais',              '100012',      '100012',      'outro',    1, 0, NULL),
('Planner',                                     'Planner',                                     'planner',                                             '2',            NULL,           'outro',    1, 1, 'Inativo por enquanto - somente NF-e quando reativar'),
('Apostila',                                    'Apostila',                                    'apostila',                                            '100013',      NULL,           'outro',    1, 1, 'Inativo por enquanto - somente NF-e quando reativar'),
('Livro Guia do Paciente',                    'Livro "Guia do Paciente"',                  'voce nao e sua dor;você não é sua dor;livro voce;livro você', '100014', NULL, 'outro', 1, 1, 'Inativo por enquanto - somente NF-e quando reativar');
