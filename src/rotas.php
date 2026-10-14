<?php
declare(strict_types=1);

use Up\Auth;
use Up\Controllers\CatalogoController as Cat;
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

// Catálogo e tabelas de preço
$cadastro = ['administrativo', 'gestao'];
$r->get('/api/catalogo/tabelas', [Cat::class, 'tabelas']);
$r->get('/api/catalogo/produtos', [Cat::class, 'produtos']);
$r->put('/api/catalogo/produtos/{id}', [Cat::class, 'atualizarProduto'], $cadastro);
$r->post('/api/config/tabelas', [Cat::class, 'criarTabela'], $cadastro);
$r->put('/api/config/tabelas/{id}', [Cat::class, 'atualizarTabela'], $cadastro);
$r->get('/api/catalogo/importacoes', [Cat::class, 'importacoes'], $cadastro);
$r->post('/api/catalogo/importar', [Cat::class, 'importarReceber'], $cadastro);
$r->post('/api/catalogo/importar/{id}/aba', [Cat::class, 'importarAba'], $cadastro);
$r->post('/api/catalogo/importar/{id}/previa', [Cat::class, 'importarPrevia'], $cadastro);
$r->post('/api/catalogo/importar/{id}/confirmar', [Cat::class, 'importarConfirmar'], $cadastro);
$r->post('/api/catalogo/importacoes/{id}/desfazer', [Cat::class, 'importarDesfazer'], $cadastro);

// Financeiro: só quem tem acesso_financeiro (as rotas do módulo entram na fase 4)
$r->get('/api/financeiro/ping', fn() => ['ok' => true], 'financeiro');

return $r;
