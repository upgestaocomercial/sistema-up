<?php
declare(strict_types=1);

namespace Up\Http;

use Up\Auth;

/**
 * Roteador simples. Cada rota declara quem pode chamar:
 *   'publico'    — sem login
 *   'logado'     — qualquer usuário logado (inclui TV só se a rota permitir 'tv')
 *   'financeiro' — usuarios.acesso_financeiro = 1
 *   lista de perfis — ex.: ['administrativo','gestao']
 */
final class Router
{
    private array $rotas = [];

    public function add(string $metodo, string $padrao, callable $acao, string|array $acesso = 'logado'): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($padrao, '/') ?: '/') . '$#';
        $this->rotas[] = [$metodo, $regex, $acao, $acesso];
    }

    public function get(string $p, callable $a, string|array $acesso = 'logado'): void { $this->add('GET', $p, $a, $acesso); }
    public function post(string $p, callable $a, string|array $acesso = 'logado'): void { $this->add('POST', $p, $a, $acesso); }
    public function put(string $p, callable $a, string|array $acesso = 'logado'): void { $this->add('PUT', $p, $a, $acesso); }
    public function delete(string $p, callable $a, string|array $acesso = 'logado'): void { $this->add('DELETE', $p, $a, $acesso); }

    public function despachar(Request $req): Response
    {
        try {
            $caminho = rtrim($req->caminho, '/') ?: '/';
            $metodoPermitido = false;
            foreach ($this->rotas as [$metodo, $regex, $acao, $acesso]) {
                if (!preg_match($regex, $caminho, $m)) {
                    continue;
                }
                $metodoPermitido = true;
                if ($metodo !== $req->metodo) {
                    continue;
                }
                $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->autorizar($req, $acesso);
                $r = $acao($req);
                return $r instanceof Response ? $r : Response::json($r);
            }
            throw new HttpErro($metodoPermitido ? 405 : 404, $metodoPermitido ? 'Método não permitido.' : 'Rota não encontrada.');
        } catch (HttpErro $e) {
            return Response::json(['erro' => $e->getMessage(), 'detalhes' => $e->detalhes ?: null], $e->status);
        } catch (\Throwable $e) {
            error_log('[sistema-up] ' . $e);
            $debug = (bool) \Up\App::config('app.debug', false);
            return Response::json(['erro' => 'Erro interno.', 'detalhes' => $debug ? $e->getMessage() : null], 500);
        }
    }

    private function autorizar(Request $req, string|array $acesso): void
    {
        if ($acesso === 'publico') {
            return;
        }
        // Proteção CSRF: toda alteração precisa do header enviado pelo app (o navegador não envia em ataques entre sites)
        if ($req->metodo !== 'GET' && $req->header('X-Requested-With') !== 'sistema-up') {
            throw new HttpErro(403, 'Requisição recusada.');
        }
        $req->usuario = Auth::usuarioDaRequisicao($req);
        if (!$req->usuario) {
            throw new HttpErro(401, 'Faça login para continuar.');
        }
        $perfil = $req->usuario['perfil'];
        if ($acesso === 'logado') {
            if ($perfil === 'tv') {
                throw new HttpErro(403, 'Sem permissão.');
            }
            return;
        }
        if ($acesso === 'financeiro') {
            if ((int) $req->usuario['acesso_financeiro'] !== 1) {
                throw new HttpErro(403, 'Acesso restrito ao financeiro.');
            }
            return;
        }
        if (is_array($acesso) && !in_array($perfil, $acesso, true)) {
            throw new HttpErro(403, 'Sem permissão.');
        }
    }
}
