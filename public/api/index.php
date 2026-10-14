<?php
declare(strict_types=1);

// Ponto de entrada da API.
// Na HostGator o código fica em ~/sistema-up e só a pasta pública fica em ~/public_html/app.
// Em desenvolvimento, public/ fica dentro do próprio projeto.
$candidatos = [
    __DIR__ . '/../../../sistema-up',   // ~/public_html/app/api -> ~/sistema-up
    __DIR__ . '/../..',                 // projeto/public/api -> projeto
];
$base = null;
foreach ($candidatos as $c) {
    if (is_file($c . '/vendor/autoload.php') && is_file($c . '/src/rotas.php')) {
        $base = realpath($c);
        break;
    }
}
if ($base === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Código do sistema não encontrado no servidor.']);
    exit;
}
if (!is_file($base . '/config/config.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Sistema ainda não instalado. Abra /instalar.php.']);
    exit;
}

require $base . '/vendor/autoload.php';

$router = require $base . '/src/rotas.php';
$router->despachar(Up\Http\Request::daGlobal())->enviar();
