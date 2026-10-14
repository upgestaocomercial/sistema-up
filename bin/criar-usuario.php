<?php
declare(strict_types=1);
// Uso: php bin/criar-usuario.php "Nome" email@up.com.br gestao [financeiro]
// A senha é pedida no terminal (não fica no histórico).
require __DIR__ . '/../vendor/autoload.php';

use Up\Db;

[$_, $nome, $email, $perfil] = array_pad($argv, 4, null);
$financeiro = in_array('financeiro', $argv, true) ? 1 : 0;
if (!$nome || !$email || !in_array($perfil, ['vendedor', 'administrativo', 'gestao', 'tv'], true)) {
    fwrite(STDERR, "Uso: php bin/criar-usuario.php \"Nome\" email perfil(vendedor|administrativo|gestao|tv) [financeiro]\n");
    exit(1);
}
$senha = getenv('UP_SENHA') ?: null;
if ($senha === null) {
    echo 'Senha (mín. 8): ';
    system('stty -echo 2>/dev/null');
    $senha = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";
}
if (mb_strlen($senha) < 8) {
    fwrite(STDERR, "Senha muito curta.\n");
    exit(1);
}
$id = Db::inserir('usuarios', [
    'nome' => $nome,
    'email' => mb_strtolower($email),
    'perfil' => $perfil,
    'acesso_financeiro' => $financeiro,
    'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
]);
echo "Usuário criado: #$id $nome ($perfil" . ($financeiro ? ', financeiro' : '') . ")\n";
