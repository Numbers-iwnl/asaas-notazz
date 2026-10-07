-- ============================================================
-- Migração 007 — Token Asaas por empresa (multi-conta)
--
-- As mentorias novas (Empresa 2 (Mentorias)) são cobradas em uma conta Asaas separada,
-- com o próprio token. O webhook roteia por produto (mentoria -> empresa 2), e o
-- enriquecimento de cliente / auditoria precisa consultar a conta Asaas certa.
--
-- company 1 (Educação): token NULL = usa o do config.php (conta atual).
-- company 2 (Mentorias): preencher com o token da conta Asaas do CNPJ Mentorias.
-- ============================================================

ALTER TABLE companies
    ADD COLUMN asaas_api_token VARCHAR(255) NULL AFTER notazz_api_key;

-- Educação continua usando o token padrão do config.php
UPDATE companies SET asaas_api_token = NULL WHERE id = 1;

-- Mentorias: troque <TOKEN_ASAAS_MENTORIAS> pelo token real da conta Asaas do CNPJ Mentorias.
-- UPDATE companies SET asaas_api_token = '<TOKEN_ASAAS_MENTORIAS>' WHERE id = 2;
