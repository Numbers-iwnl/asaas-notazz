-- ============================================================
-- Migração 010 — Melhorias do painel de Inadimplentes
--
-- Habilita: histórico de contatos, retorno agendado, atribuição por
-- colaborador (ranking), e meta mensal. As métricas semanais/gráficos
-- usam colunas que já existem (resolved_at / recovered_value / contacted_at).
-- ============================================================

-- Atribuição de quem fez a ação (ranking por colaborador) + próximo retorno
ALTER TABLE inadimplentes
    ADD COLUMN contacted_by     VARCHAR(40) NULL AFTER contacted_at,
    ADD COLUMN resolved_by      VARCHAR(40) NULL AFTER resolved_status,
    ADD COLUMN next_action_at   DATETIME    NULL AFTER notes,   -- retorno agendado
    ADD COLUMN next_action_note VARCHAR(255) NULL AFTER next_action_at,
    ADD KEY idx_inad_next_action (next_action_at),
    ADD KEY idx_inad_resolved_by (resolved_by);

-- Histórico de contatos (N registros por inadimplente)
CREATE TABLE IF NOT EXISTS inadimplente_contatos (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inadimplente_id   BIGINT UNSIGNED NOT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by        VARCHAR(40) NULL,           -- usuário logado (financeiro/gestor/suporte)
    channel           VARCHAR(20) NULL,           -- whatsapp | ligacao | email | outro
    summary           TEXT NULL,                  -- resumo da conversa
    status_negociacao VARCHAR(40) NULL,           -- ex.: prometeu pagar, sem resposta, acordo...
    next_action_at    DATETIME NULL,              -- retorno agendado deste contato
    next_action_note  VARCHAR(255) NULL,
    KEY idx_contato_inad (inadimplente_id),
    KEY idx_contato_by (created_by),
    KEY idx_contato_created (created_at),
    CONSTRAINT fk_contato_inad FOREIGN KEY (inadimplente_id) REFERENCES inadimplentes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Config do painel (meta mensal etc.) — chave/valor editável na tela
CREATE TABLE IF NOT EXISTS painel_config (
    chave  VARCHAR(40) NOT NULL PRIMARY KEY,
    valor  VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO painel_config (chave, valor) VALUES ('meta_mensal', '10000.00')
    ON DUPLICATE KEY UPDATE chave = chave;
