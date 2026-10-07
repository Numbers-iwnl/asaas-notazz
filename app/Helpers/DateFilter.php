<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Filtro de data reutilizável para as listagens do painel.
 * Lê ?de=YYYY-MM-DD e ?ate=YYYY-MM-DD do request, valida e
 * gera a cláusula SQL + bindings para uma coluna de data/hora.
 */
final class DateFilter
{
    /** Retorna [from, to] já validados (ou null). */
    public static function fromRequest(): array
    {
        $from = (string)($_GET['de'] ?? '');
        $to   = (string)($_GET['ate'] ?? '');
        $valid = static fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        return [$valid($from), $valid($to)];
    }

    /**
     * Gera fragmento SQL (" AND col >= ? AND col <= ?") e bindings.
     */
    public static function clause(string $column, ?string $from, ?string $to): array
    {
        $sql = '';
        $bind = [];
        if ($from) { $sql .= " AND {$column} >= ?"; $bind[] = $from . ' 00:00:00'; }
        if ($to)   { $sql .= " AND {$column} <= ?"; $bind[] = $to   . ' 23:59:59'; }
        return [$sql, $bind];
    }

    /** Atalhos de período retornando [de, ate]. */
    public static function presets(): array
    {
        $today = date('Y-m-d');
        return [
            'hoje'     => ['label' => 'Hoje',         'de' => $today,                          'ate' => $today],
            '7d'       => ['label' => '7 dias',       'de' => date('Y-m-d', strtotime('-6 days')),  'ate' => $today],
            '30d'      => ['label' => '30 dias',      'de' => date('Y-m-d', strtotime('-29 days')), 'ate' => $today],
            'mes'      => ['label' => 'Este mês',     'de' => date('Y-m-01'),                  'ate' => date('Y-m-t')],
            'mes_ant'  => ['label' => 'Mês passado',  'de' => date('Y-m-01', strtotime('first day of last month')), 'ate' => date('Y-m-t', strtotime('last day of last month'))],
        ];
    }
}
