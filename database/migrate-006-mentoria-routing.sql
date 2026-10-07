-- ============================================================
-- Migração 006 — Rotear MENTORIAS para o CNPJ Empresa 2 (Mentorias)
--
-- Decisão do financeiro (06/2026): as mentorias deste mês passam a emitir
-- no Empresa 2 (Mentorias) (só NFS-e, 100% do valor). Compras com parcelas em curso
-- que ainda casavam na Educação passam a ir pro Mentorias; eventuais ajustes
-- o financeiro faz na Revisão. O importante é ficar certo daqui pra frente.
--
-- Como funciona: o matcher agora só considera produtos ATIVOS. Então
-- desativamos os produtos de MENTORIA da Educação e deixamos os do Mentorias
-- capturando as descrições reais ("Mentoria Essencial 5.0", "Mentoria Premium"...).
-- Os demais produtos da Educação (Extensão, pós-graduação, cursos) ficam intactos.
-- ============================================================

-- 1) Desativa os produtos de MENTORIA que estavam na Educação (company_id = 1)
UPDATE products
SET active = 0,
    notes = CONCAT(COALESCE(notes,''), ' | 06/2026: mentoria migrada para Empresa 2 (Mentorias) (desativado na Educação)')
WHERE company_id = 1
  AND notazz_product_id IN ('100002', '100003');  -- Mentoria Premium, Mentoria Essencial

-- 2) Garante os produtos do Mentorias ativos, na empresa 2, com keywords que
--    capturam as descrições reais de mentoria vistas no Asaas.
UPDATE products SET active = 1, company_id = 2,
    keywords = 'mentoria executiva;mentoring executiva'
WHERE notazz_service_id = '1' AND name LIKE '%EXECUTIVA%';

UPDATE products SET active = 1, company_id = 2,
    keywords = 'mentoria essencial;mentoring essencial;renovacao mentoria essencial'
WHERE notazz_service_id = '3' AND name LIKE 'Mentoria Essencial%' AND name NOT LIKE '%Premium%';

UPDATE products SET active = 1, company_id = 2,
    keywords = 'mentoria premium;mentoring premium'
WHERE notazz_service_id = '2' AND name LIKE '%Premium%';
