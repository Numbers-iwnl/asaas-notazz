-- ============================================================
-- Migração 009 — Inadimplentes: produto + recuperação
--
-- O sync deixa de APAGAR quem saiu da lista de vencidos e passa a
-- RESOLVER a linha (pago / cancelado / renegociado), guardando data e
-- valor recuperado. Assim o painel calcula "quanto foi recuperado no
-- período". Também guarda o produto (via ProductMatcher) p/ agrupar.
-- ============================================================

ALTER TABLE inadimplentes
    ADD COLUMN product_name  VARCHAR(120) NULL AFTER description,
    ADD COLUMN fiscal_group  VARCHAR(20)  NULL AFTER product_name,
    ADD COLUMN resolved_at   TIMESTAMP    NULL AFTER contacted_at,
    ADD COLUMN resolved_status VARCHAR(30) NULL AFTER resolved_at,   -- pago | pago_manual | cancelado | renegociado
    ADD COLUMN recovered_value DECIMAL(12,2) NULL AFTER resolved_status,
    ADD KEY idx_inad_resolved (resolved_at);
