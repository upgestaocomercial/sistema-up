<?php
declare(strict_types=1);

use Up\Auth;
use Up\Controllers\RepresentadasController as Rep;
use Up\Controllers\UsuariosController as Usu;
use Up\Http\Router;

$r = new Router();
$gestao = ['gestao'];

// Acesso
$r->post('/api/auth/login', [Auth::class, 'login'], 'publico');
$r->post('/api/auth/logout', [Auth::class, 'logout'], 'publico');
$r->get('/api/auth/eu', [Usu::class, 'eu']);
$r->post('/api/auth/senha', [Usu::class, 'trocarMinhaSenha']);
$r->get('/api/saude', fn() => ['ok' => true, 'hora_utc' => gmdate('c')], 'publico');

// Usuários
$r->get('/api/vendedores', [Usu::class, 'vendedores']);
$r->get('/api/config/usuarios', [Usu::class, 'listar'], $gestao);
$r->post('/api/config/usuarios', [Usu::class, 'criar'], $gestao);
$r->put('/api/config/usuarios/{id}', [Usu::class, 'atualizar'], $gestao);

// Representadas e canais
$r->get('/api/representadas', [Rep::class, 'listar']);
$r->post('/api/config/representadas', [Rep::class, 'criar'], $gestao);
$r->put('/api/config/representadas/{id}', [Rep::class, 'atualizar'], $gestao);
$r->post('/api/config/representadas/{id}/canais', [Rep::class, 'criarCanal'], $gestao);
$r->put('/api/config/canais/{id}', [Rep::class, 'atualizarCanal'], $gestao);

// Financeiro: só quem tem acesso_financeiro (as rotas do módulo entram na fase 4)
$r->get('/api/financeiro/ping', fn() => ['ok' => true], 'financeiro');

return $r;
