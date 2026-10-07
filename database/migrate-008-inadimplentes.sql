-- ============================================================
-- Migração 008 — Inadimplentes (pagamentos vencidos e não pagos)
--
-- Alimentada pelo cron/sync-inadimplentes.php, que consulta o Asaas
-- (status=OVERDUE) de cada empresa e mantém esta tabela atualizada.
-- A aba admin/inadimplentes.php (login próprio do financeiro) lê daqui.
-- ============================================================

CREATE TABLE IF NOT EXISTS inadimplentes (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id         INT UNSIGNED NOT NULL DEFAULT 1,
    source             VARCHAR(20) NOT NULL DEFAULT 'asaas',   -- asaas | eduzz (fase 2)
    asaas_payment_id   VARCHAR(60) NOT NULL,
    asaas_customer_id  VARCHAR(60) NULL,
    customer_name      VARCHAR(200) NULL,
    customer_cpfcnpj   VARCHAR(20) NULL,
    customer_email     VARCHAR(160) NULL,
    customer_phone     VARCHAR(40) NULL,
    description        VARCHAR(500) NULL,
    value              DECIMAL(12,2) NULL,
    due_date           DATE NULL,
    days_overdue       INT NULL,
    billing_type       VARCHAR(40) NULL,
    installment        VARCHAR(60) NULL,       -- ex.: "Parcela 3 de 12"
    invoice_url        VARCHAR(500) NULL,      -- link da cobrança no Asaas
    status             VARCHAR(40) NULL,
    contacted_at       TIMESTAMP NULL,         -- "já contatei" (financeiro)
    notes              TEXT NULL,              -- anotações do financeiro
    first_seen_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_synced_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inadimplente (source, asaas_payment_id),
    KEY idx_inad_company (company_id),
    KEY idx_inad_due (due_date),
    KEY idx_inad_days (days_overdue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
