<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Repositories\CompanyRepo;
use App\Helpers\Security;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::csrfCheck((string)($_POST['_csrf'] ?? ''))) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Sessão expirada, tente de novo.'];
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && CompanyRepo::find($id)) {
            $data = [
                'name'          => trim((string)($_POST['name'] ?? '')),
                'trade_name'    => trim((string)($_POST['trade_name'] ?? '')) ?: null,
                'cnpj'          => preg_replace('/\D+/', '', (string)($_POST['cnpj'] ?? '')) ?: null,
                'municipal_reg' => trim((string)($_POST['municipal_reg'] ?? '')) ?: null,
                'tax_regime'    => trim((string)($_POST['tax_regime'] ?? '')) ?: null,
                'addr_street'   => trim((string)($_POST['addr_street'] ?? '')) ?: null,
                'addr_number'   => trim((string)($_POST['addr_number'] ?? '')) ?: null,
                'addr_complement'=> trim((string)($_POST['addr_complement'] ?? '')) ?: null,
                'addr_district' => trim((string)($_POST['addr_district'] ?? '')) ?: null,
                'addr_city'     => trim((string)($_POST['addr_city'] ?? '')) ?: null,
                'addr_state'    => strtoupper(trim((string)($_POST['addr_state'] ?? ''))) ?: null,
                'addr_zipcode'  => preg_replace('/\D+/', '', (string)($_POST['addr_zipcode'] ?? '')) ?: null,
                'city_ibge'     => trim((string)($_POST['city_ibge'] ?? '')) ?: null,
                'nfe_percent'   => max(0, min(100, (int)($_POST['nfe_percent'] ?? 50))),
                'auto_emission_delay_days' => max(0, (int)($_POST['delay_days'] ?? 8)),
                'active'        => isset($_POST['active']) ? 1 : 0,
            ];
            // API key: só substitui se o campo foi preenchido (evita apagar sem querer;
            // o valor atual nunca é exibido por segurança).
            $newKey = trim((string)($_POST['notazz_api_key'] ?? ''));
            if ($newKey !== '') {
                $data['notazz_api_key'] = $newKey;
            }
            CompanyRepo::save($id, $data);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Empresa atualizada.'];
        }
    }
    header('Location: /admin/empresas.php'); exit;
}

$rows = CompanyRepo::all();
$csrf = Security::csrfToken();
$pageTitle = 'Empresas';
ob_start();
?>
<div class="alert info mb-24">
  <strong>Multi-empresa:</strong> cada produto pertence a uma empresa; a nota é emitida na conta Notazz da empresa do produto.
  A <strong>API key</strong> nunca é exibida — o campo mostra apenas se está cadastrada. Para trocar, digite a nova chave e salve.
  Empresa sem API key usa a chave padrão do config.php (caso da Empresa 1 (Educação)).
</div>

<?php foreach ($rows as $c): ?>
<form method="post" class="card" style="margin-bottom:20px">
  <input type="hidden" name="_csrf" value="<?= Security::e($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <div class="flex-between mb-12">
    <div class="label" style="font-size:14px">#<?= (int)$c['id'] ?> — <?= Security::e($c['name']) ?></div>
    <label style="text-transform:none;margin:0"><input type="checkbox" name="active" <?= $c['active'] ? 'checked' : '' ?>> Ativa</label>
  </div>
  <div class="grid-3">
    <div class="form-row"><label>Razão social</label><input type="text" name="name" value="<?= Security::e($c['name']) ?>" required></div>
    <div class="form-row"><label>Nome fantasia</label><input type="text" name="trade_name" value="<?= Security::e($c['trade_name'] ?? '') ?>"></div>
    <div class="form-row"><label>CNPJ</label><input type="text" name="cnpj" value="<?= Security::e($c['cnpj'] ?? '') ?>"></div>
    <div class="form-row"><label>Inscrição municipal</label><input type="text" name="municipal_reg" value="<?= Security::e($c['municipal_reg'] ?? '') ?>"></div>
    <div class="form-row"><label>Regime tributário</label><input type="text" name="tax_regime" value="<?= Security::e($c['tax_regime'] ?? '') ?>"></div>
    <div class="form-row">
      <label>API key Notazz — <?= !empty($c['notazz_api_key']) ? '<span style="color:var(--green)">cadastrada ✓</span>' : '<span style="color:var(--yellow)">não cadastrada (usa config.php)</span>' ?></label>
      <input type="password" name="notazz_api_key" value="" placeholder="colar nova chave para trocar" autocomplete="new-password">
    </div>
  </div>
  <div class="grid-3">
    <div class="form-row"><label>Rua</label><input type="text" name="addr_street" value="<?= Security::e($c['addr_street'] ?? '') ?>"></div>
    <div class="form-row"><label>Número</label><input type="text" name="addr_number" value="<?= Security::e($c['addr_number'] ?? '') ?>"></div>
    <div class="form-row"><label>Complemento</label><input type="text" name="addr_complement" value="<?= Security::e($c['addr_complement'] ?? '') ?>"></div>
    <div class="form-row"><label>Bairro</label><input type="text" name="addr_district" value="<?= Security::e($c['addr_district'] ?? '') ?>"></div>
    <div class="form-row"><label>Cidade</label><input type="text" name="addr_city" value="<?= Security::e($c['addr_city'] ?? '') ?>"></div>
    <div class="form-row"><label>UF</label><input type="text" name="addr_state" maxlength="2" value="<?= Security::e($c['addr_state'] ?? '') ?>"></div>
    <div class="form-row"><label>CEP</label><input type="text" name="addr_zipcode" value="<?= Security::e($c['addr_zipcode'] ?? '') ?>"></div>
    <div class="form-row"><label>Código IBGE</label><input type="text" name="city_ibge" value="<?= Security::e($c['city_ibge'] ?? '') ?>"></div>
    <div class="form-row"><label>% NF-e (resto = NFS-e)</label><input type="number" name="nfe_percent" min="0" max="100" value="<?= (int)$c['nfe_percent'] ?>"></div>
    <div class="form-row"><label>Dias p/ envio à SEFAZ</label><input type="number" name="delay_days" min="0" value="<?= (int)$c['auto_emission_delay_days'] ?>"></div>
  </div>
  <button class="btn" type="submit">Salvar</button>
</form>
<?php endforeach; ?>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
