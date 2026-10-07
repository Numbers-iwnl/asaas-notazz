<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();

use App\Bootstrap;
use App\Database;
use App\Helpers\Security;
use App\Repositories\CompanyRepo;

/* ===========================================================================
 * Login PRÓPRIO do financeiro — HTTP Basic Auth (multiusuário).
 * Credenciais no config.php: 'inadimplentes' => ['users' => ['fulano'=>'senha']]
 * ========================================================================= */
function basicCreds(): array {
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [(string)$_SERVER['PHP_AUTH_USER'], (string)($_SERVER['PHP_AUTH_PW'] ?? '')];
    }
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'basic ') === 0) {
        $dec = base64_decode(substr($h, 6));
        if ($dec !== false && strpos($dec, ':') !== false) { [$a, $b] = explode(':', $dec, 2); return [$a, $b]; }
    }
    return ['', ''];
}
$creds = [];
$legacyUser = (string)Bootstrap::config('inadimplentes.user', '');
if ($legacyUser !== '') {
    $creds[$legacyUser] = (string)Bootstrap::config('inadimplentes.pass_hash', '')
        ?: (string)Bootstrap::config('inadimplentes.pass', '');
}
$usersMap = Bootstrap::config('inadimplentes.users', null);
if (is_array($usersMap)) { foreach ($usersMap as $un => $pw) { if ((string)$un !== '') $creds[(string)$un] = (string)$pw; } }
[$u, $p] = basicCreds();
$authOk = false;
if ($u !== '' && isset($creds[$u])) {
    $stored = $creds[$u];
    $authOk = strncmp($stored, '$2', 2) === 0 ? password_verify($p, $stored) : ($stored !== '' && hash_equals($stored, $p));
}
if (!$authOk) {
    header('WWW-Authenticate: Basic realm="Inadimplentes"');
    http_response_code(401);
    echo 'Acesso restrito ao financeiro.';
    exit;
}
$currentUser = $u; // usado para atribuir ações (ranking / responsável)
$tok = hash_hmac('sha256', 'inadimplentes', (string)Bootstrap::config('security.csrf_secret', 'x'));

/* ---- helpers ---- */
function brl($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function cfg(string $k, string $def = ''): string {
    $r = Database::one('SELECT valor FROM painel_config WHERE chave = ?', [$k]);
    return $r ? (string)$r['valor'] : $def;
}
function tempoDesde(?string $ts): string {
    if (!$ts) return 'nunca';
    $d = (int)floor((time() - strtotime($ts)) / 86400);
    if ($d <= 0) return 'hoje'; if ($d === 1) return 'ontem'; return "há {$d} dias";
}
function prioridade(float $v, int $d): array {          // [classe, rótulo]
    if ($d > 60 || $v > 1000) return ['r', '🔴 Alta'];
    if ($d >= 30)             return ['o', '🟡 Média'];
    return ['g', '🟢 Baixa'];
}
function chanceRec(int $d): array {                      // probabilidade de recuperar
    if ($d <= 30) return ['g', '🟢 Alta'];
    if ($d <= 90) return ['o', '🟡 Média'];
    return ['r', '🔴 Baixa'];
}
function corAtraso(int $d): string {                     // alerta visual por tempo
    if ($d > 60) return 'r'; if ($d > 30) return 'o'; if ($d > 15) return 'y'; return 'g';
}

/* ===========================================================================
 * AÇÕES (POST)
 * ========================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($tok, (string)($_POST['_t'] ?? ''))) {
        $acao = (string)($_POST['acao'] ?? '');
        $id   = (int)($_POST['id'] ?? 0);

        if ($acao === 'meta') {
            $meta = str_replace(['.', ','], ['', '.'], trim((string)($_POST['meta'] ?? '')));
            Database::run('INSERT INTO painel_config (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                ['meta_mensal', is_numeric($meta) ? number_format((float)$meta, 2, '.', '') : '0']);
        } elseif ($id > 0) {
            $row = Database::one('SELECT * FROM inadimplentes WHERE id = ?', [$id]);
            if ($row) {
                if ($acao === 'recuperar') {
                    Database::update('inadimplentes', [
                        'resolved_at' => date('Y-m-d H:i:s'), 'resolved_status' => 'pago_manual',
                        'recovered_value' => (float)($row['value'] ?? 0), 'resolved_by' => $currentUser,
                    ], 'id = ?', [$id]);
                } elseif ($acao === 'reabrir') {
                    Database::update('inadimplentes', [
                        'resolved_at' => null, 'resolved_status' => null, 'recovered_value' => null, 'resolved_by' => null,
                    ], 'id = ?', [$id]);
                } elseif ($acao === 'contatar') {
                    Database::update('inadimplentes', [
                        'contacted_at' => $row['contacted_at'] ? null : date('Y-m-d H:i:s'),
                        'contacted_by' => $row['contacted_at'] ? null : $currentUser,
                    ], 'id = ?', [$id]);
                } elseif ($acao === 'nota') {
                    Database::update('inadimplentes', ['notes' => trim((string)($_POST['notes'] ?? '')) ?: null], 'id = ?', [$id]);
                } elseif ($acao === 'contato') {
                    // Registra um contato no histórico + atualiza o "estado atual" da linha
                    $next = trim((string)($_POST['next_action_at'] ?? ''));
                    $nextAt = preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})?/', $next) ? str_replace('T', ' ', $next) : null;
                    Database::run(
                        'INSERT INTO inadimplente_contatos (inadimplente_id, created_by, channel, summary, status_negociacao, next_action_at, next_action_note)
                         VALUES (?,?,?,?,?,?,?)',
                        [$id, $currentUser, (string)($_POST['channel'] ?? '') ?: null,
                         trim((string)($_POST['summary'] ?? '')) ?: null, trim((string)($_POST['status_neg'] ?? '')) ?: null,
                         $nextAt, trim((string)($_POST['next_note'] ?? '')) ?: null]);
                    Database::update('inadimplentes', [
                        'contacted_at' => date('Y-m-d H:i:s'), 'contacted_by' => $currentUser,
                        'next_action_at' => $nextAt, 'next_action_note' => trim((string)($_POST['next_note'] ?? '')) ?: null,
                    ], 'id = ?', [$id]);
                }
            }
        }
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /admin/inadimplentes.php' . ($qs ? '?' . $qs : ''));
    exit;
}

/* ===========================================================================
 * FILTROS
 * ========================================================================= */
$empresa = (int)($_GET['empresa'] ?? 0);
$diasMin = (int)($_GET['dias'] ?? 0);
$q       = trim((string)($_GET['q'] ?? ''));
$de      = trim((string)($_GET['de'] ?? ''));
$ate     = trim((string)($_GET['ate'] ?? ''));
$produtos = array_values(array_filter((array)($_GET['produtos'] ?? []), static fn($x) => $x !== ''));
$fonte   = in_array(($_GET['fonte'] ?? ''), ['asaas', 'eduzz'], true) ? (string)$_GET['fonte'] : '';
$qf      = (string)($_GET['qf'] ?? '');    // filtro rápido
$ver     = ($_GET['ver'] ?? 'aberto') === 'recuperado' ? 'recuperado' : 'aberto';
$ord     = in_array(($_GET['ord'] ?? ''), ['recentes', 'atraso', 'valor', 'prioridade'], true) ? (string)$_GET['ord'] : 'recentes';
$export  = in_array(($_GET['export'] ?? ''), ['csv', 'excel', 'pdf'], true) ? (string)$_GET['export'] : '';

$deOk  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)  ? $de  : '';
$ateOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) ? $ate : '';
$recDe  = $deOk  ?: date('Y-m-01');
$recAte = $ateOk ?: date('Y-m-d');

// Semanas (domingo→sábado)
$dow = (int)date('w');
$weekStart = date('Y-m-d', strtotime("today -{$dow} days"));
$weekPrev  = date('Y-m-d', strtotime("{$weekStart} -7 days"));
$monthStart = date('Y-m-01');

$wCommon = []; $bCommon = [];
if ($empresa > 0) { $wCommon[] = 'company_id = ?'; $bCommon[] = $empresa; }
if ($produtos) {
    $ors = []; foreach ($produtos as $pp) {
        if ($pp === '(sem produto)') { $ors[] = 'product_name IS NULL'; }
        else { $ors[] = 'product_name = ?'; $bCommon[] = $pp; }
    }
    $wCommon[] = '(' . implode(' OR ', $ors) . ')';
}
if ($q !== '') {
    // Busca por telefone só entra se sobrar algum dígito — senão "%%" (string
    // vazia entre os coringas) casa com QUALQUER telefone preenchido, e uma
    // busca por e-mail (sem números) virava um match quase universal.
    $ors = ['customer_name LIKE ?', 'customer_cpfcnpj LIKE ?', 'customer_email LIKE ?'];
    $qb  = ["%$q%", "%$q%", "%$q%"];
    $qDigits = preg_replace('/\D+/', '', $q);
    if ($qDigits !== '') { $ors[] = 'customer_phone LIKE ?'; $qb[] = "%{$qDigits}%"; }
    $wCommon[] = '(' . implode(' OR ', $ors) . ')';
    array_push($bCommon, ...$qb);
}
if ($fonte !== '') { $wCommon[] = 'source = ?'; $bCommon[] = $fonte; }
$sqlCommon = $wCommon ? (' AND ' . implode(' AND ', $wCommon)) : '';

// Filtros rápidos (qf) — alguns forçam a aba
$qfOpen = ''; $qfOpenB = []; $qfRec = ''; $qfRecB = [];
switch ($qf) {
    case 'nao_contatado':  $qfOpen = ' AND contacted_at IS NULL'; break;
    case 'contatado_hoje': $qfOpen = ' AND DATE(contacted_at) = CURDATE()'; break;
    case 'v500':  $qfOpen = ' AND value >= 500';  break;
    case 'v1000': $qfOpen = ' AND value >= 1000'; break;
    case 'd30':   $qfOpen = ' AND days_overdue >= 30'; break;
    case 'd60':   $qfOpen = ' AND days_overdue >= 60'; break;
    case 'd90':   $qfOpen = ' AND days_overdue >= 90'; break;
    case 'agendados': $qfOpen = ' AND next_action_at IS NOT NULL'; break;
    case 'rec_hoje':   $ver = 'recuperado'; $qfRec = ' AND DATE(resolved_at) = CURDATE()'; break;
    case 'rec_semana': $ver = 'recuperado'; $qfRec = ' AND resolved_at >= ?'; $qfRecB[] = $weekStart . ' 00:00:00'; break;
}

/* ===========================================================================
 * KPIs + MÉTRICAS
 * ========================================================================= */
$wOpen = 'resolved_at IS NULL' . $sqlCommon . $qfOpen; $bOpen = array_merge($bCommon, $qfOpenB);
if ($diasMin > 0) { $wOpen .= ' AND days_overdue >= ?'; $bOpen[] = $diasMin; }
if ($deOk)  { $wOpen .= ' AND due_date >= ?'; $bOpen[] = $deOk; }
if ($ateOk) { $wOpen .= ' AND due_date <= ?'; $bOpen[] = $ateOk; }
$kOpen = Database::one("SELECT COUNT(*) c, COALESCE(SUM(value),0) v FROM inadimplentes WHERE {$wOpen}", $bOpen);

$wRec = "resolved_status IN ('pago','pago_manual') AND resolved_at >= ? AND resolved_at <= ?" . $sqlCommon . $qfRec;
$bRec = array_merge([$recDe . ' 00:00:00', $recAte . ' 23:59:59'], $bCommon, $qfRecB);
$kRec = Database::one("SELECT COUNT(*) c, COALESCE(SUM(recovered_value),0) v FROM inadimplentes WHERE {$wRec}", $bRec);

$taxa = ((float)$kRec['v'] + (float)$kOpen['v']) > 0 ? (float)$kRec['v'] / ((float)$kRec['v'] + (float)$kOpen['v']) * 100 : 0.0;

// Recuperado semana atual × anterior
$recWeek = (float)(Database::one("SELECT COALESCE(SUM(recovered_value),0) v FROM inadimplentes WHERE resolved_status IN ('pago','pago_manual') AND resolved_at >= ?{$sqlCommon}", array_merge([$weekStart . ' 00:00:00'], $bCommon))['v'] ?? 0);
$recWeekPrev = (float)(Database::one("SELECT COALESCE(SUM(recovered_value),0) v FROM inadimplentes WHERE resolved_status IN ('pago','pago_manual') AND resolved_at >= ? AND resolved_at < ?{$sqlCommon}", array_merge([$weekPrev . ' 00:00:00', $weekStart . ' 00:00:00'], $bCommon))['v'] ?? 0);
$varWeek = $recWeekPrev > 0 ? ($recWeek - $recWeekPrev) / $recWeekPrev * 100 : ($recWeek > 0 ? 100 : 0);

// Recuperado no mês (meta)
$recMonth = (float)(Database::one("SELECT COALESCE(SUM(recovered_value),0) v FROM inadimplentes WHERE resolved_status IN ('pago','pago_manual') AND resolved_at >= ?{$sqlCommon}", array_merge([$monthStart . ' 00:00:00'], $bCommon))['v'] ?? 0);
$meta = (float)cfg('meta_mensal', '0');
$metaPct = $meta > 0 ? $recMonth / $meta * 100 : 0;

// Gráfico: recuperado por dia da semana (semana atual)
$byDow = array_fill(0, 7, 0.0);
foreach (Database::all("SELECT DAYOFWEEK(resolved_at) d, COALESCE(SUM(recovered_value),0) v FROM inadimplentes WHERE resolved_status IN ('pago','pago_manual') AND resolved_at >= ?{$sqlCommon} GROUP BY d", array_merge([$weekStart . ' 00:00:00'], $bCommon)) as $r) {
    $byDow[((int)$r['d'] + 6) % 7] = (float)$r['v']; // DAYOFWEEK 1=Dom → índice 0=Dom
}
// Gráfico: evolução das últimas 12 semanas
$evo = [];
for ($i = 11; $i >= 0; $i--) {
    $ws = date('Y-m-d', strtotime("{$weekStart} -{$i} weeks"));
    $we = date('Y-m-d', strtotime("{$ws} +7 days"));
    $v = (float)(Database::one("SELECT COALESCE(SUM(recovered_value),0) v FROM inadimplentes WHERE resolved_status IN ('pago','pago_manual') AND resolved_at >= ? AND resolved_at < ?{$sqlCommon}", array_merge([$ws . ' 00:00:00', $we . ' 00:00:00'], $bCommon))['v'] ?? 0);
    $evo[] = ['label' => date('d/m', strtotime($ws)), 'v' => $v];
}

// Produtividade
$prod = [
    'contatos_hoje' => (int)(Database::one("SELECT COUNT(*) c FROM inadimplente_contatos WHERE DATE(created_at) = CURDATE()")['c'] ?? 0),
    'rec_hoje'      => (int)(Database::one("SELECT COUNT(*) c FROM inadimplentes WHERE resolved_status IN ('pago','pago_manual') AND DATE(resolved_at) = CURDATE(){$sqlCommon}", $bCommon)['c'] ?? 0),
    'rec_semana'    => (int)(Database::one("SELECT COUNT(*) c FROM inadimplentes WHERE resolved_status IN ('pago','pago_manual') AND resolved_at >= ?{$sqlCommon}", array_merge([$weekStart . ' 00:00:00'], $bCommon))['c'] ?? 0),
    'val_mes'       => $recMonth,
];

// Ranking por colaborador (no período do card verde)
$ranking = Database::all(
    "SELECT resolved_by u, COUNT(*) c, COALESCE(SUM(recovered_value),0) v FROM inadimplentes
     WHERE resolved_status IN ('pago','pago_manual') AND resolved_at >= ? AND resolved_at <= ? AND resolved_by IS NOT NULL{$sqlCommon}
     GROUP BY resolved_by ORDER BY v DESC",
    array_merge([$recDe . ' 00:00:00', $recAte . ' 23:59:59'], $bCommon));

/* ---- Resumo por produto/curso (com taxa) ---- */
$bdAbertos = Database::all("SELECT COALESCE(product_name,'(sem produto)') p, COUNT(*) c, COALESCE(SUM(value),0) v FROM inadimplentes WHERE resolved_at IS NULL{$sqlCommon} GROUP BY p", $bCommon);
$bdRec = Database::all("SELECT COALESCE(product_name,'(sem produto)') p, COALESCE(SUM(recovered_value),0) v FROM inadimplentes WHERE resolved_status IN ('pago','pago_manual') AND resolved_at >= ? AND resolved_at <= ?{$sqlCommon} GROUP BY p",
    array_merge([$recDe . ' 00:00:00', $recAte . ' 23:59:59'], $bCommon));
$breakdown = [];
foreach ($bdAbertos as $r) { $breakdown[$r['p']] = ['c' => (int)$r['c'], 'v' => (float)$r['v'], 'rec' => 0.0]; }
foreach ($bdRec as $r) { $breakdown[$r['p']] = ($breakdown[$r['p']] ?? ['c'=>0,'v'=>0.0,'rec'=>0.0]); $breakdown[$r['p']]['rec'] = (float)$r['v']; }
uasort($breakdown, static fn($a, $b) => $b['v'] <=> $a['v']);

/* ---- Lista ---- */
$DISPLAY_CAP = 300;
if ($ver === 'aberto') {
    $orderSql = [
        'recentes'   => 'due_date DESC, value DESC',
        'atraso'     => 'days_overdue DESC, value DESC',
        'valor'      => 'value DESC',
        'prioridade' => '(value > 1000 OR days_overdue > 60) DESC, days_overdue DESC, value DESC',
    ][$ord];
    $rows = Database::all("SELECT * FROM inadimplentes WHERE {$wOpen} ORDER BY {$orderSql} LIMIT {$DISPLAY_CAP}", $bOpen);
} else {
    $orderSql = $ord === 'valor' ? 'recovered_value DESC' : 'resolved_at DESC';
    $rows = Database::all("SELECT * FROM inadimplentes WHERE {$wRec} ORDER BY {$orderSql} LIMIT {$DISPLAY_CAP}", $bRec);
}
// Renderizar TUDO de uma vez (sem paginação) trava a página quando passa de
// algumas centenas de linhas — o servidor derruba a conexão no meio do
// carregamento, sem erro visível (nem o PHP, nem o navegador mostram nada).
// Por isso há um teto + aviso EXPLÍCITO (diferente do corte antigo, que era
// silencioso): mostra as mais urgentes e orienta a usar filtro pra ver o resto.
$totalReal = (int)($ver === 'aberto' ? $kOpen['c'] : $kRec['c']);
$foiTruncado = $totalReal > count($rows);

// Histórico de contatos das linhas exibidas
$contatos = [];
$ids = array_map(static fn($r) => (int)$r['id'], $rows);
if ($ids && !$export) {
    $place = implode(',', array_fill(0, count($ids), '?'));
    foreach (Database::all("SELECT * FROM inadimplente_contatos WHERE inadimplente_id IN ({$place}) ORDER BY created_at DESC", $ids) as $c) {
        $contatos[(int)$c['inadimplente_id']][] = $c;
    }
}

$empNome = [];
foreach (CompanyRepo::all() as $c) { $empNome[(int)$c['id']] = $c['trade_name'] ?: $c['name']; }
$prodOpts = Database::all("SELECT DISTINCT COALESCE(product_name,'(sem produto)') p FROM inadimplentes ORDER BY p");
$ultimaSync = Database::one('SELECT MAX(last_synced_at) s FROM inadimplentes');

// Agrupa por cliente (mesmo CPF/CNPJ; sem documento agrupa por nome) — várias
// cobranças da mesma pessoa em épocas diferentes ficam juntas, com um resumo
// que expande. Mantém a ordem já definida por $ord (grupo aparece na posição
// da 1ª linha do cliente na lista já ordenada).
$groups = [];
foreach ($rows as $r) {
    $gk = trim((string)($r['customer_cpfcnpj'] ?? ''));
    if ($gk === '') $gk = 'n:' . mb_strtolower(trim((string)($r['customer_name'] ?? '')));
    if ($gk === '' || $gk === 'n:') $gk = 'row:' . $r['id']; // sem doc nem nome: não agrupa
    $groups[$gk]['rows'][] = $r;
    $groups[$gk]['name']  = $r['customer_name'] ?? '—';
    $groups[$gk]['cpf']   = $r['customer_cpfcnpj'] ?? '';
}

/* ===========================================================================
 * EXPORT (csv / excel / pdf) — respeita os filtros/aba atuais
 * ========================================================================= */
if ($export) {
    $head = ['Cliente','CPF/CNPJ','E-mail','WhatsApp','Empresa','Fonte','Produto','Valor','Vencimento','Dias em atraso'];
    if ($ver === 'recuperado') { $head[] = 'Recuperado em'; $head[] = 'Valor recuperado'; $head[] = 'Por'; }
    else { $head[] = 'Contatado'; $head[] = 'Prioridade'; }
    $data = [];
    foreach ($rows as $r) {
        $dias = (int)($r['days_overdue'] ?? 0);
        $line = [
            $r['customer_name'] ?? '', $r['customer_cpfcnpj'] ?? '', $r['customer_email'] ?? '', $r['customer_phone'] ?? '',
            $empNome[(int)$r['company_id']] ?? '', ($r['source'] === 'eduzz' ? 'Eduzz' : 'Asaas'), $r['product_name'] ?? '',
            number_format((float)$r['value'], 2, ',', '.'), $r['due_date'] ? date('d/m/Y', strtotime((string)$r['due_date'])) : '', $dias,
        ];
        if ($ver === 'recuperado') {
            $line[] = $r['resolved_at'] ? date('d/m/Y', strtotime((string)$r['resolved_at'])) : '';
            $line[] = number_format((float)$r['recovered_value'], 2, ',', '.');
            $line[] = $r['resolved_by'] ?? '';
        } else {
            $line[] = $r['contacted_at'] ? date('d/m/Y', strtotime((string)$r['contacted_at'])) : 'não';
            $line[] = prioridade((float)$r['value'], $dias)[1];
        }
        $data[] = $line;
    }
    $fname = 'inadimplentes_' . $ver . '_' . date('Y-m-d');
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, $head, ';');
        foreach ($data as $l) fputcsv($out, $l, ';');
        fclose($out);
        exit;
    }
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '.xls"');
        echo "\xEF\xBB\xBF<table border=1><tr>";
        foreach ($head as $h) echo '<th>' . Security::e($h) . '</th>';
        echo '</tr>';
        foreach ($data as $l) { echo '<tr>'; foreach ($l as $c) echo '<td>' . Security::e((string)$c) . '</td>'; echo '</tr>'; }
        echo '</table>';
        exit;
    }
    // pdf → HTML pronto pra imprimir
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>' . $fname . '</title>';
    echo '<style>body{font:12px system-ui;margin:20px}h1{font-size:16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:4px 6px;text-align:left}th{background:#eee}</style>';
    echo '<h1>Inadimplentes — ' . ($ver === 'recuperado' ? 'Recuperados' : 'Em atraso') . ' — ' . date('d/m/Y') . '</h1>';
    echo '<table><tr>'; foreach ($head as $h) echo '<th>' . Security::e($h) . '</th>'; echo '</tr>';
    foreach ($data as $l) { echo '<tr>'; foreach ($l as $c) echo '<td>' . Security::e((string)$c) . '</td>'; echo '</tr>'; }
    echo '</table><script>window.onload=()=>window.print()</script>';
    exit;
}

function qs(array $mod): string {
    $keys = ['empresa','dias','q','de','ate','fonte','ord','ver','qf'];
    $base = [];
    foreach ($keys as $k) { $base[$k] = $_GET[$k] ?? null; }
    if (!empty($_GET['produtos'])) $base['produtos'] = (array)$_GET['produtos'];
    $out = array_filter(array_merge($base, $mod), static fn($v) => $v !== null && $v !== '' && $v !== '0' && $v !== []);
    return $out ? ('?' . http_build_query($out)) : '';
}
$temFiltro = $empresa || $diasMin || $q !== '' || $de !== '' || $ate !== '' || $produtos || $fonte !== '' || $qf !== '';

/* ---- SVG helpers (gráficos, sem lib externa) ---- */
function svgBars(array $vals, array $labels): string {
    $w = 520; $h = 150; $pad = 22; $n = count($vals); $max = max(1, max($vals));
    $bw = ($w - $pad * 2) / $n * 0.62; $gap = ($w - $pad * 2) / $n;
    $s = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" style="max-width:560px">';
    foreach ($vals as $i => $v) {
        $bh = $max > 0 ? ($v / $max) * ($h - 45) : 0;
        $x = $pad + $i * $gap + ($gap - $bw) / 2; $y = $h - 26 - $bh;
        $s .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="3" fill="#4ade80" opacity="0.85"/>';
        if ($v > 0) $s .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 4, 1) . '" fill="#8b93a1" font-size="9" text-anchor="middle">' . number_format($v, 0, ',', '.') . '</text>';
        $s .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 10) . '" fill="#8b93a1" font-size="10" text-anchor="middle">' . htmlspecialchars($labels[$i]) . '</text>';
    }
    return $s . '</svg>';
}
function svgLine(array $points, array $labels): string {
    $w = 720; $h = 170; $pad = 30; $n = count($points); $max = max(1, max($points));
    $stepX = $n > 1 ? ($w - $pad * 2) / ($n - 1) : 0;
    $coords = [];
    foreach ($points as $i => $v) { $x = $pad + $i * $stepX; $y = $h - 30 - ($v / $max) * ($h - 55); $coords[] = [round($x, 1), round($y, 1)]; }
    $poly = implode(' ', array_map(static fn($c) => $c[0] . ',' . $c[1], $coords));
    $s = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%">';
    $s .= '<polyline points="' . $poly . '" fill="none" stroke="#5aa9ff" stroke-width="2"/>';
    foreach ($coords as $i => $c) {
        $s .= '<circle cx="' . $c[0] . '" cy="' . $c[1] . '" r="3" fill="#5aa9ff"/>';
        if ($i % 2 === 0 || $i === $n - 1) $s .= '<text x="' . $c[0] . '" y="' . ($h - 12) . '" fill="#8b93a1" font-size="9" text-anchor="middle">' . htmlspecialchars($labels[$i]) . '</text>';
    }
    return $s . '</svg>';
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inadimplentes — da empresa</title>
<style>
  :root{--bg:#0f1115;--card:#171a21;--line:#262b35;--tx:#dbe2ec;--mut:#8b93a1;--green:#4ade80;--red:#ff6b6b;--orange:#ffb454;--yellow:#e8d44d;--blue:#5aa9ff}
  *{box-sizing:border-box}
  body{background:var(--bg);color:var(--tx);font:14px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif;margin:0;padding:24px 20px}
  a{color:var(--blue);text-decoration:none} a:hover{text-decoration:underline}
  .wrap{max-width:1320px;margin:0 auto}
  .hd{display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px}
  .hd h1{font-size:21px;margin:0}
  .hd .sub{color:var(--mut);font-size:12px}
  .sec-title{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--mut);margin:22px 2px 10px}

  .kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
  .box{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px}
  .box .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--mut)}
  .box b{display:block;font-size:22px;margin-top:3px;font-variant-numeric:tabular-nums}
  .box .sub{font-size:11px;color:var(--mut);margin-top:2px}
  .box.rec b{color:var(--green)}
  .up{color:var(--green)} .down{color:var(--red)}
  .meter{height:7px;background:#20242d;border-radius:6px;margin-top:8px;overflow:hidden}
  .meter i{display:block;height:100%;background:var(--green)}

  .charts{display:grid;grid-template-columns:minmax(280px,1fr) minmax(320px,1.4fr);gap:12px}
  @media(max-width:820px){.charts{grid-template-columns:1fr}}
  .chartcard{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px}
  .chartcard h3{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.4px;margin:0 0 8px;font-weight:600}

  .fbar{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
  .f label{display:block;font-size:11px;color:var(--mut);margin-bottom:4px}
  .f select,.f input{background:var(--bg);border:1px solid var(--line);color:var(--tx);border-radius:8px;padding:8px 10px;font-size:13px}
  .f input[type=number]{width:80px} .f input[type=text]{width:190px} select[multiple]{min-width:180px;height:64px}
  .btn{background:#2b3245;border:1px solid #3d4557;color:#fff;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer}
  .btn:hover{background:#343c52}
  .chips{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}
  .chip{font-size:12px;padding:5px 11px;border-radius:20px;border:1px solid var(--line);background:var(--card);color:var(--mut);cursor:pointer}
  .chip:hover{border-color:#4a5468;text-decoration:none;color:var(--tx)}
  .chip.on{background:#2b3245;color:#fff;border-color:#3d4557;font-weight:600}
  .hint{color:var(--mut);font-size:11px;margin:6px 2px 14px}
  .exp{display:flex;gap:6px;align-items:center;margin-left:auto}
  .exp a{font-size:12px;padding:6px 11px;border-radius:8px;border:1px solid var(--line);color:var(--mut)}
  .exp a:hover{border-color:#4a5468;color:var(--tx);text-decoration:none}

  .tabs{display:inline-flex;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:4px;gap:4px;margin:14px 0}
  .tabs a{padding:7px 16px;border-radius:7px;color:var(--mut);font-size:13px}
  .tabs a:hover{text-decoration:none;color:var(--tx)}
  .tabs a.on{background:#2b3245;color:#fff;font-weight:600}

  details.bd{background:var(--card);border:1px solid var(--line);border-radius:12px;margin-bottom:12px}
  details.bd>summary{cursor:pointer;padding:12px 16px;font-weight:600;font-size:13px;list-style:none}
  details.bd>summary::-webkit-details-marker{display:none}
  details.bd>summary:after{content:'▸';float:right;color:var(--mut)}
  details.bd[open]>summary:after{content:'▾'}
  details.bd .inner{padding:0 16px 14px}

  .tblwrap{background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:auto}
  table{width:100%;border-collapse:collapse;font-size:13px}
  .tblwrap table{min-width:1040px}
  th{position:sticky;top:0;background:var(--card);z-index:1;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--mut);text-align:left;padding:10px 12px;border-bottom:1px solid var(--line)}
  td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:top}
  tbody tr:hover{background:#1b1f28}
  td.money,th.money{text-align:right}
  .money strong{font-variant-numeric:tabular-nums;white-space:nowrap}
  .muted{color:var(--mut);font-size:11px}
  .cliente b{font-size:13px}
  .contatos{margin-top:3px;display:flex;gap:10px;font-size:11px}

  .pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
  .pill.r{background:#3b1519;color:var(--red)} .pill.o{background:#3a2a12;color:var(--orange)}
  .pill.y{background:#39360f;color:var(--yellow)} .pill.g{background:#20242d;color:var(--mut)}
  .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px}
  .dot.r{background:var(--red)} .dot.o{background:var(--orange)} .dot.y{background:var(--yellow)} .dot.g{background:var(--green)}
  .badge{display:inline-block;font-size:10px;padding:2px 8px;border-radius:10px;background:#2b3245;color:#aab6cc}
  .badge.grn{background:#14321f;color:var(--green)}

  .acts{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
  .abtn{display:inline-flex;align-items:center;gap:5px;background:#20242d;border:1px solid var(--line);color:#c6cfdc;font-size:12px;border-radius:8px;padding:6px 10px;cursor:pointer;line-height:1.2}
  .abtn:hover{border-color:#4a5468}
  .abtn.on{background:#14321f;border-color:#1f7a44;color:var(--green)}
  details.pop{display:inline-block}
  details.pop>summary{list-style:none;display:inline-flex} details.pop>summary::-webkit-details-marker{display:none}
  .popbox{margin-top:8px;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:10px;width:min(360px,80vw)}
  .popbox input,.popbox select,.popbox textarea{width:100%;background:var(--card);border:1px solid var(--line);color:var(--tx);border-radius:6px;padding:6px 8px;font:12px inherit;margin-bottom:6px}
  .popbox textarea{height:48px;resize:vertical}
  .hist{margin-top:6px;font-size:11px;color:var(--mut);max-width:340px}
  .hist .h{padding:4px 0;border-top:1px dashed var(--line)}
  .next{display:inline-block;font-size:11px;color:var(--yellow);margin-top:4px}
  .vazio{text-align:center;padding:36px;color:var(--mut)}
  .rk{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid var(--line);font-size:13px}

  .grp-header{background:#1b1f28}
  .grp-header td{padding:9px 12px;font-size:13px}
  .grp-toggle{display:inline-block;width:12px;color:var(--mut)}
  tr.grp-child td.cliente{padding-left:24px}
</style>
</head>
<body>
<div class="wrap">
  <div class="hd">
    <h1>💸 Inadimplentes</h1>
    <div class="sub">Asaas + Eduzz · <?= Security::e($currentUser) ?> · atualizado <?= Security::e($ultimaSync['s'] ?? '—') ?></div>
  </div>

  <!-- ===== KPIs ===== -->
  <div class="sec-title">Visão geral</div>
  <div class="kpi">
    <div class="box">
      <span class="lbl">Em atraso (aberto)</span>
      <b><?= brl($kOpen['v']) ?></b>
      <span class="sub"><?= (int)$kOpen['c'] ?> cobrança(s)</span>
    </div>
    <div class="box rec">
      <span class="lbl">Recuperado no período</span>
      <b><?= brl($kRec['v']) ?></b>
      <span class="sub"><?= (int)$kRec['c'] ?> · <?= Security::e(date('d/m', strtotime($recDe))) ?>–<?= Security::e(date('d/m', strtotime($recAte))) ?></span>
    </div>
    <div class="box">
      <span class="lbl">Recuperado na semana</span>
      <b><?= brl($recWeek) ?></b>
      <span class="sub"><?php if ($varWeek >= 0): ?><span class="up">▲ +<?= number_format($varWeek, 1, ',', '.') ?>%</span><?php else: ?><span class="down">▼ <?= number_format($varWeek, 1, ',', '.') ?>%</span><?php endif; ?> vs semana anterior</span>
    </div>
    <div class="box">
      <span class="lbl">Taxa de recuperação</span>
      <b><?= number_format($taxa, 1, ',', '.') ?>%</b>
      <span class="sub">recuperado ÷ (recuperado + em atraso)</span>
    </div>
    <div class="box">
      <span class="lbl">Meta do mês</span>
      <b><?= number_format($metaPct, 1, ',', '.') ?>%</b>
      <span class="sub"><?= brl($recMonth) ?> de <?= brl($meta) ?></span>
      <div class="meter"><i style="width:<?= min(100, $metaPct) ?>%;<?= $metaPct >= 100 ? 'background:var(--green)' : ($metaPct >= 60 ? '' : 'background:var(--orange)') ?>"></i></div>
    </div>
  </div>

  <!-- ===== Performance semanal (gráficos) ===== -->
  <div class="sec-title">Performance semanal</div>
  <div class="charts">
    <div class="chartcard">
      <h3>Recuperado por dia (semana atual)</h3>
      <?= svgBars($byDow, ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb']) ?>
    </div>
    <div class="chartcard">
      <h3>Evolução da recuperação — últimas 12 semanas</h3>
      <?= svgLine(array_map(static fn($e) => $e['v'], $evo), array_map(static fn($e) => $e['label'], $evo)) ?>
    </div>
  </div>

  <!-- ===== Filtros ===== -->
  <div class="sec-title">Filtros</div>
  <form method="get" class="fbar">
    <input type="hidden" name="ver" value="<?= Security::e($ver) ?>">
    <?php if ($qf !== ''): ?><input type="hidden" name="qf" value="<?= Security::e($qf) ?>"><?php endif; ?>
    <div class="f"><label>Empresa</label>
      <select name="empresa" onchange="this.form.submit()">
        <option value="0">Todas</option>
        <?php foreach ($empNome as $cid => $nm): ?><option value="<?= (int)$cid ?>" <?= $empresa === (int)$cid ? 'selected' : '' ?>><?= Security::e($nm) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="f"><label>Fonte</label>
      <select name="fonte" onchange="this.form.submit()">
        <option value="">Todas</option>
        <option value="asaas" <?= $fonte === 'asaas' ? 'selected' : '' ?>>Asaas</option>
        <option value="eduzz" <?= $fonte === 'eduzz' ? 'selected' : '' ?>>Eduzz</option>
      </select>
    </div>
    <div class="f"><label>Cursos (segure Ctrl p/ vários)</label>
      <select name="produtos[]" multiple>
        <?php foreach ($prodOpts as $po): ?><option value="<?= Security::e($po['p']) ?>" <?= in_array($po['p'], $produtos, true) ? 'selected' : '' ?>><?= Security::e(mb_substr($po['p'], 0, 38)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="f"><label>Ordenar</label>
      <select name="ord" onchange="this.form.submit()">
        <option value="recentes" <?= $ord === 'recentes' ? 'selected' : '' ?>>Mais recentes</option>
        <option value="atraso" <?= $ord === 'atraso' ? 'selected' : '' ?>>Mais atrasados</option>
        <option value="valor" <?= $ord === 'valor' ? 'selected' : '' ?>>Maior valor</option>
        <option value="prioridade" <?= $ord === 'prioridade' ? 'selected' : '' ?>>Prioridade</option>
      </select>
    </div>
    <div class="f"><label>Atraso mín.</label><input type="number" name="dias" value="<?= $diasMin ?: '' ?>" placeholder="30"></div>
    <div class="f"><label>De</label><input type="date" name="de" value="<?= Security::e($de) ?>"></div>
    <div class="f"><label>Até</label><input type="date" name="ate" value="<?= Security::e($ate) ?>"></div>
    <div class="f"><label>Busca (nome/CPF/e-mail/WhatsApp)</label><input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="qualquer dado"></div>
    <button class="btn" type="submit">Aplicar</button>
    <?php if ($temFiltro): ?><a class="btn" style="background:transparent" href="/admin/inadimplentes.php<?= $ver === 'recuperado' ? '?ver=recuperado' : '' ?>">Limpar</a><?php endif; ?>
  </form>

  <!-- filtros rápidos -->
  <div class="chips">
    <?php
    $quick = ['nao_contatado'=>'Nunca contatado','contatado_hoje'=>'Contatado hoje','agendados'=>'Com retorno agendado',
              'rec_hoje'=>'Recuperados hoje','rec_semana'=>'Recuperados na semana',
              'v500'=>'+ R$500','v1000'=>'+ R$1.000','d30'=>'+30 dias','d60'=>'+60 dias','d90'=>'+90 dias'];
    foreach ($quick as $k => $lbl):
        $on = $qf === $k; ?>
      <a class="chip <?= $on ? 'on' : '' ?>" href="<?= $on ? Security::e(qs(['qf'=>null])) ?: '/admin/inadimplentes.php' : Security::e(qs(['qf'=>$k])) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
    <div class="exp">
      <span class="muted">Exportar:</span>
      <a href="<?= Security::e(qs(['export'=>'csv'])) ?>">CSV</a>
      <a href="<?= Security::e(qs(['export'=>'excel'])) ?>">Excel</a>
      <a href="<?= Security::e(qs(['export'=>'pdf'])) ?>" target="_blank">PDF</a>
    </div>
  </div>
  <div class="hint">O período (De/Até) filtra o <strong>vencimento</strong> na aba Em atraso e a <strong>data da recuperação</strong> nos recuperados (sem datas = mês atual).</div>

  <!-- ===== Seções recolhíveis: produtividade, meta, ranking, por curso ===== -->
  <details class="bd">
    <summary>📈 Produtividade &amp; Meta</summary>
    <div class="inner">
      <div class="kpi" style="margin-bottom:12px">
        <div class="box"><span class="lbl">Contatos hoje</span><b><?= $prod['contatos_hoje'] ?></b></div>
        <div class="box"><span class="lbl">Recuperações hoje</span><b><?= $prod['rec_hoje'] ?></b></div>
        <div class="box"><span class="lbl">Recuperações na semana</span><b><?= $prod['rec_semana'] ?></b></div>
        <div class="box rec"><span class="lbl">Valor recuperado no mês</span><b><?= brl($prod['val_mes']) ?></b></div>
      </div>
      <form method="post" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="_t" value="<?= Security::e($tok) ?>"><input type="hidden" name="acao" value="meta">
        <label class="muted">Meta mensal (R$):</label>
        <input class="f" style="background:var(--bg);border:1px solid var(--line);color:var(--tx);border-radius:8px;padding:7px 9px;width:140px" type="text" name="meta" value="<?= number_format($meta, 2, ',', '.') ?>">
        <button class="btn" type="submit">Salvar meta</button>
      </form>
    </div>
  </details>

  <?php if ($ranking): ?>
  <details class="bd">
    <summary>🏆 Ranking de recuperação (no período) — por colaborador</summary>
    <div class="inner">
      <?php $rkMax = max(array_map(static fn($r) => (float)$r['v'], $ranking)); foreach ($ranking as $i => $r): ?>
        <div class="rk">
          <span><strong><?= $i + 1 ?>.</strong> <?= Security::e($r['u']) ?> · <span class="muted"><?= (int)$r['c'] ?> recuperação(ões)</span></span>
          <strong style="color:var(--green)"><?= brl($r['v']) ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>

  <details class="bd">
    <summary>📊 Por produto / curso (em atraso × recuperado × taxa)</summary>
    <div class="inner tblwrap" style="border:0">
      <table style="min-width:600px">
        <thead><tr><th>Produto</th><th class="money">Em atraso</th><th class="money">Qtd</th><th class="money">Recuperado</th><th class="money">Taxa</th></tr></thead>
        <tbody>
          <?php foreach ($breakdown as $pName => $bd):
            $tx = ($bd['v'] + $bd['rec']) > 0 ? $bd['rec'] / ($bd['v'] + $bd['rec']) * 100 : 0; ?>
            <tr>
              <td><a href="<?= Security::e(qs(['produtos'=>[$pName]])) ?>"><?= Security::e($pName) ?></a></td>
              <td class="money"><strong><?= brl($bd['v']) ?></strong></td>
              <td class="money"><?= (int)$bd['c'] ?></td>
              <td class="money" style="color:var(--green)"><?= $bd['rec'] > 0 ? brl($bd['rec']) : '—' ?></td>
              <td class="money"><?= number_format($tx, 0, ',', '.') ?>%</td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$breakdown): ?><tr><td colspan="5" class="muted">Sem dados.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </details>

  <!-- ===== Abas + tabela ===== -->
  <div class="tabs">
    <a href="<?= Security::e(qs(['ver' => null])) ?: '/admin/inadimplentes.php' ?>" class="<?= $ver === 'aberto' ? 'on' : '' ?>">⏰ Em atraso · <?= (int)$kOpen['c'] ?></a>
    <a href="<?= Security::e(qs(['ver' => 'recuperado'])) ?>" class="<?= $ver === 'recuperado' ? 'on' : '' ?>">✅ Recuperados · <?= (int)$kRec['c'] ?></a>
  </div>

  <?php if ($foiTruncado): ?>
  <div class="hint" style="background:#3a2a12;color:var(--orange);border-radius:8px;padding:10px 14px;margin-bottom:10px">
    ⚠️ Mostrando as <?= count($rows) ?> mais urgentes de <strong><?= $totalReal ?></strong> no total — a lista completa é grande
    demais pra carregar de uma vez só. Use os filtros (empresa, produto, atraso mínimo, busca) pra refinar e ver o restante.
  </div>
  <?php endif; ?>

  <div class="tblwrap">
  <table>
    <thead><tr>
      <th style="width:22%">Cliente</th>
      <th style="width:20%">Produto / cobrança</th>
      <th>Origem</th>
      <th class="money">Valor</th>
      <th>Vencimento / atraso</th>
      <?php if ($ver === 'aberto'): ?><th>Prioridade</th><th style="width:28%">Ações &amp; contato</th>
      <?php else: ?><th>Recuperado</th><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($groups as $gk => $g):
        $grows = $g['rows'];
        $multi = count($grows) > 1;
        if ($multi):
            $gSum = 0.0; $gMaxDias = 0;
            foreach ($grows as $gr) {
                $gSum += (float)($ver === 'aberto' ? ($gr['value'] ?? 0) : ($gr['recovered_value'] ?? 0));
                $gMaxDias = max($gMaxDias, (int)($gr['days_overdue'] ?? 0));
            }
            $gCa = corAtraso($gMaxDias);
    ?>
      <tr class="grp-header">
        <td colspan="7">
          <span class="grp-toggle">▾</span> <b><?= Security::e($g['name']) ?></b>
          <span class="muted"><?= Security::e($g['cpf']) ?></span>
          — <?= count($grows) ?> cobranças · <strong><?= brl($gSum) ?></strong>
          <?php if ($ver === 'aberto'): ?><span class="pill <?= $gCa ?>" style="margin-left:6px"><?= $gMaxDias ?> dias (pior caso)</span><?php endif; ?>
        </td>
      </tr>
    <?php endif; ?>
    <?php foreach ($grows as $r):
        $dias = (int)($r['days_overdue'] ?? 0);
        $fone = preg_replace('/\D+/', '', (string)($r['customer_phone'] ?? ''));
        $desc = (string)($r['description'] ?? '');
        $mostraDesc = $desc !== '' && $desc !== (string)($r['product_name'] ?? '');
        [$prCls, $prLbl] = prioridade((float)($r['value'] ?? 0), $dias);
        [$chCls, $chLbl] = chanceRec($dias);
        $ca = corAtraso($dias);
        $hist = $contatos[(int)$r['id']] ?? [];
    ?>
      <tr<?= $multi ? ' class="grp-child"' : '' ?>>
        <td class="cliente">
          <b><?= Security::e($r['customer_name'] ?? '—') ?></b>
          <div class="muted"><?= Security::e($r['customer_cpfcnpj'] ?? '') ?></div>
          <div class="contatos">
            <?php if (!empty($r['customer_email'])): ?><a href="mailto:<?= Security::e($r['customer_email']) ?>">✉️</a><?php endif; ?>
            <?php if ($fone !== ''): ?><a href="https://wa.me/55<?= Security::e($fone) ?>" target="_blank">📱 WhatsApp</a><?php endif; ?>
          </div>
          <?php if ($ver === 'aberto' && !empty($r['contacted_at'])): ?><div class="muted">último contato: <?= tempoDesde((string)$r['contacted_at']) ?><?= $r['contacted_by'] ? ' · ' . Security::e($r['contacted_by']) : '' ?></div><?php endif; ?>
        </td>
        <td>
          <?= Security::e($r['product_name'] ?? '(sem produto)') ?>
          <?php if (!empty($r['installment'])): ?><div class="muted"><?= Security::e($r['installment']) ?></div><?php endif; ?>
          <?php if ($mostraDesc): ?><div class="muted"><?= Security::e(mb_substr($desc, 0, 44)) ?></div><?php endif; ?>
          <?php if (!empty($r['invoice_url'])): ?><div class="muted"><a href="<?= Security::e($r['invoice_url']) ?>" target="_blank">abrir cobrança ↗</a></div>
          <?php else: ?><div class="muted">sem link · ID <?= Security::e($r['asaas_payment_id'] ?? '') ?></div><?php endif; ?>
        </td>
        <td><?= Security::e($empNome[(int)$r['company_id']] ?? ('#' . $r['company_id'])) ?>
          <div style="margin-top:3px"><span class="badge"><?= ($r['source'] ?? '') === 'eduzz' ? 'Eduzz' : 'Asaas' ?></span></div>
        </td>
        <td class="money"><strong><?= brl($r['value'] ?? 0) ?></strong></td>
        <td>
          <?= $r['due_date'] ? Security::e(date('d/m/Y', strtotime((string)$r['due_date']))) : '—' ?>
          <?php if ($ver === 'aberto'): ?><div style="margin-top:4px"><span class="pill <?= $ca ?>"><?= $dias ?> dias</span></div>
            <div class="muted" style="margin-top:3px"><span class="dot <?= $chCls ?>"></span>recup.: <?= mb_substr($chLbl, 2) ?></div>
          <?php endif; ?>
        </td>

        <?php if ($ver === 'aberto'): ?>
        <td><span class="pill <?= $prCls ?>"><?= $prLbl ?></span></td>
        <td>
          <div class="acts">
            <form method="post" style="display:inline"><input type="hidden" name="_t" value="<?= Security::e($tok) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="abtn <?= !empty($r['contacted_at']) ? 'on' : '' ?>" type="submit" name="acao" value="contatar" title="marcar/desmarcar contato rápido">✓ <?= !empty($r['contacted_at']) ? 'contatado' : 'contatado' ?></button>
            </form>
            <form method="post" style="display:inline"><input type="hidden" name="_t" value="<?= Security::e($tok) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="abtn" type="submit" name="acao" value="recuperar" onclick="return confirm('Marcar como RECUPERADO (pago por fora / acordo)?')">💰 recuperado</button>
            </form>
            <details class="pop">
              <summary><span class="abtn">📇 registrar contato<?= $hist ? ' (' . count($hist) . ')' : '' ?></span></summary>
              <form method="post" class="popbox">
                <input type="hidden" name="_t" value="<?= Security::e($tok) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="acao" value="contato">
                <select name="channel"><option value="">Canal…</option><option value="whatsapp">WhatsApp</option><option value="ligacao">Ligação</option><option value="email">E-mail</option><option value="outro">Outro</option></select>
                <input type="text" name="status_neg" placeholder="Status (ex.: prometeu pagar, sem resposta, acordo)">
                <textarea name="summary" placeholder="Resumo da conversa…"></textarea>
                <label class="muted">Retorno agendado:</label>
                <input type="datetime-local" name="next_action_at">
                <input type="text" name="next_note" placeholder="ex.: retornar após o dia do pagamento">
                <button class="abtn" type="submit">salvar contato</button>
              </form>
            </details>
          </div>
          <?php if (!empty($r['next_action_at'])): ?><div class="next">⏭ retornar <?= Security::e(date('d/m H:i', strtotime((string)$r['next_action_at']))) ?><?= $r['next_action_note'] ? ' — ' . Security::e($r['next_action_note']) : '' ?></div><?php endif; ?>
          <?php if ($hist): ?>
            <div class="hist">
              <?php foreach (array_slice($hist, 0, 3) as $h): ?>
                <div class="h"><?= Security::e(date('d/m H:i', strtotime((string)$h['created_at']))) ?> · <?= Security::e($h['channel'] ?: '—') ?><?= $h['created_by'] ? ' · ' . Security::e($h['created_by']) : '' ?><?php if ($h['status_negociacao']): ?> · <em><?= Security::e($h['status_negociacao']) ?></em><?php endif; ?><?php if ($h['summary']): ?><br><?= Security::e(mb_substr((string)$h['summary'], 0, 90)) ?><?php endif; ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </td>

        <?php else: ?>
        <td>
          <span style="color:var(--green);font-weight:700"><?= $r['resolved_at'] ? Security::e(date('d/m/Y', strtotime((string)$r['resolved_at']))) : '—' ?></span>
          <div class="muted"><?= brl($r['recovered_value'] ?? 0) ?><?= $r['resolved_by'] ? ' · ' . Security::e($r['resolved_by']) : '' ?></div>
          <div style="margin-top:3px"><span class="badge <?= $r['resolved_status'] === 'pago_manual' ? '' : 'grn' ?>"><?= $r['resolved_status'] === 'pago_manual' ? 'manual' : 'detectado' ?></span></div>
        </td>
        <td>
          <form method="post"><input type="hidden" name="_t" value="<?= Security::e($tok) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="abtn" type="submit" name="acao" value="reabrir" onclick="return confirm('Reabrir esta cobrança como inadimplente?')">↩ reabrir</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; // $grows ?>
    <?php endforeach; // $groups ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="vazio"><?= $ver === 'aberto' ? 'Nenhum inadimplente com esses filtros 🎉' : 'Nenhuma recuperação no período.' ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($ver === 'aberto' && count($rows) >= 1): ?>
  <div style="margin-top:12px;text-align:right">
    <button class="btn" id="prox" type="button">▶ Próximo cliente</button>
  </div>
  <script>
    // "Próximo cliente": rola/destaca a próxima linha ainda não tratada
    (function(){
      var i = -1, rows = Array.prototype.slice.call(document.querySelectorAll('tbody tr:not(.grp-header)'));
      document.getElementById('prox').addEventListener('click', function(){
        if (i >= 0 && rows[i]) rows[i].style.outline='';
        i = (i + 1) % rows.length;
        var tr = rows[i];
        if (tr) { tr.scrollIntoView({behavior:'smooth',block:'center'}); tr.style.outline='2px solid var(--blue)'; }
      });
    })();
  </script>
  <?php endif; ?>
</div>
</body>
</html>
