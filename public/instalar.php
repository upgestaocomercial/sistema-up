<?php
// Instalador do Sistema UP. Roda uma vez: cria as tabelas, grava a configuração,
// cria o primeiro usuário e se apaga. Escrito de forma simples para avisar
// quando a versão do PHP da hospedagem for antiga.

$base = null;
foreach (array(__DIR__ . '/../../sistema-up', __DIR__ . '/..') as $c) {
    if (is_file($c . '/src/rotas.php')) { $base = realpath($c); break; }
}
$jaInstalado = $base && is_file($base . '/config/config.php');

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$requisitos = array(
    array('PHP 8.1 ou mais novo', version_compare(PHP_VERSION, '8.1.0', '>='), 'Versão atual: ' . PHP_VERSION . '. No cPanel, abra "MultiPHP Manager", marque app.upgestaocomercial.com.br e escolha PHP 8.1 ou mais novo.'),
    array('Extensão pdo_mysql', extension_loaded('pdo_mysql'), 'Ative em cPanel → "Selecionar versão do PHP" → Extensões.'),
    array('Extensão mbstring', extension_loaded('mbstring'), 'Ative em cPanel → "Selecionar versão do PHP" → Extensões.'),
    array('Extensão zip (planilhas .xlsx)', class_exists('ZipArchive'), 'Ative em cPanel → "Selecionar versão do PHP" → Extensões.'),
    array('Extensões de XML (planilhas .xlsx)', class_exists('XMLReader') && function_exists('simplexml_load_string'), 'Ative xmlreader e simplexml em "Selecionar versão do PHP".'),
    array('Código do sistema em ~/sistema-up', $base !== null, 'Extraia o pacote na pasta inicial (home), não dentro de public_html.'),
    array('Pasta de configuração com permissão de gravação', $base && is_writable($base . '/config'), 'Ajuste a permissão de ~/sistema-up/config para 755.'),
    array('Pasta storage com permissão de gravação', $base && is_writable($base . '/storage'), 'Ajuste a permissão de ~/sistema-up/storage para 755.'),
    array('Conexão segura (HTTPS)', $https, 'Rode o AutoSSL no cPanel para app.upgestaocomercial.com.br. Dá para instalar antes, mas o login só funciona com HTTPS.'),
);
$obrigatoriosOk = true;
foreach ($requisitos as $i => $r) { if ($i !== 8 && !$r[1]) { $obrigatoriosOk = false; } }

$erro = '';
$feito = false;
$v = array(
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '',
    'nome' => '', 'email' => '',
);

if (!$jaInstalado && $obrigatoriosOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) { $v[$k] = trim(isset($_POST[$k]) ? (string) $_POST[$k] : ''); }
    $dbPass = isset($_POST['db_pass']) ? (string) $_POST['db_pass'] : '';
    $senha = isset($_POST['senha']) ? (string) $_POST['senha'] : '';
    $senha2 = isset($_POST['senha2']) ? (string) $_POST['senha2'] : '';
    $email = mb_strtolower($v['email']);

    if ($v['db_name'] === '' || $v['db_user'] === '') {
        $erro = 'Preencha o nome do banco e o usuário do banco.';
    } elseif ($v['nome'] === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Preencha o seu nome e um e-mail válido.';
    } elseif (mb_strlen($senha) < 8) {
        $erro = 'A sua senha de acesso precisa ter pelo menos 8 caracteres.';
    } elseif ($senha !== $senha2) {
        $erro = 'As duas senhas de acesso não são iguais.';
    } else {
        try {
            $pdo = new PDO('mysql:host=' . $v['db_host'] . ';dbname=' . $v['db_name'] . ';charset=utf8mb4', $v['db_user'], $dbPass, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ));
            $existe = $pdo->query("SHOW TABLES LIKE 'usuarios'")->fetchColumn();
            if ($existe) {
                throw new RuntimeException('Este banco já tem as tabelas do sistema. Use um banco vazio.');
            }
            $pdo->exec("SET time_zone = '+00:00'");
            foreach (array('schema.sql', 'seed.sql') as $arq) {
                $sql = file_get_contents($base . '/database/' . $arq);
                $sql = preg_replace('/(^|\s)--(\s[^\n]*)?$/m', '$1', $sql); // tira comentários
                foreach (preg_split('/;\s*\n/', $sql) as $cmd) {
                    if (trim($cmd) !== '') { $pdo->exec($cmd); }
                }
            }
            $st = $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil, acesso_financeiro) VALUES (?, ?, ?, 'gestao', 0)");
            $st->execute(array($v['nome'], $email, password_hash($senha, PASSWORD_DEFAULT)));

            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'app.upgestaocomercial.com.br';
            $config = array(
                'db' => array('host' => $v['db_host'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $dbPass, 'charset' => 'utf8mb4'),
                'app' => array('url' => 'https://' . $host, 'timezone' => 'America/Sao_Paulo', 'debug' => false, 'storage' => $base . '/storage', 'cookie_secure' => true),
                'sessao' => array('horas_navegador' => 12, 'dias_app' => 30, 'dias_tv' => 365),
                'smtp' => array('host' => '', 'port' => 465, 'user' => '', 'pass' => '', 'from' => 'sistema@upgestaocomercial.com.br', 'from_name' => 'UP Gestão Comercial'),
            );
            $php = "<?php\n// Gerado pelo instalador em " . gmdate('Y-m-d H:i') . " UTC. Não vai para o GitHub.\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($base . '/config/config.php', $php, LOCK_EX) === false) {
                throw new RuntimeException('Não consegui gravar ~/sistema-up/config/config.php. Confira a permissão da pasta.');
            }
            @chmod($base . '/config/config.php', 0600);
            $feito = true;
            @unlink(__FILE__); // o instalador se apaga
        } catch (PDOException $e) {
            $erro = 'Não consegui usar o banco: ' . $e->getMessage() . '. Confira nome do banco, usuário, senha e se o usuário tem todas as permissões no banco.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Instalar · Sistema UP</title>
<style>
  :root { --papel:#F2F4F7; --folha:#fff; --grafite:#1A1F27; --cinza:#4A5361; --linha:#D5DBE3; --carbono:#1D3C8F; --carimbo:#B42B26; --pago:#24704A; --aviso:#8A5A00; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--papel); color: var(--grafite); line-height:1.45; }
  main { max-width: 640px; margin: 32px auto; padding: 0 16px; display:grid; gap:16px; }
  .folha { background: var(--folha); border:1px solid var(--linha); border-radius:10px; padding:20px; }
  .topo { border-top: 6px solid var(--carbono); }
  h1 { margin:0; font-size:1.6rem; } h1 b { color: var(--carbono); }
  h2 { margin:0 0 10px; font-size:1.15rem; }
  ul { list-style:none; padding:0; margin:0; display:grid; gap:8px; }
  li { display:grid; grid-template-columns: 24px 1fr; gap:6px; }
  .ok { color: var(--pago); font-weight:700; } .nao { color: var(--carimbo); font-weight:700; } .talvez { color: var(--aviso); font-weight:700; }
  small { color: var(--cinza); display:block; }
  label { display:grid; gap:4px; font-size:.9rem; color: var(--cinza); }
  input { font: inherit; padding:10px 12px; border:1px solid #B5BFCC; border-radius:6px; min-height:44px; color: var(--grafite); }
  fieldset { border:0; padding:0; margin:0 0 18px; display:grid; gap:12px; }
  legend { font-weight:600; font-size:1.05rem; margin-bottom:8px; color: var(--grafite); }
  button, .botao { font: inherit; font-weight:600; background: var(--carbono); color:#fff; border:0; border-radius:6px; min-height:44px; padding:0 20px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
  .erro { color: var(--carimbo); background:#FBEAE9; border-left:3px solid var(--carimbo); padding:10px 12px; border-radius:4px; }
  .sucesso { color: var(--pago); background:#E5F2EA; border-left:3px solid var(--pago); padding:10px 12px; border-radius:4px; }
</style>
</head>
<body>
<main>
  <div class="folha topo"><h1><b>UP</b> Gestão Comercial · instalação</h1></div>

<?php if ($feito): ?>
  <div class="folha">
    <p class="sucesso">Sistema instalado. O banco foi criado, a configuração foi gravada e esta página de instalação foi apagada.</p>
    <p style="margin:14px 0">Entre com o e-mail <strong><?= h($email) ?></strong> e a senha que você acabou de criar.</p>
    <a class="botao" href="/entrar">Entrar no sistema</a>
  </div>
<?php elseif ($jaInstalado): ?>
  <div class="folha">
    <p>O sistema já está instalado. Por segurança, apague este arquivo (public_html/app/instalar.php) pelo Gerenciador de Arquivos.</p>
    <p style="margin-top:14px"><a class="botao" href="/entrar">Entrar no sistema</a></p>
  </div>
<?php else: ?>
  <div class="folha">
    <h2>Conferência da hospedagem</h2>
    <ul>
      <?php foreach ($requisitos as $i => $r): ?>
        <li>
          <span class="<?= $r[1] ? 'ok' : ($i === 8 ? 'talvez' : 'nao') ?>"><?= $r[1] ? '✓' : ($i === 8 ? '!' : '✗') ?></span>
          <span><?= h($r[0]) ?><?php if (!$r[1]): ?><small><?= h($r[2]) ?></small><?php endif; ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if ($obrigatoriosOk): ?>
  <form class="folha" method="post" autocomplete="off">
    <?php if ($erro): ?><p class="erro"><?= h($erro) ?></p><?php endif; ?>
    <fieldset>
      <legend>Banco de dados (criado no cPanel → Bancos de dados MySQL)</legend>
      <label>Servidor <input name="db_host" value="<?= h($v['db_host']) ?>" required></label>
      <label>Nome do banco (com o prefixo da conta, ex.: conta_upsistema) <input name="db_name" value="<?= h($v['db_name']) ?>" required></label>
      <label>Usuário do banco <input name="db_user" value="<?= h($v['db_user']) ?>" required></label>
      <label>Senha do usuário do banco <input name="db_pass" type="password" required></label>
    </fieldset>
    <fieldset>
      <legend>Seu acesso ao sistema (perfil gestão)</legend>
      <label>Seu nome <input name="nome" value="<?= h($v['nome']) ?>" required></label>
      <label>Seu e-mail (é o login) <input name="email" type="email" value="<?= h($v['email']) ?>" required></label>
      <label>Senha de acesso (mínimo 8) <input name="senha" type="password" autocomplete="new-password" required></label>
      <label>Repita a senha <input name="senha2" type="password" autocomplete="new-password" required></label>
    </fieldset>
    <button type="submit">Instalar o sistema</button>
  </form>
  <?php else: ?>
  <div class="folha"><p class="erro">Resolva os itens marcados com ✗ e recarregue esta página.</p></div>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
