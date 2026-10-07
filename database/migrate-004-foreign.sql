-- ============================================================
-- Migração 004 — suporte a clientes ESTRANGEIROS (sem CPF/CNPJ)
-- customer_country: código ISO 3166-1 alfa-2 (ex: PT, US, AR)
-- customer_foreign_doc: documento estrangeiro opcional (NIF/passaporte)
-- ============================================================

ALTER TABLE asaas_payments
    ADD COLUMN customer_country VARCHAR(2) NULL AFTER customer_state,
    ADD COLUMN customer_foreign_doc VARCHAR(40) NULL AFTER customer_country;
