<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Repositories\CompanyRepo;

/**
 * Empresa selecionada no painel (seletor no topo).
 * Persistida na sessão; ?empresa=0 = todas as empresas.
 */
final class CompanyContext
{
    /** Retorna o id da empresa selecionada (null = todas). */
    public static function currentId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (isset($_GET['empresa'])) {
            $_SESSION['company_filter'] = max(0, (int)$_GET['empresa']);
        }
        $id = (int)($_SESSION['company_filter'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** Lista para o seletor do topo. */
    public static function options(): array
    {
        return CompanyRepo::all();
    }
}
