<?php
declare(strict_types=1);

// Ponto de entrada da API. Em produção, a pasta do projeto fica fora do public_html
// e só o conteúdo de public/ é publicado; ajuste BASE se necessário.
const BASE = __DIR__ . '/../..';

require BASE . '/vendor/autoload.php';

use Up\Http\Request;

$router = require BASE . '/src/rotas.php';
$router->despachar(Request::daGlobal())->enviar();
