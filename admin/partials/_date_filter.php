<?php
/**
 * Partial de filtro por data.
 * Variáveis esperadas antes do include:
 *   $filterFrom, $filterTo  (string|null) — valores atuais
 *   $filterExtra (array)    — outros params GET a preservar (ex: ['q'=>'x','level'=>'error'])
 */
use App\Helpers\DateFilter;
use App\Helpers\Security;

$filterExtra = $filterExtra ?? [];
$presets = DateFilter::presets();
$script = basename($_SERVER['SCRIPT_NAME']);

$buildQs = function (array $params) use ($filterExtra): string {
    $merged = array_filter(array_merge($filterExtra, $params), fn($v) => $v !== null && $v !== '');
    return http_build_query($merged);
};
?>
<form method="get" class="date-filter">
  <?php foreach ($filterExtra as $k => $v): ?>
    <input type="hidden" name="<?= Security::e((string)$k) ?>" value="<?= Security::e((string)$v) ?>">
  <?php endforeach; ?>
  <span class="df-label">De</span>
  <input type="date" name="de" value="<?= Security::e($filterFrom ?? '') ?>">
  <span class="df-label">até</span>
  <input type="date" name="ate" value="<?= Security::e($filterTo ?? '') ?>">
  <button class="btn btn-sm" type="submit">Filtrar</button>
  <?php if ($filterFrom || $filterTo): ?>
    <a class="btn ghost btn-sm" href="/admin/<?= $script ?><?= $buildQs([]) ? '?'.$buildQs([]) : '' ?>">Limpar</a>
  <?php endif; ?>
  <span class="df-presets">
    <?php foreach ($presets as $p): ?>
      <a class="df-chip" href="/admin/<?= $script ?>?<?= $buildQs(['de'=>$p['de'],'ate'=>$p['ate']]) ?>"><?= Security::e($p['label']) ?></a>
    <?php endforeach; ?>
  </span>
</form>
