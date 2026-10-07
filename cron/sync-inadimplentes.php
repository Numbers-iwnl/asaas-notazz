<?php
declare(strict_types=1);

/**
 * Sincroniza os INADIMPLENTES (pagamentos vencidos e não pagos) das contas
 * Asaas de cada empresa para a tabela `inadimplentes`. Roda algumas vezes ao dia.
 *
 * Para cada empresa ativa:
 *   - GET /payments?status=OVERDUE (paginado)
 *   - resolve nome/contato do cliente (GET /customers/{id}, com cache)
 *   - casa o PRODUTO pela descrição (ProductMatcher) p/ agrupar no painel
 *   - UPSERT (preserva "contacted_at"/"notes"; reabre se voltou a vencer)
 *   - quem SAIU da lista de vencidos NÃO é apagado: consulta o pagamento e
 *     marca a resolução -> pago (RECUPERADO, guarda valor/data), cancelado
 *     ou renegociado. É daí que sai o "valor recuperado no período".
 *
 * Uso (SSH / cron):
 *   php cron/sync-inadimplentes.php
 */

require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();

use App\Bootstrap;
use App\Database;
use App\Repositories\CompanyRepo;
use App\Services\ProductMatcher;

$base = rtrim((string)Bootstrap::config('asaas.api_base'), '/');

function asaasGet(string $url, string $token): array {
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40,
            CURLOPT_USERAGENT      => 'AsaasNotazz/1.0 (+github.com/Numbers-iwnl/asaas-notazz)',
            CURLOPT_HTTPHEADER     => ['access_token: ' . $token, 'Accept: application/json'],
        ]);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200) { $j = json_decode($raw, true); return is_array($j) ? $j : []; }
        if ($code === 404) { return ['_notfound' => true]; }
        usleep(400000);
    }
    return [];
}

function eduzzHttp(string $method, string $url, array $headers = [], $body = null): array {
    $h = array_merge(['Accept: application/json', 'User-Agent: AsaasNotazz/1.0 (+github.com/Numbers-iwnl/asaas-notazz)'], $headers);
    $last = ['code' => 0, 'json' => null, 'raw' => ''];
    // Retry: a API da Eduzz derruba conexões esporadicamente (HTTP 0) e pode dar 429/5xx.
    for ($try = 1; $try <= 4; $try++) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $h,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : $body;
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $last = ['code' => $code, 'json' => json_decode($raw, true), 'raw' => $raw];
        if ($code > 0 && $code !== 429 && $code < 500) {
            return $last;                    // resposta definitiva (2xx/4xx)
        }
        sleep($try * 5);                     // 5s, 10s, 15s — a Eduzz corta conexões em rajada
    }
    return $last;
}

$PAGOS      = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];
$CANCELADOS = ['REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL', 'DELETED', 'CANCELED'];

$today = new DateTime('today');
$totalGeral = 0; $recuperados = 0;

foreach (CompanyRepo::all(true) as $company) {
    $companyId = (int)$company['id'];
    $token = $companyId === 1
        ? (string)Bootstrap::config('asaas.api_token')
        : (string)($company['asaas_api_token'] ?? '');
    if ($token === '') { echo "Empresa {$companyId} sem token Asaas — pulando.\n"; continue; }

    $custCache = [];
    $seen = [];
    $offset = 0; $limit = 100; $count = 0;

    do {
        $url = $base . "/payments?status=OVERDUE&limit={$limit}&offset={$offset}";
        $page = asaasGet($url, $token);
        $data = $page['data'] ?? [];
        $hasMore = (bool)($page['hasMore'] ?? false);

        foreach ($data as $pay) {
            $pid = (string)($pay['id'] ?? '');
            if ($pid === '') continue;
            $seen[] = $pid;
            $count++;

            // Cliente (cache por id)
            $custId = is_string($pay['customer'] ?? null) ? $pay['customer'] : ($pay['customer']['id'] ?? null);
            $cust = [];
            if ($custId) {
                if (!array_key_exists($custId, $custCache)) {
                    $custCache[$custId] = asaasGet($base . '/customers/' . rawurlencode((string)$custId), $token);
                    usleep(150000);
                }
                $cust = $custCache[$custId] ?: [];
            }

            $due = substr((string)($pay['dueDate'] ?? ''), 0, 10);
            $daysOverdue = null;
            if ($due) {
                try { $daysOverdue = (int)$today->diff(new DateTime($due))->format('%r%a'); $daysOverdue = -$daysOverdue; } catch (\Throwable $e) {}
            }
            $installment = (isset($pay['installmentNumber'], $pay['installmentCount']))
                ? ('Parcela ' . $pay['installmentNumber'] . ' de ' . $pay['installmentCount']) : null;

            // Produto (p/ agrupar por curso no painel)
            $matched = ProductMatcher::match((string)($pay['description'] ?? ''));

            Database::run(
                "INSERT INTO inadimplentes
                   (company_id, source, asaas_payment_id, asaas_customer_id, customer_name, customer_cpfcnpj,
                    customer_email, customer_phone, description, product_name, fiscal_group, value, due_date,
                    days_overdue, billing_type, installment, invoice_url, status)
                 VALUES (?, 'asaas', ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    company_id=VALUES(company_id), asaas_customer_id=VALUES(asaas_customer_id),
                    customer_name=VALUES(customer_name), customer_cpfcnpj=VALUES(customer_cpfcnpj),
                    customer_email=VALUES(customer_email), customer_phone=VALUES(customer_phone),
                    description=VALUES(description), product_name=VALUES(product_name),
                    fiscal_group=VALUES(fiscal_group), value=VALUES(value), due_date=VALUES(due_date),
                    days_overdue=VALUES(days_overdue), billing_type=VALUES(billing_type),
                    installment=VALUES(installment), invoice_url=VALUES(invoice_url),
                    status=VALUES(status), last_synced_at=NOW(),
                    -- Voltou a estar vencido: reabre (a menos que o financeiro tenha
                    -- marcado 'recuperado manual' — recebido por fora, respeita).
                    resolved_at     = IF(resolved_status='pago_manual', resolved_at, NULL),
                    recovered_value = IF(resolved_status='pago_manual', recovered_value, NULL),
                    resolved_status = IF(resolved_status='pago_manual', resolved_status, NULL)",
                [
                    $companyId, $pid, $custId,
                    $cust['name'] ?? null,
                    preg_replace('/\D+/', '', (string)($cust['cpfCnpj'] ?? '')) ?: null,
                    $cust['email'] ?? null,
                    $cust['phone'] ?? ($cust['mobilePhone'] ?? null),
                    mb_substr((string)($pay['description'] ?? ''), 0, 500),
                    $matched['name'] ?? null,
                    $matched['fiscal_group'] ?? null,
                    isset($pay['value']) ? (float)$pay['value'] : null,
                    $due ?: null,
                    $daysOverdue,
                    $pay['billingType'] ?? null,
                    $installment,
                    $pay['invoiceUrl'] ?? null,
                    $pay['status'] ?? null,
                ]
            );
        }
        $offset += $limit;
    } while ($hasMore);

    // ---- Quem saiu da lista de vencidos: descobre o que aconteceu (pagou?) ----
    $params = [$companyId];
    $notIn = '';
    if ($seen) {
        $place = implode(',', array_fill(0, count($seen), '?'));
        $notIn = " AND asaas_payment_id NOT IN ({$place})";
        $params = array_merge($params, $seen);
    }
    $sairam = Database::all(
        "SELECT id, asaas_payment_id, value FROM inadimplentes
         WHERE source='asaas' AND company_id=? AND resolved_at IS NULL{$notIn}",
        $params
    );

    foreach ($sairam as $row) {
        $pay = asaasGet($base . '/payments/' . rawurlencode((string)$row['asaas_payment_id']), $token);
        usleep(150000);
        $st = (string)($pay['status'] ?? '');
        $deleted = !empty($pay['deleted']) || !empty($pay['_notfound']);

        if (in_array($st, $PAGOS, true)) {
            // RECUPERADO — pagou depois de vencido
            $when = substr((string)($pay['paymentDate'] ?? $pay['clientPaymentDate'] ?? ''), 0, 10);
            Database::update('inadimplentes', [
                'resolved_at'     => ($when ? $when . ' 12:00:00' : date('Y-m-d H:i:s')),
                'resolved_status' => 'pago',
                'recovered_value' => isset($pay['value']) ? (float)$pay['value'] : (float)$row['value'],
                'status'          => $st ?: 'RECEIVED',
            ], 'id = ?', [$row['id']]);
            $recuperados++;
        } elseif ($deleted || in_array($st, $CANCELADOS, true)) {
            Database::update('inadimplentes', [
                'resolved_at'     => date('Y-m-d H:i:s'),
                'resolved_status' => 'cancelado',
                'recovered_value' => null,
                'status'          => $deleted ? 'DELETED' : $st,
            ], 'id = ?', [$row['id']]);
        } else {
            // Ainda existe mas não está vencido (vencimento adiado/acordo)
            Database::update('inadimplentes', [
                'resolved_at'     => date('Y-m-d H:i:s'),
                'resolved_status' => 'renegociado',
                'recovered_value' => null,
                'status'          => $st ?: null,
            ], 'id = ?', [$row['id']]);
        }
    }

    echo "Empresa {$companyId} ({$company['name']}): {$count} vencido(s), " . count($sairam) . " saíram da lista.\n";
    $totalGeral += $count;
}

/* ================= EDUZZ — vendas vencidas e não pagas =================
 * Conta produtora Eduzz (da empresa). Inadimplente =
 * status 'expired' (vencida) ou 'recovering' (em recuperação, não paga).
 * Quem sai dessa lista é consultado: paid -> RECUPERADO; canceled/refunded
 * -> cancelado; outros -> renegociado. Fonte 'eduzz', empresa 1 no painel. */
$edId  = (string)Bootstrap::config('eduzz.client_id', '');
$edSec = (string)Bootstrap::config('eduzz.client_secret', '');
if ($edId !== '' && $edSec !== '') {
    $r = eduzzHttp('POST', 'https://accounts-api.eduzz.com/oauth/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        ['grant_type' => 'client_credentials', 'client_id' => $edId, 'client_secret' => $edSec]);
    $edTok = $r['json']['access_token'] ?? null;
    if (!$edTok) {
        echo "Eduzz: falha ao obter token (HTTP {$r['code']}) — pulando.\n";
    } else {
        $H = ['Authorization: Bearer ' . $edTok];
        $edCompany = 1;                                                  // exibida como Educação (selo "eduzz")
        $edSince   = (string)Bootstrap::config('eduzz.since', '2020-01-01');
        $fim       = date('Y-m-d');
        $seenEd    = []; $countEd = 0; $edIncompleto = false; $keptDue = [];

        // Busca FATIADA POR ANO: paginação profunda (página 16+ num range de
        // 6 anos) estoura o tempo da Eduzz e derruba a conexão (HTTP 0).
        // Janelas anuais mantêm as páginas rasas e rápidas.
        $anoIni = (int)substr($edSince, 0, 4);
        $anoFim = (int)date('Y');

        // ---- INADIMPLÊNCIA REAL = parcela de CONTRATO vencida e não paga ----
        // Régua validada (jul/2026) contra o export "Minhas Vendas" da Eduzz + o
        // suporte (contract.status=late): fatura COM contractId e status
        // open / waitingPayment / negotiated, filtrando por referenceDate=dueDate
        // (vencidas até hoje). Boleto AVULSO (sem contrato) fica de fora — é
        // Expirada / Em Recuperação = matrícula abandonada, NÃO inadimplência.
        // (Sem passada de "pagos": o contractId já separa compromisso real.)
        $hojeStr = date('Y-m-d');
        foreach (['open', 'waitingPayment', 'negotiated'] as $stFiltro) {
          $pulaStatus = false;
          for ($ano = $anoIni; $ano <= $anoFim && !$edIncompleto && !$pulaStatus; $ano++) {
            $iniJan = ("{$ano}-01-01" < $edSince) ? $edSince : "{$ano}-01-01";
            $fimJan = ("{$ano}-12-31" > $fim) ? $fim : "{$ano}-12-31";
            $page = 1; $pages = 1;
            do {
                $url = "https://api.eduzz.com/myeduzz/v1/sales?referenceDate=dueDate&startDate={$iniJan}&endDate={$fimJan}&status={$stFiltro}&page={$page}&itemsPerPage=100";
                $r = eduzzHttp('GET', $url, $H);
                if ($r['code'] === 400 || $r['code'] === 422) { echo "Eduzz status '{$stFiltro}': não suportado pela API (HTTP {$r['code']}), pulando.\n"; $pulaStatus = true; break; }
                if ($r['code'] !== 200) { echo "Eduzz {$stFiltro} {$ano} p{$page}: HTTP {$r['code']} (varredura incompleta)\n"; $edIncompleto = true; break; }
                $items = $r['json']['items'] ?? [];
                $pages = max(1, (int)($r['json']['pages'] ?? 1));

                foreach ($items as $it) {
                    $sid = (string)($it['id'] ?? '');
                    if ($sid === '') continue;
                    // SÓ contrato (assinatura/parcelamento) = compromisso real.
                    // Sem contractId = boleto avulso (matrícula abandonada) → fora.
                    $cid = $it['contractId'] ?? null;
                    if ($cid === null || $cid === '') continue;
                    $due = substr((string)($it['dueDate'] ?? ''), 0, 10);
                    if ($due === '' || $due > $hojeStr) continue;   // só vencidas (referenceDate já filtra; redundância segura)
                    // deduplica parcelas repetidas do mesmo contrato+vencimento
                    $dk = (string)$cid . '|' . $due;
                    if (isset($keptDue[$dk])) continue;
                    $keptDue[$dk] = true;
                    $seenEd[] = $sid; $countEd++;

                    $daysOverdue = null;
                    try { $daysOverdue = (int)$today->diff(new DateTime($due))->format('%r%a'); $daysOverdue = -$daysOverdue; } catch (\Throwable $e) {}
                    $buyer = is_array($it['buyer'] ?? null) ? $it['buyer'] : [];
                    $fone = preg_replace('/\D+/', '', (string)($buyer['phone'] ?? ''));
                    if (strncmp($fone, '55', 2) === 0 && strlen($fone) > 11) $fone = substr($fone, 2); // tira DDI (painel já põe 55)
                    $prodName = $it['product']['name'] ?? null;

                    Database::run(
                        "INSERT INTO inadimplentes
                           (company_id, source, asaas_payment_id, asaas_customer_id, customer_name, customer_cpfcnpj,
                            customer_email, customer_phone, description, product_name, fiscal_group, value, due_date,
                            days_overdue, billing_type, installment, invoice_url, status)
                         VALUES (?, 'eduzz', ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            customer_name=VALUES(customer_name), customer_cpfcnpj=VALUES(customer_cpfcnpj),
                            customer_email=VALUES(customer_email), customer_phone=VALUES(customer_phone),
                            description=VALUES(description), product_name=VALUES(product_name),
                            value=VALUES(value), due_date=VALUES(due_date), days_overdue=VALUES(days_overdue),
                            billing_type=VALUES(billing_type), installment=VALUES(installment),
                            invoice_url=VALUES(invoice_url), status=VALUES(status), last_synced_at=NOW(),
                            resolved_at     = IF(resolved_status='pago_manual', resolved_at, NULL),
                            recovered_value = IF(resolved_status='pago_manual', recovered_value, NULL),
                            resolved_status = IF(resolved_status='pago_manual', resolved_status, NULL)",
                        [
                            $edCompany, $sid,
                            isset($buyer['id']) ? (string)$buyer['id'] : null,
                            $buyer['name'] ?? null,
                            preg_replace('/\D+/', '', (string)($buyer['document'] ?? '')) ?: null,
                            $buyer['email'] ?? null,
                            $fone ?: null,
                            mb_substr((string)($prodName ?? ''), 0, 500),
                            $prodName ? mb_substr((string)$prodName, 0, 120) : null,
                            null,
                            isset($it['total']['value']) ? (float)$it['total']['value'] : null,
                            $due ?: null,
                            $daysOverdue,
                            $it['paymentMethod'] ?? null,
                            'Contrato ' . $cid,
                            $it['payment']['link'] ?? null,
                            $it['status'] ?? null,
                        ]
                    );
                }
                $page++;
                usleep(500000); // poucas páginas agora (só open/waitingPayment de contrato)
            } while ($page <= $pages && $items);
          } // ano
        }

        // LIMPEZA: o painel fica só com as parcelas de contrato em atraso desta
        // rodada. Tudo mais em aberto (boleto avulso, parcela que foi paga ou saiu
        // da lista) é REMOVIDO — preserva o que o financeiro marcou à mão
        // (pago_manual) e o histórico já resolvido (resolved_at).
        // Guardado por !edIncompleto: se a varredura falhou, não apaga nada.
        $removidos = 0;
        if (!$edIncompleto) {
            $params = []; $notIn = '';
            if ($seenEd) {
                $place = implode(',', array_fill(0, count($seenEd), '?'));
                $notIn = " AND asaas_payment_id NOT IN ({$place})";
                $params = $seenEd;
            }
            $removidos = Database::run(
                "DELETE FROM inadimplentes
                 WHERE source='eduzz' AND resolved_at IS NULL
                   AND (resolved_status IS NULL OR resolved_status <> 'pago_manual')
                   {$notIn}", $params
            )->rowCount();
        } else {
            echo "Eduzz: limpeza pulada nesta rodada (varredura incompleta).\n";
        }

        echo "Eduzz (WF Treinamentos): {$countEd} parcela(s) de contrato em atraso, {$removidos} removido(s).\n";
        $totalGeral += $countEd;
    }
}

echo "Total vencidos: {$totalGeral} | recuperados detectados nesta rodada: {$recuperados}\n";
