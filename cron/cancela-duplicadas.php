<?php
declare(strict_types=1);

/**
 * Cancela/exclui notas NOSSAS que ficaram DUPLICADAS (o sistema emitiu, mas o
 * financeiro já tinha feito a mesma manualmente). Roteia pela empresa do doc.
 *
 * Antes de mexer, CONSULTA cada nota na Notazz e mostra o estado:
 *   - autorizada (tem número / statusNota autorizada)  -> já foi pra prefeitura:
 *       no --excluir tenta o cancelamento (delete) e avisa; é ato fiscal.
 *   - agendada/pendente (sem número)                   -> exclusão limpa (delete).
 *   - não encontrada                                    -> já saiu, nada a fazer.
 *
 * Uso (SSH):
 *   php cron/cancela-duplicadas.php --ids=163-204            # relatório (estado)
 *   php cron/cancela-duplicadas.php --ids=163-204 --excluir  # aplica
 *     --ids aceita faixa (163-204) e/ou lista (163,164,...).
 */

require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();

use App\Database;
use App\Clients\NotazzClient;
use App\Repositories\CompanyRepo;

$args    = $argv;
$excluir = in_array('--excluir', $args, true);

$idsArg = '';
foreach ($args as $a) { if (strncmp($a, '--ids=', 6) === 0) { $idsArg = substr($a, 6); } }
$ids = [];
foreach (explode(',', $idsArg) as $part) {
    $part = trim($part);
    if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
        for ($i = (int)$m[1]; $i <= (int)$m[2]; $i++) $ids[] = $i;
    } elseif (ctype_digit($part)) {
        $ids[] = (int)$part;
    }
}
$ids = array_values(array_unique($ids));
if (!$ids) { fwrite(STDERR, "Informe --ids=163-204\n"); exit(1); }

$clients = [];
$clientFor = function (int $companyId) use (&$clients): NotazzClient {
    if (!isset($clients[$companyId])) {
        $c = CompanyRepo::find($companyId);
        $clients[$companyId] = new NotazzClient($c['notazz_api_key'] ?? null);
    }
    return $clients[$companyId];
};

$place = implode(',', array_fill(0, count($ids), '?'));
$docs = Database::all("SELECT * FROM notazz_documents WHERE id IN ({$place}) ORDER BY id", $ids);
echo "Docs encontrados: " . count($docs) . ($excluir ? "  [EXCLUIR]" : "  [relatório]") . "\n\n";

$autoriz = 0; $agendada = 0; $sumiu = 0; $excluidas = 0; $puladas = 0;
foreach ($docs as $d) {
    $companyId = (int)($d['company_id'] ?? 1);
    $isNfe  = $d['document_type'] === 'nfe';
    $method = $isNfe ? 'consult_nfe_55' : 'consult_nfse';
    $docId  = (string)$d['notazz_document_id'];
    if ($docId === '') { echo "  #{$d['id']} sem document_id — pulando\n"; $puladas++; continue; }

    try {
        $r = $clientFor($companyId)->call($method, ['DOCUMENT_ID' => $docId])['response'] ?? [];
    } catch (\Throwable $e) { echo "  #{$d['id']} erro consulta: " . $e->getMessage() . "\n"; $puladas++; continue; }

    $status = strtolower((string)($r['statusProcessamento'] ?? ''));
    $num    = $r['numero'] ?? $r['nNF'] ?? $r['nNFSe'] ?? $r['number'] ?? null;
    $sNota  = (string)($r['statusNota'] ?? '');
    $naoExiste = $status === 'erro' || stripos(json_encode($r), 'nao encontrado') !== false;
    $isAuth = !$naoExiste && (!empty($num) || stripos($sNota, 'autoriz') !== false || stripos($sNota, 'emitid') !== false);

    $estado = $naoExiste ? 'NÃO ENCONTRADA' : ($isAuth ? "AUTORIZADA (nº {$num})" : "AGENDADA/PENDENTE ({$sNota})");
    echo sprintf("  #%-4d %s emp%d -> %s\n", $d['id'], strtoupper($d['document_type']), $companyId, $estado);

    if ($naoExiste) { $sumiu++; }
    elseif ($isAuth) { $autoriz++; }
    else { $agendada++; }

    if ($excluir && !$naoExiste) {
        try {
            $clientFor($companyId)->call($isNfe ? 'delete_nfe_55' : 'delete_nfse', ['DOCUMENT_ID' => $docId]);
            Database::update('notazz_documents', [
                'status' => 'ignored',
                'last_error' => 'Duplicada (nota manual do financeiro) — ' . ($isAuth ? 'cancelada' : 'excluída') . ' em ' . date('Y-m-d H:i'),
            ], 'id = ?', [$d['id']]);
            $excluidas++;
            echo "        -> " . ($isAuth ? 'CANCELADA' : 'excluída') . " na Notazz + marcada 'ignored'\n";
        } catch (\Throwable $e) {
            echo "        -> FALHOU: " . $e->getMessage() . "\n";
        }
    }
    usleep(800000); // respeita rate limit
}

echo "\n===== RESUMO =====\n";
echo "Autorizadas (já na prefeitura): {$autoriz}\n";
echo "Agendadas/pendentes: {$agendada}\n";
echo "Não encontradas (já fora): {$sumiu}\n";
if ($excluir) echo "Excluídas/canceladas agora: {$excluidas} | puladas: {$puladas}\n";
else echo "\nRode com --excluir para aplicar.\n";
if (!$excluir && $autoriz) echo "ATENÇÃO: {$autoriz} já estão AUTORIZADAS — excluir vai disparar CANCELAMENTO fiscal. Confirme com o financeiro.\n";
