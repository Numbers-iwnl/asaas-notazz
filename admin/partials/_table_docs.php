<?php
use App\Helpers\Security;
// Nomes das empresas para o selo da coluna Tipo (multi-empresa)
$tdEmpresas = [];
try { foreach (\App\Repositories\CompanyRepo::all() as $tdC) { $tdEmpresas[(int)$tdC['id']] = $tdC['trade_name'] ?: $tdC['name']; } } catch (\Throwable $e) {}
?>
<table>
  <thead><tr><th>#</th><th>Tipo</th><th>Cliente</th><th>Valor nota</th><th>Venda</th><th>Descrição</th><th>Status</th><th>Tent.</th><th>Nº NF</th><th>Atualizada</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td>
          <strong><?= strtoupper(Security::e($r['document_type'])) ?></strong>
          <?php if (count($tdEmpresas) > 1 && isset($r['company_id'])): ?>
            <div class="muted" style="font-size:10px"><?= Security::e($tdEmpresas[(int)$r['company_id']] ?? ('Empresa #' . (int)$r['company_id'])) ?></div>
          <?php endif; ?>
        </td>
        <td><?= Security::e($r['customer_name'] ?? '-') ?><div class="muted" style="font-size:11px"><?= Security::e($r['customer_cpfcnpj'] ?? '') ?></div></td>
        <td class="nowrap"><strong>R$ <?= number_format((float)($r['document_value'] ?? 0), 2, ',', '.') ?></strong></td>
        <td class="nowrap muted">R$ <?= number_format((float)($r['sale_value'] ?? 0), 2, ',', '.') ?></td>
        <td><?= Security::e(mb_substr($r['description'] ?? '', 0, 60)) ?></td>
        <td><span class="badge <?= Security::e($r['status']) ?>"><?= Security::e($r['status']) ?></span></td>
        <td class="center"><?= (int)$r['attempts'] ?></td>
        <td><?= Security::e($r['notazz_number'] ?? '-') ?></td>
        <td class="nowrap muted"><?= Security::e($r['updated_at']) ?></td>
        <td><a href="/admin/nota-detalhes.php?id=<?= (int)$r['id'] ?>" class="btn ghost btn-sm">Ver</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="center muted">Nada por aqui.</td></tr><?php endif; ?>
  </tbody>
</table>
