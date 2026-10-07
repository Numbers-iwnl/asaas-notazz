<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Repositories\ProductRepo;
use App\Helpers\Security;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::csrfCheck((string)($_POST['_csrf'] ?? ''))) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'CSRF inválido.'];
    } elseif (($_POST['action'] ?? '') === 'create') {
        $novo = [
            'company_id'        => (int)($_POST['company_id'] ?? 0),
            'name'              => trim((string)($_POST['name'] ?? '')),
            'keywords'          => trim((string)($_POST['keywords'] ?? '')),
            'fiscal_group'      => (string)($_POST['fiscal_group'] ?? ''),
            'notazz_service_id' => preg_replace('/\s+/', '', (string)($_POST['notazz_service_id'] ?? '')),
            'notazz_product_id' => preg_replace('/\s+/', '', (string)($_POST['notazz_product_id'] ?? '')),
            'nfe_name'          => trim((string)($_POST['nfe_name'] ?? '')),
        ];
        $trechos = array_values(array_filter(array_map('trim', explode(';', $novo['keywords'])), static fn($k) => $k !== ''));
        $novo['keywords'] = implode(';', $trechos);
        // Uma palavra solta ("mentoria", "ouro") casa com vendas de outros produtos e
        // emite a nota no produto/empresa errados — por isso cada trecho exige 2+ palavras.
        $soltas = array_filter($trechos, static fn($k) => count(preg_split('/\s+/', $k)) < 2);
        $erro = match (true) {
            !\App\Repositories\CompanyRepo::find($novo['company_id']) => 'Escolha a empresa que emite as notas.',
            $novo['name'] === ''          => 'Informe o nome do produto.',
            ProductRepo::nameExists($novo['name']) => 'Já existe um produto com esse nome — edite-o na tabela abaixo.',
            $novo['keywords'] === ''      => 'Informe a palavra-chave.',
            $soltas !== []                => 'Cada palavra-chave precisa ter 2 palavras ou mais, escritas juntas (ex.: mentoria ouro). O ; separa só trechos alternativos — "' . implode('", "', $soltas) . '" ficou com uma palavra só.',
            !in_array($novo['fiscal_group'], ['pos_grad','extensao','mentoria','outro'], true) => 'Escolha o tipo do produto.',
            // Sem nenhum código Notazz o produto casa mas não emite nada — o mesmo silêncio que se quer evitar.
            $novo['notazz_service_id'] === '' && $novo['notazz_product_id'] === '' => 'Informe o código do serviço na Notazz (ou do produto, se for livro). Sem ele nenhuma nota é emitida.',
            default => null,
        };
        if ($erro !== null) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>$erro];
            $_SESSION['novo_produto'] = $novo;
        } else {
            ProductRepo::create([
                'company_id'        => $novo['company_id'],
                'name'              => $novo['name'],
                'asaas_description' => $novo['name'],
                'keywords'          => $novo['keywords'],
                'fiscal_group'      => $novo['fiscal_group'],
                'notazz_service_id' => $novo['notazz_service_id'] ?: null,
                'notazz_product_id' => $novo['notazz_product_id'] ?: null,
                'nfe_name'          => $novo['nfe_name'] ?: null,
                'active'            => 1,
                'ignored'           => 0,
                'notes'             => 'Cadastrado pelo painel em ' . date('d/m/Y H:i'),
            ]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>"Produto \"{$novo['name']}\" cadastrado. As próximas vendas dele já geram nota."];
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && ProductRepo::find($id)) {
            ProductRepo::save($id, [
                'company_id'        => max(1, (int)($_POST['company_id'] ?? 1)),
                'name'              => trim((string)($_POST['name'] ?? '')),
                'nfe_name'          => trim((string)($_POST['nfe_name'] ?? '')) ?: null,
                'asaas_description' => trim((string)($_POST['asaas_description'] ?? '')),
                'keywords'          => trim((string)($_POST['keywords'] ?? '')),
                'notazz_product_id' => trim((string)($_POST['notazz_product_id'] ?? '')) ?: null,
                'notazz_service_id' => trim((string)($_POST['notazz_service_id'] ?? '')) ?: null,
                'fiscal_group'      => in_array($_POST['fiscal_group'] ?? '', ['pos_grad','extensao','mentoria','outro'], true) ? $_POST['fiscal_group'] : 'outro',
                'active'            => isset($_POST['active']) ? 1 : 0,
                'ignored'           => isset($_POST['ignored']) ? 1 : 0,
                'notes'             => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Produto atualizado.'];
        }
    }
    header('Location: /admin/produtos.php'); exit;
}

$rows = ProductRepo::all();
$companies = \App\Repositories\CompanyRepo::all();
$csrf = Security::csrfToken();
$pageTitle = 'Produtos / Regras fiscais';
ob_start();
?>
<div class="alert info mb-24">
  <strong>Como funciona:</strong> O sistema casa a <em>description</em> da cobrança Asaas com as <em>keywords</em> abaixo (separadas por <code>;</code>). O match mais longo vence. Se um produto não tiver <em>ID de serviço</em>, só será gerada NF-e — sem NFS-e.
</div>

<?php $np = $_SESSION['novo_produto'] ?? []; unset($_SESSION['novo_produto']); ?>
<details class="card mb-24" <?= $np ? 'open' : '' ?>>
  <summary style="cursor:pointer;font-weight:600">➕ Cadastrar produto novo</summary>
  <form method="post" style="margin-top:16px;max-width:640px">
    <input type="hidden" name="_csrf" value="<?= Security::e($csrf) ?>">
    <input type="hidden" name="action" value="create">
    <div class="grid-2">
      <div class="form-row">
        <label for="np-company">Empresa que emite a nota</label>
        <select id="np-company" name="company_id" required>
          <option value="">Escolha…</option>
          <?php foreach ($companies as $co): ?>
            <option value="<?= (int)$co['id'] ?>" <?= (int)($np['company_id'] ?? 0) === (int)$co['id'] ? 'selected' : '' ?>><?= Security::e($co['trade_name'] ?: $co['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label for="np-group">Tipo do produto</label>
        <select id="np-group" name="fiscal_group" required>
          <option value="">Escolha…</option>
          <?php foreach (['mentoria'=>'Mentoria','pos_grad'=>'Pós-graduação','extensao'=>'Curso de extensão','outro'=>'Outro'] as $g => $lbl): ?>
            <option value="<?= $g ?>" <?= ($np['fiscal_group'] ?? '') === $g ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <label for="np-name">Nome do produto (sai na nota fiscal)</label>
      <input type="text" id="np-name" name="name" required value="<?= Security::e($np['name'] ?? '') ?>" placeholder="ex.: Mentoria Ouro">
    </div>
    <div class="form-row">
      <label for="np-kw">Palavra-chave (trecho da descrição)</label>
      <input type="text" id="np-kw" name="keywords" required value="<?= Security::e($np['keywords'] ?? '') ?>" placeholder="ex.: mentoria ouro">
      <div class="muted" style="font-size:12px;margin-top:4px">Escreva o trecho <strong>inteiro</strong>, como aparece na descrição da cobrança no Asaas, com 2 palavras ou mais (ex.: <code>mentoria ouro</code>). Acento e maiúsculas não importam. O <code>;</code> serve só para cadastrar trechos alternativos (ex.: <code>mentoria ouro; mentoria medeiros</code>), nunca para separar as palavras de um trecho.</div>
    </div>
    <div class="form-row">
      <label for="np-service">Código do serviço na Notazz (nota de serviço)</label>
      <input type="text" id="np-service" name="notazz_service_id" inputmode="numeric" value="<?= Security::e($np['notazz_service_id'] ?? '') ?>" placeholder="ex.: 4">
    </div>
    <details style="margin-bottom:14px">
      <summary style="cursor:pointer" class="muted">Só para livros e e-books (nota de produto)</summary>
      <div class="grid-2" style="margin-top:10px">
        <div class="form-row">
          <label for="np-product">Código do produto na Notazz</label>
          <input type="text" id="np-product" name="notazz_product_id" inputmode="numeric" value="<?= Security::e($np['notazz_product_id'] ?? '') ?>">
        </div>
        <div class="form-row">
          <label for="np-nfe">Nome na nota de produto</label>
          <input type="text" id="np-nfe" name="nfe_name" value="<?= Security::e($np['nfe_name'] ?? '') ?>" placeholder="ex.: E-book Curso de Extensão">
        </div>
      </div>
    </details>
    <button class="btn" type="submit">Cadastrar produto</button>
  </form>
</details>

<table>
  <thead>
    <tr>
      <th>Empresa</th>
      <th>Nome NFS-e / Descrição Asaas</th>
      <th>Nome NF-e (produto)</th>
      <th>Keywords</th>
      <th>ID Produto Notazz</th>
      <th>ID Serviço Notazz</th>
      <th>Grupo</th>
      <th>Status</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $p): ?>
    <tr>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= Security::e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <td>
          <select name="company_id" title="Empresa que emite as notas deste produto">
            <?php foreach ($companies as $co): ?>
              <option value="<?= (int)$co['id'] ?>" <?= (int)($p['company_id'] ?? 1) === (int)$co['id'] ? 'selected' : '' ?>>
                <?= Security::e($co['trade_name'] ?: $co['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td style="min-width:240px">
          <input type="text" name="name" value="<?= Security::e($p['name']) ?>" required title="Nome usado na NFS-e e no painel">
          <input type="text" name="asaas_description" style="margin-top:4px;font-size:12px" value="<?= Security::e($p['asaas_description']) ?>" placeholder="descrição original Asaas">
        </td>
        <td style="min-width:240px"><input type="text" name="nfe_name" value="<?= Security::e($p['nfe_name'] ?? '') ?>" placeholder="ex: E-book Curso de Extensão" title="Nome usado na NF-e (produto)"></td>
        <td style="min-width:240px"><textarea name="keywords" rows="2"><?= Security::e($p['keywords']) ?></textarea></td>
        <td><input type="text" name="notazz_product_id" value="<?= Security::e($p['notazz_product_id'] ?? '') ?>" placeholder="vazio = não emite NF-e"></td>
        <td><input type="text" name="notazz_service_id" value="<?= Security::e($p['notazz_service_id'] ?? '') ?>" placeholder="vazio = não emite NFS-e"></td>
        <td>
          <select name="fiscal_group">
            <?php foreach (['pos_grad','extensao','mentoria','outro'] as $g): ?>
              <option value="<?= $g ?>" <?= $p['fiscal_group']===$g?'selected':'' ?>><?= $g ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="nowrap">
          <label style="text-transform:none;margin:0"><input type="checkbox" name="active" <?= $p['active']?'checked':'' ?>>Ativo</label><br>
          <label style="text-transform:none;margin:0"><input type="checkbox" name="ignored" <?= $p['ignored']?'checked':'' ?>>Ignorado</label>
        </td>
        <td><button class="btn btn-sm">Salvar</button></td>
      </form>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
