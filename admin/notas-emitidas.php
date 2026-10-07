<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Repositories\DocumentRepo;
use App\Helpers\DateFilter;

[$filterFrom, $filterTo] = DateFilter::fromRequest();
$companyId = \App\Helpers\CompanyContext::currentId();
$rows = DocumentRepo::listByStatus('sent', 500, $filterFrom, $filterTo, $companyId);
$pageTitle = 'Notas emitidas';
ob_start();
include __DIR__ . '/partials/_date_filter.php';
include __DIR__ . '/partials/_table_docs.php';
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
