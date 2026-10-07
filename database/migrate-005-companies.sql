-- ============================================================
-- Migração 005 — MULTI-EMPRESA (Empresa 1 (Educação) + Empresa 2 (Mentorias))
--
-- Cria a tabela companies, vincula produtos e documentos a uma
-- empresa, e cadastra as duas empresas + produtos do Mentorias.
--
-- Retrocompatível: empresa 1 (Educação) é o padrão de tudo que
-- já existe; quando notazz_api_key é NULL, o sistema usa a chave
-- do config.php (comportamento atual, nada quebra).
-- ============================================================

CREATE TABLE IF NOT EXISTS companies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    trade_name      VARCHAR(120) NULL,
    cnpj            VARCHAR(20) NULL,
    municipal_reg   VARCHAR(30) NULL,
    state_reg       VARCHAR(30) NULL,
    tax_regime      VARCHAR(40) NULL,
    -- API da conta Notazz desta empresa (NULL = usa notazz.api_key do config.php)
    notazz_api_key  VARCHAR(255) NULL,
    -- Endereço do emitente (usado como fallback p/ cliente sem endereço)
    addr_street     VARCHAR(200) NULL,
    addr_number     VARCHAR(20) NULL,
    addr_complement VARCHAR(120) NULL,
    addr_district   VARCHAR(120) NULL,
    addr_city       VARCHAR(120) NULL,
    addr_state      VARCHAR(2) NULL,
    addr_zipcode    VARCHAR(10) NULL,
    city_ibge       VARCHAR(10) NULL,
    -- Regras de emissão
    nfe_percent     INT NOT NULL DEFAULT 50,          -- % do valor na NF-e (resto vai p/ NFS-e)
    auto_emission_delay_days INT NOT NULL DEFAULT 8,  -- dias até envio à SEFAZ/Prefeitura
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Empresa 1: Empresa 1 (Educação) (a atual — api_key NULL usa o config.php)
INSERT INTO companies
    (id, name, trade_name, cnpj, municipal_reg, state_reg, tax_regime, notazz_api_key,
     addr_street, addr_number, addr_complement, addr_district, addr_city, addr_state, addr_zipcode, city_ibge,
     nfe_percent, auto_emission_delay_days, active)
VALUES
    (1, 'EMPRESA EXEMPLO EDUCACAO LTDA', 'EMPRESA EXEMPLO EDUCACAO',
     '11111111000111', '0000001', '000000001', 'Normal', NULL,
     'Rua Exemplo', '2441', NULL, 'Centro', 'Recife', 'RN', '50000000', '2611606',
     50, 8, 1);

-- Empresa 2: Empresa 2 (Mentorias) (planilha 11/06/2026 — API key pendente: preencher no painel)
INSERT INTO companies
    (id, name, trade_name, cnpj, municipal_reg, state_reg, tax_regime, notazz_api_key,
     addr_street, addr_number, addr_complement, addr_district, addr_city, addr_state, addr_zipcode, city_ibge,
     nfe_percent, auto_emission_delay_days, active)
VALUES
    (2, 'EMPRESA EXEMPLO MENTORIAS LTDA', 'EMPRESA EXEMPLO MENTORIAS',
     '22222222000122', '0000002', NULL, 'Lucro Presumido', NULL,
     'Rua Exemplo', '2441', 'Andar 4', 'Centro', 'Recife', 'RN', '50000000', '2611606',
     0, 8, 1);

-- Vínculo de empresa em produtos e documentos (tudo existente = Educação)
ALTER TABLE products
    ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_products_company (company_id);

ALTER TABLE notazz_documents
    ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_docs_company (company_id);

-- Produtos da Empresa 2 (Mentorias) (planilha). Só NFS-e (sem ID de produto).
-- ⚠️ INATIVOS de propósito: (a) IDs de serviço 1/2/3 ainda não confirmados,
-- (b) API key do Mentorias ainda não cadastrada, (c) as keywords COLIDEM com
-- produtos da Educação ("Mentoria Essencial" / "Mentoria Premium") — é
-- preciso decidir se os da Educação serão desativados antes de ativar estes.
INSERT INTO products (company_id, name, asaas_description, keywords, notazz_product_id, notazz_service_id, fiscal_group, active, ignored, nfe_name, notes) VALUES
(2, 'MENTORIA EXECUTIVA',      'MENTORIA EXECUTIVA',      'mentoria executiva;mentoring executiva',                 NULL, '1', 'mentoria', 0, 0, NULL, 'Mentorias: ativar após confirmar ID de serviço e API key'),
(2, 'Mentoria Essencial',         'Mentoria Essencial',         'mentoria essencial;mentoring essencial',      NULL, '3', 'mentoria', 0, 0, NULL, 'Mentorias: CONFLITO de keywords com produto da Educação — resolver antes de ativar'),
(2, 'Mentoria Premium', 'Mentoria Premium', 'mentoria premium;mentoring premium',       NULL, '2', 'mentoria', 0, 0, NULL, 'Mentorias: CONFLITO de keywords com produto da Educação — resolver antes de ativar');
