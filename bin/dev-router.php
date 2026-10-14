<?php
// Só para desenvolvimento local: php -S 127.0.0.1:8080 -t public bin/dev-router.php
// Faz o papel do .htaccess.
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($caminho, '/api/')) {
    require __DIR__ . '/../public/api/index.php';
    return true;
}
$arquivo = __DIR__ . '/../public' . $caminho;
if ($caminho !== '/' && is_file($arquivo)) {
    return false;
}
readfile(__DIR__ . '/../public/index.html');
return true;
