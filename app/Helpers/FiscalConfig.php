<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Configurações fiscais conforme a planilha "Relação de produtos Asaas".
 *
 * Origem dos dados:
 *  - Aba "Configuração NF de produto" → constantes NFE_*
 *  - Aba "Configuração NF de serviço"  → constante NFSE_GROUPS, indexada por fiscal_group
 *
 * Se algum valor mudar na vida real, edite aqui (ou migre para a tabela
 * `products` se quiser variar por produto).
 */
final class FiscalConfig
{
    /**
     * Configurações fixas para todas as NF-e de produto (livros - NCM 49019900).
     */
    public const NFE_PRODUCT = [
        'NCM'                   => '49019900',
        'ICMS_CST'              => '41',     // Não tributada
        'ICMS_ALIQUOTA'         => '0',
        'IPI_CST'               => '54',     // Saída imune
        'IPI_ALIQUOTA'          => '0',
        'PIS_CST'               => '06',     // Operação tributável com alíquota zero
        'PIS_ALIQUOTA'          => '0.65',
        'COFINS_CST'            => '06',
        'COFINS_ALIQUOTA'       => '3.00',
        // IBS/CBS (Reforma Tributária) — produto IMUNE (livro), alíquota zero de
        // verdade. O CST (410) é derivado pela Notazz a partir do CCLASSTRIBIBSCBS;
        // os campos de alíquota precisam vir presentes (mesmo "0") pra ela montar
        // o bloco <IBSCBS>/<IBSCBSTot> no XML — omitir os campos omite o bloco todo.
        'IBS_UF'                => '0',
        'IBS_MUN'               => '0',
        'CBS'                   => '0',
        'IS'                    => '0',
        'CCLASSTRIBIBSCBS'      => '410008', // Fornecimento de livros — imunidade tributária (Art. 150, VI, d, CF/88)
    ];

    /**
     * Configurações de NFS-e por grupo fiscal.
     * Identificado pela coluna `fiscal_group` da tabela `products`.
     */
    public const NFSE_GROUPS = [
        'pos_grad' => [
            'CNAE'                     => '8599604',
            'NBS'                      => '122042000',  // 1.2204.20.00 - Serviço educacional de pós-graduação
            'CITY_SERVICE_CODE'        => '80201',
            'CITY_SERVICE_DESCRIPTION' => 'Instrução, treinamento, orientação pedagógica e educacional',
            'SERVICE_LIST_LC116'       => '8.02',
            'CCLASSTRIB'               => '000001',
            'WITHHELD_ISS'             => '0',
            'ALIQUOTAS' => [
                'ISS'    => '5.00',
            ],
        ],
        'extensao' => [
            'CNAE'                     => '8599604',
            'NBS'                      => '122043000',  // 1.2204.30.00 - Serviço educacional de extensão
            'CITY_SERVICE_CODE'        => '80201',
            'CITY_SERVICE_DESCRIPTION' => 'Instrução, treinamento, orientação pedagógica e educacional',
            'SERVICE_LIST_LC116'       => '8.02',
            'CCLASSTRIB'               => '000001',
            'WITHHELD_ISS'             => '0',
            'ALIQUOTAS' => [
                'ISS'    => '5.00',
            ],
        ],
        'mentoria' => [
            'CNAE'                     => '8599604',
            'NBS'                      => '122051900',  // 1.2205.19.00 - Serviço de educação (treinamento)
            'CITY_SERVICE_CODE'        => '80201',
            'CITY_SERVICE_DESCRIPTION' => 'Instrução, treinamento, orientação pedagógica e educacional',
            'SERVICE_LIST_LC116'       => '8.02',
            'CCLASSTRIB'               => '000001',
            'WITHHELD_ISS'             => '0',
            'ALIQUOTAS' => [
                'ISS'    => '5.00',
            ],
        ],
        'outro' => [
            // Mesmo padrão da pós-graduação como fallback
            'CNAE'                     => '8599604',
            'NBS'                      => '122051900',
            'CITY_SERVICE_CODE'        => '80201',
            'CITY_SERVICE_DESCRIPTION' => 'Instrução, treinamento, orientação pedagógica e educacional',
            'SERVICE_LIST_LC116'       => '8.02',
            'CCLASSTRIB'               => '000001',
            'WITHHELD_ISS'             => '0',
            'ALIQUOTAS' => [
                'ISS'    => '5.00',
            ],
        ],
    ];

    /**
     * Overrides de NFS-e por EMPRESA (multi-empresa).
     * Empresa 2 = Empresa 2 (Mentorias) (Lucro Presumido): além do ISS,
     * destaca os federais PIS/COFINS/IR conforme planilha do financeiro (06/2026).
     */
    public const NFSE_COMPANY_OVERRIDES = [
        2 => [
            'mentoria' => [
                'CNAE'                     => '8599604',
                'NBS'                      => '122051900',  // 1.2205.19.00 - Serviço de educação/treinamento
                'CITY_SERVICE_CODE'        => '80201',
                'CITY_SERVICE_DESCRIPTION' => 'Instrução, treinamento, orientação pedagógica e educacional',
                'SERVICE_LIST_LC116'       => '8.02',
                'CCLASSTRIB'               => '000001',
                'WITHHELD_ISS'             => '0',
                'ALIQUOTAS' => [
                    'ISS'    => '5.00',
                    // Lucro Presumido — federais destacados
                    'PIS'    => '0.65',
                    'COFINS' => '3.00',
                    'IR'     => '0.00',   // zerado a pedido do financeiro (07/2026)
                ],
            ],
        ],
    ];

    /**
     * CFOP dinâmico conforme a UF do destinatário e do emitente.
     */
    public static function cfop(?string $destState, string $emitterState = 'RN'): string
    {
        $dest = strtoupper(trim((string)$destState));
        if ($dest === '' || $dest === 'EX') {
            // EX = Exterior (NFe campo padrão SEFAZ)
            return $dest === 'EX' ? '7102' : '5102';
        }
        return $dest === strtoupper($emitterState) ? '5102' : '6102';
    }

    /**
     * Retorna as configs de NFS-e do grupo informado, considerando
     * overrides da empresa (multi-empresa).
     */
    public static function nfseGroup(?string $fiscalGroup, int $companyId = 1): array
    {
        $key = $fiscalGroup ?: 'outro';
        if (isset(self::NFSE_COMPANY_OVERRIDES[$companyId][$key])) {
            return self::NFSE_COMPANY_OVERRIDES[$companyId][$key];
        }
        return self::NFSE_GROUPS[$key] ?? self::NFSE_GROUPS['outro'];
    }
}
