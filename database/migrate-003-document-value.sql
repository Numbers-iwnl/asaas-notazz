-- ============================================================
-- Migração 003 — coluna document_value (valor da nota após split)
-- ============================================================

-- 1) Adiciona a coluna
ALTER TABLE notazz_documents
    ADD COLUMN document_value DECIMAL(12,2) NULL AFTER product_id;

-- 2) Popular registros existentes:
--    Se a venda gerou as DUAS notas (nfe + nfse) → metade do valor pago
UPDATE notazz_documents d
JOIN asaas_payments p ON p.id = d.payment_row_id
SET d.document_value = ROUND(p.value / 2, 2)
WHERE EXISTS (
    SELECT 1 FROM (SELECT * FROM notazz_documents) d2
    WHERE d2.payment_row_id = d.payment_row_id
      AND d2.document_type <> d.document_type
      AND d2.status <> 'ignored'
);

-- 3) Documentos únicos (só um tipo) → valor cheio
UPDATE notazz_documents d
JOIN asaas_payments p ON p.id = d.payment_row_id
SET d.document_value = p.value
WHERE d.document_value IS NULL;
