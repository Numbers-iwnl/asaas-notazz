-- ============================================================
-- Migração 002 — adiciona coluna nfe_name e atualiza nomes
-- ============================================================

-- 1) Adiciona a coluna (idempotente: ignora se já existir)
ALTER TABLE products
    ADD COLUMN nfe_name VARCHAR(200) NULL AFTER name;

-- 2) Atualiza os nomes oficiais (NFSe = name; NFe = nfe_name)
UPDATE products SET name = 'Gestão de Clínicas na Prática',                              nfe_name = 'E-book Gestão de Clínicas na Prática'                              WHERE notazz_product_id = '100001';
UPDATE products SET name = 'Mentoria Premium',                                                   nfe_name = 'E-book Mentoria Premium'                                                   WHERE notazz_product_id = '100002';
UPDATE products SET name = 'Mentoria Essencial',                                                            nfe_name = 'E-book Mentoria Essencial'                                                            WHERE notazz_product_id = '100003';
UPDATE products SET name = 'Pós-graduação em Fisioterapia Avançada',         nfe_name = 'E-book Pós-graduação em Fisioterapia Avançada'         WHERE notazz_product_id = '200005';
UPDATE products SET name = 'Renovação da pós-graduação em Fisioterapia Avançada',                    nfe_name = 'E-book Renovação da pós-graduação em Fisioterapia Avançada'                    WHERE notazz_product_id = '100004';
UPDATE products SET name = 'Pós-graduação em Ortopedia Funcional',                           nfe_name = 'E-book Pós-graduação em Ortopedia Funcional'                           WHERE notazz_product_id = '100005';
UPDATE products SET name = 'Curso Online Plus',                                                                nfe_name = 'E-book Curso Online Plus'                                                                WHERE notazz_product_id = '100006';
UPDATE products SET name = 'Curso de Extensão',                                                                   nfe_name = 'E-book Curso de Extensão'                                                                   WHERE notazz_product_id = '100007';
UPDATE products SET name = 'Curso de Extensão Ilimitado',                                                           nfe_name = 'E-book Curso de Extensão Ilimitado'                                                           WHERE notazz_product_id = '100008';
UPDATE products SET name = 'Curso de Extensão Vitalício',                                                          nfe_name = 'E-book Curso de Extensão Vitalício'                                                          WHERE notazz_product_id = '100009';
UPDATE products SET name = 'Renovação do Curso de Extensão - 1 ano',                                                     nfe_name = 'E-book Renovação do Curso de Extensão - 1 ano'                                                     WHERE notazz_product_id = '100010';
UPDATE products SET name = 'Curso de avaliação',                                                        nfe_name = 'E-book Curso de avaliação'                                                        WHERE notazz_product_id = '100011';
UPDATE products SET name = 'Curso de Técnicas Manuais',                                               nfe_name = 'E-book Curso de Técnicas Manuais'                                               WHERE notazz_product_id = '100012';

-- 3) Os 3 produtos ignorados continuam com nome próprio na NFe (sem prefixo "E-book")
UPDATE products SET name = 'Planner',                       nfe_name = 'Planner'                       WHERE notazz_product_id = '2';
UPDATE products SET name = 'Apostila',                      nfe_name = 'Apostila'                      WHERE notazz_product_id = '100013';
UPDATE products SET name = 'Livro "Guia do Paciente"',    nfe_name = 'Livro "Guia do Paciente"'    WHERE notazz_product_id = '100014';
