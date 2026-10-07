<?php
declare(strict_types=1);

/**
 * Endpoint público que recebe os webhooks do Asaas.
 * Regras:
 *  1. Sempre grava o payload bruto em webhook_logs.
 *  2. Valida o header asaas-access-token.
 *  3. Responde 200 rapidamente — NÃO chama Notazz.
 *  4. Enfileira NF-e e NFS-e em notazz_documents (status=pending).
 */

require __DIR__ . '/app/Bootstrap.php';
\App\Bootstrap::init();

use App\Bootstrap;
use App\Database;
use App\Helpers\Logger;
use App\Helpers\Security;
use App\Services\ProductMatcher;
use App\Repositories\PaymentRepo;
use App\Repositories\DocumentRepo;
use App\Repositories\ProductRepo;

// ---- 1) Capturar requisição ----
$rawBody    = file_get_contents('php://input') ?: '';
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$remoteIp   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
$headers    = function_exists('getallheaders') ? getallheaders() : [];
$accessToken = $headers['asaas-access-token'] ?? $headers['Asaas-Access-Token'] ?? $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';

// Cabeçalhos sanitizados para log (mascarando o token)
$logHeaders = $headers;
foreach ($logHeaders as $k => $v) {
    if (stripos($k, 'token') !== false || stripos($k, 'auth') !== false) {
        $logHeaders[$k] = '***REDACTED***';
    }
}

// ---- 2) Insere log cru imediatamente ----
$webhookLogId = Database::insert('webhook_logs', [
    'remote_ip'    => $remoteIp,
    'method'       => $method,
    'headers'      => json_encode($logHeaders, JSON_UNESCAPED_UNICODE),
    'raw_body'     => $rawBody,
    'token_valid'  => 0,
    'json_valid'   => 0,
    'event'        => null,
    'asaas_payment_id' => null,
    'response_code' => null,
    'response_body' => null,
]);

$respond = function (int $code, array $body) use ($webhookLogId): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    Database::update('webhook_logs', [
        'response_code' => $code,
        'response_body' => $json,
    ], 'id = ?', [$webhookLogId]);
    echo $json;
    exit;
};

// ---- 3) Método deve ser POST ----
if ($method !== 'POST') {
    $respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// ---- 4) Valida token ----
// Aceita múltiplos tokens (multi-conta Asaas: cada conta tem seu segredo de webhook).
// 'webhook_token' (string, conta Educação) + 'webhook_tokens' (array, demais contas).
$validTokens = (array)Bootstrap::config('asaas.webhook_tokens', []);
$single = (string)Bootstrap::config('asaas.webhook_token', '');
if ($single !== '') { $validTokens[] = $single; }
$tokenOk = false;
foreach ($validTokens as $vt) {
    if ((string)$vt !== '' && hash_equals((string)$vt, (string)$accessToken)) { $tokenOk = true; break; }
}
if (!$tokenOk) {
    Logger::warning('webhook', 'Token inválido', ['ip' => $remoteIp], null, null);
    $respond(401, ['ok' => false, 'error' => 'invalid_token']);
}
Database::update('webhook_logs', ['token_valid' => 1], 'id = ?', [$webhookLogId]);

// ---- 5) Decodifica JSON ----
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    Logger::error('webhook', 'JSON inválido');
    $respond(400, ['ok' => false, 'error' => 'invalid_json']);
}
Database::update('webhook_logs', ['json_valid' => 1], 'id = ?', [$webhookLogId]);

$event   = (string)($data['event'] ?? '');
$payment = $data['payment'] ?? null;
Database::update('webhook_logs', [
    'event' => $event,
    'asaas_payment_id' => is_array($payment) ? ($payment['id'] ?? null) : null,
], 'id = ?', [$webhookLogId]);

if (!is_array($payment) || empty($payment['id'])) {
    Logger::warning('webhook', 'Sem objeto payment', ['event' => $event]);
    $respond(200, ['ok' => true, 'enqueued' => false, 'reason' => 'no_payment']);
}

$paymentId = (string)$payment['id'];

// ---- 6) Filtra eventos que NÃO disparam emissão ----
$triggerEvents = (array)Bootstrap::config('asaas.trigger_events', ['PAYMENT_CONFIRMED']);
if (!in_array($event, $triggerEvents, true)) {
    Logger::info('webhook', "Evento {$event} não dispara emissão", null, $paymentId);
    $respond(200, ['ok' => true, 'enqueued' => false, 'reason' => 'event_not_trigger']);
}

// ---- 6.1) Regra fina por billingType ----
// Cartão de crédito (À VISTA e PARCELADO): emite no PAYMENT_CONFIRMED.
//   - À vista: 1 nota na confirmação.
//   - Parcelado: o Asaas confirma TODAS as parcelas no dia da compra (1 evento CONFIRMED
//     por parcela, cada uma com seu vencimento/dueDate). Aceitamos cada parcela e AGENDAMOS
//     a emissão para o vencimento mensal dela (ver IssueSchedule). O RECEIVED posterior de
//     cada parcela (~32 dias depois) é ignorado pois já foi agendado — sem duplicar.
// PIX/Boleto: emite no PAYMENT_RECEIVED (quando efetivamente pago, à vista ou por parcela).
$billingType = (string)($payment['billingType'] ?? '_default');

// Detecta se é parcela de um parcelamento (usado adiante para o agendamento por vencimento).
$isInstallment = !empty($payment['installment'])
    || (isset($payment['installmentNumber']) && $payment['installmentNumber'] !== null && $payment['installmentNumber'] !== '');

$mapByBilling = (array)Bootstrap::config('asaas.trigger_event_by_billing_type', []);
$expectedEvent = $mapByBilling[$billingType] ?? ($mapByBilling['_default'] ?? null);

if ($expectedEvent !== null && $event !== $expectedEvent) {
    // RESGATE: se chega um evento de pagamento LIQUIDADO (RECEIVED/RECEIVED_IN_CASH)
    // de uma cobrança que ainda NÃO virou documento, EMITE em vez de ignorar —
    // recupera o CONFIRMED perdido (ex.: sistema indisponível no dia da compra do
    // cartão parcelado; depois só chega o RECEIVED mensal). Só ignora se já existe
    // documento (aí é o RECEIVED normal pós-CONFIRMED, e ignorar evita duplicar).
    $settledEvents = ['PAYMENT_RECEIVED', 'PAYMENT_RECEIVED_IN_CASH'];
    $temDoc = (bool)Database::one('SELECT 1 FROM notazz_documents WHERE asaas_payment_id = ? LIMIT 1', [$paymentId]);
    $resgatar = in_array($event, $settledEvents, true) && !$temDoc;
    if (!$resgatar) {
        Logger::info(
            'webhook',
            "Ignorando {$event} para billingType={$billingType} (parcelado=" . ($isInstallment ? 'sim' : 'nao') . ", esperado: {$expectedEvent})",
            null,
            $paymentId
        );
        $respond(200, [
            'ok' => true,
            'enqueued' => false,
            'reason' => 'wrong_event_for_billing_type',
            'billing_type' => $billingType,
            'is_installment' => $isInstallment,
            'expected_event' => $expectedEvent,
        ]);
    }
    Logger::info('webhook', "RESGATE: {$event} de {$billingType} sem documento — emitindo (CONFIRMED provavelmente perdido)", null, $paymentId);
}

// ---- 7) Deduplicação a nível de payment+event ----
// _force_emit (backfill) FURA a dedup para reprocessar pagamentos que foram
// registrados mas nunca viraram nota (ex.: produto inativo/sem match na época).
$forceEmit = !empty($data['_force_emit']);
$existingPayment = PaymentRepo::findByAsaasIdAndEvent($paymentId, $event);
if ($existingPayment && !$forceEmit) {
    Logger::info('webhook', 'Pagamento já registrado, ignorando duplicata', null, $paymentId);
    $respond(200, ['ok' => true, 'enqueued' => false, 'reason' => 'duplicate']);
}

// ---- 8) Extrai dados do cliente (vem aninhado em alguns webhooks) ----
$customer = $payment['customer'] ?? null;
$customerData = is_array($customer) ? $customer : [];
$cpfCnpj = Security::onlyDigits((string)($customerData['cpfCnpj'] ?? ''));
$personType = strlen($cpfCnpj) === 14 ? 'J' : (strlen($cpfCnpj) === 11 ? 'F' : null);

$description = (string)($payment['description'] ?? '');

// ---- 9) Tenta dar match com produto ----
// Override manual (_force_product_id): usado no backfill por nome quando a
// description não permite casar sozinho (ex.: vazia — "compra sem descrição").
// O financeiro já identificou o produto correto; pula o ProductMatcher, mas
// o restante do pipeline (fiscal, agendamento, validação) roda igual.
$forceProductId = isset($data['_force_product_id']) ? (int)$data['_force_product_id'] : 0;
$matched = $forceProductId > 0 ? ProductRepo::find($forceProductId) : ProductMatcher::match($description);
$matchedId = $matched['id'] ?? null;

// ---- 10) Persistir asaas_payments (ou reusar a linha existente no --force_emit) ----
if ($existingPayment) {
    $paymentRowId = (int)$existingPayment['id'];
    PaymentRepo::setMatchedProduct($paymentRowId, $matchedId); // produto pode ter sido ativado depois
} else {
$paymentRowId = PaymentRepo::create([
    'asaas_payment_id'      => $paymentId,
    'asaas_customer_id'     => $payment['customer'] ?? null && is_string($payment['customer']) ? $payment['customer'] : ($customerData['id'] ?? null),
    'asaas_subscription_id' => $payment['subscription'] ?? null,
    'asaas_installment_id'  => $payment['installment'] ?? null,
    'event'                 => $event,
    'status'                => $payment['status'] ?? null,
    'billing_type'          => $payment['billingType'] ?? null,
    'description'           => mb_substr($description, 0, 500),
    'value'                 => isset($payment['value']) ? (float)$payment['value'] : null,
    'net_value'             => isset($payment['netValue']) ? (float)$payment['netValue'] : null,
    'due_date'              => $payment['dueDate'] ?? null,
    'payment_date'          => $payment['paymentDate'] ?? null,
    'installment_number'    => $payment['installmentNumber'] ?? null,
    'installment_count'     => $payment['installmentCount'] ?? null,
    'customer_name'         => $customerData['name'] ?? null,
    'customer_cpfcnpj'      => $cpfCnpj ?: null,
    'customer_person_type'  => $personType,
    'customer_email'        => $customerData['email'] ?? null,
    'customer_phone'        => $customerData['phone'] ?? ($customerData['mobilePhone'] ?? null),
    'customer_address'      => $customerData['address'] ?? null,
    'customer_address_number'=> $customerData['addressNumber'] ?? null,
    'customer_complement'   => $customerData['complement'] ?? null,
    'customer_province'     => $customerData['province'] ?? null,
    'customer_postal_code'  => $customerData['postalCode'] ?? null,
    'customer_city'         => $customerData['city'] ?? null,
    'customer_state'        => $customerData['state'] ?? null,
    'raw_payload'           => $rawBody,
    'matched_product_id'    => $matchedId,
]);
}

// ---- 11) Decide o que enfileirar ----
if (!$matched) {
    Logger::warning('webhook', 'Produto não identificado pela description', ['description' => $description], $paymentId);
    $respond(200, ['ok' => true, 'enqueued' => false, 'reason' => 'product_not_matched']);
}
if ((int)$matched['active'] !== 1 || (int)$matched['ignored'] === 1) {
    Logger::info('webhook', "Produto {$matched['name']} está inativo/ignorado", null, $paymentId);
    $respond(200, ['ok' => true, 'enqueued' => false, 'reason' => 'product_ignored']);
}

// ---- 10.1) TRAVA ANTI-LEGADO ----
// Só emite automaticamente se o recebimento/crédito for recente. Pagamentos
// antigos (eventos reenviados em massa, parcelas creditadas há muito tempo) entram
// como 'manual' para revisão — não são emitidos sozinhos. Assim, parcelas que caem
// mês a mês geram nota normalmente, mas o histórico não é despejado de uma vez.
$legacyWindow = (int)Bootstrap::config('queue.legacy_window_days', 10);
// Backfill controlado (ex.: cron/backfill-mentorias.php) pode pedir emissão direta,
// ignorando a trava anti-legado — usado para escoar o mês com o token válido.
$forceEmit = !empty($data['_force_emit']);
$docStatus = 'pending';
$isLegacy  = false;
if ($legacyWindow > 0 && !$forceEmit) {
    $refDateStr = $payment['creditDate']
        ?? $payment['paymentDate']
        ?? $payment['confirmedDate']
        ?? $payment['clientPaymentDate']
        ?? $payment['dueDate']
        ?? null;
    if ($refDateStr) {
        try {
            $refDate = new DateTime(substr((string)$refDateStr, 0, 10));
            $today   = new DateTime('today');
            $ageDays = (int)$today->diff($refDate)->format('%a');
            if ($refDate < $today && $ageDays > $legacyWindow) {
                $isLegacy  = true;
                $docStatus = 'manual';
                Logger::warning('webhook', "Pagamento antigo ({$ageDays} dias) marcado p/ revisão manual (não emite automático)", ['ref_date' => substr((string)$refDateStr,0,10)], $paymentId);
            }
        } catch (\Throwable $e) {
            // Data não parseável: trata como recente (não trava).
        }
    }
}

$enqueued = [];
$companyId = (int)($matched['company_id'] ?? 1); // empresa do produto define o CNPJ emissor

// NF-e: sempre que houver notazz_product_id
if (!empty($matched['notazz_product_id'])) {
    if (!DocumentRepo::exists($paymentId, 'nfe')) {
        $id = DocumentRepo::createPending($paymentId, $paymentRowId, (int)$matched['id'], 'nfe', $docStatus, $companyId);
        $enqueued[] = ['nfe' => $id];
    }
}

// NFS-e: somente se houver notazz_service_id
if (!empty($matched['notazz_service_id'])) {
    if (!DocumentRepo::exists($paymentId, 'nfse')) {
        $id = DocumentRepo::createPending($paymentId, $paymentRowId, (int)$matched['id'], 'nfse', $docStatus, $companyId);
        $enqueued[] = ['nfse' => $id];
    }
}

$reason = $isLegacy ? 'legacy_manual_review' : 'enqueued';
Logger::info('webhook', "Registrado: " . count($enqueued) . " documento(s) [" . ($isLegacy ? 'REVISÃO MANUAL' : 'fila normal') . "]", ['matched' => $matched['name']], $paymentId);
$respond(200, ['ok' => true, 'enqueued' => $enqueued, 'status' => $docStatus, 'is_legacy' => $isLegacy, 'matched' => $matched['name']]);
