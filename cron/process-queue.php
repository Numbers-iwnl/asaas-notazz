<?php
declare(strict_types=1);

/**
 * Script de cron — roda a cada 1 minuto.
 * Pega N documentos pendentes e tenta emitir na Notazz.
 *
 * Crontab Hostinger sugerido:
 *  * * * * * /usr/bin/php /home/USER/public_html/cron/process-queue.php >> /home/USER/public_html/storage/logs/cron.log 2>&1
 */

require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();

$lockFile = \App\Bootstrap::config('paths.storage') . '/queue.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[cron] já em execução, saindo.\n");
    exit(0);
}

$start = microtime(true);
try {
    $queue = new \App\Services\EmissionQueue();
    $r = $queue->processBatch();
    $s = $queue->checkPendingStatus();
    $duration = round((microtime(true) - $start) * 1000);
    echo sprintf(
        "[%s] processed=%d sent=%d errors=%d ignored=%d checked=%d updated=%d (%dms)\n",
        date('Y-m-d H:i:s'),
        $r['processed'], $r['sent'], $r['errors'], $r['ignored'],
        $s['checked'], $s['updated'], $duration
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[cron] erro: ' . $e->getMessage() . "\n");
    error_log('[cron] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
