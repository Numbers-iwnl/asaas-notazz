<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;

final class ProductMatcher
{
    /**
     * Tenta casar a description do payload Asaas com um produto cadastrado.
     * Considera apenas produtos ATIVOS e não-ignorados — assim, controlar o
     * roteamento (qual empresa emite) é só uma questão de ativar/desativar produtos.
     * Retorna o produto cuja keyword é mais longa (match mais específico).
     * Em empate exato de comprimento, mantém o primeiro encontrado e registra
     * um aviso (sinaliza keywords ambíguas entre produtos/empresas a ajustar).
     */
    public static function match(?string $description): ?array
    {
        if (!$description) {
            return null;
        }
        $needle = self::normalize($description);
        $best = null;
        $bestLen = 0;
        $tie = false;

        foreach (ProductRepo::active() as $p) {
            $keywords = preg_split('/\s*;\s*/', (string)$p['keywords'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($keywords as $kw) {
                $norm = self::normalize($kw);
                if ($norm === '' || mb_strlen($norm) < 3) {
                    continue;
                }
                if (str_contains($needle, $norm)) {
                    $len = mb_strlen($norm);
                    if ($len > $bestLen) {
                        $bestLen = $len;
                        $best = $p;
                        $tie = false;
                    } elseif ($len === $bestLen && $best !== null && (int)$best['id'] !== (int)$p['id']) {
                        // Dois produtos diferentes casam com a MESMA força — ambíguo.
                        $tie = true;
                    }
                }
            }
        }

        if ($tie && $best !== null) {
            \App\Helpers\Logger::warning(
                'matcher',
                "Match ambíguo para descrição (keywords de mesmo tamanho em produtos diferentes) — verifique cadastro",
                ['description' => mb_substr($description, 0, 120), 'escolhido' => $best['name'] ?? null]
            );
        }
        return $best;
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string)$s));
    }
}
