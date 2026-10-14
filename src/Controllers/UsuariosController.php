<?php
declare(strict_types=1);

namespace Up\Controllers;

use Up\Auditoria;
use Up\Auth;
use Up\Db;
use Up\Http\HttpErro;
use Up\Http\Request;
use Up\Validar;

final class UsuariosController
{
    private const CAMPOS = 'id, nome, email, telefone, perfil, acesso_financeiro, ativo, ultimo_login_em';

    public static function eu(Request $req): array
    {
        return ['usuario' => Auth::publico($req->usuario)];
    }

    public static function listar(Request $req): array
    {
        $sql = 'SELECT ' . self::CAMPOS . ' FROM usuarios';
        $params = [];
        if ($req->get('ativos') === '1') {
            $sql .= ' WHERE ativo = 1';
        }
        $sql .= ' ORDER BY ativo DESC, nome';
        return ['usuarios' => array_map([self::class, 'tipar'], Db::todos($sql, $params))];
    }

    /** Lista curta de vendedores para filtros (qualquer usuário logado) */
    public static function vendedores(Request $req): array
    {
        $linhas = Db::todos("SELECT id, nome FROM usuarios WHERE ativo = 1 AND perfil <> 'tv' ORDER BY nome");
        return ['vendedores' => array_map(fn($l) => ['id' => (int) $l['id'], 'nome' => $l['nome']], $linhas)];
    }

    public static function criar(Request $req): array
    {
        $d = self::validar($req->corpo, true);
        if (Db::valor('SELECT id FROM usuarios WHERE email = ?', [$d['email']])) {
            throw new HttpErro(422, 'Já existe um usuário com esse e-mail.', ['email' => 'E-mail já cadastrado.']);
        }
        $senha = (string) ($req->corpo['senha'] ?? '');
        Auth::validarSenha($senha);
        $d['senha_hash'] = password_hash($senha, PASSWORD_DEFAULT);
        $id = Db::inserir('usuarios', $d);
        unset($d['senha_hash']);
        Auditoria::registrar((int) $req->usuario['id'], 'usuarios', $id, 'criar', null, $d);
        return ['usuario' => self::buscar($id)];
    }

    public static function atualizar(Request $req): array
    {
        $id = $req->idParam();
        $antes = self::buscar($id);
        $d = self::validar($req->corpo, false);
        if (isset($d['email']) && Db::valor('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$d['email'], $id])) {
            throw new HttpErro(422, 'Já existe um usuário com esse e-mail.', ['email' => 'E-mail já cadastrado.']);
        }
        if ($id === (int) $req->usuario['id'] && (($d['ativo'] ?? 1) === 0 || (isset($d['perfil']) && $d['perfil'] !== 'gestao'))) {
            throw new HttpErro(422, 'Você não pode desativar nem tirar a gestão do seu próprio usuário.');
        }
        if (!empty($req->corpo['senha'])) {
            Auth::validarSenha((string) $req->corpo['senha']);
            $d['senha_hash'] = password_hash((string) $req->corpo['senha'], PASSWORD_DEFAULT);
            Db::exec('DELETE FROM sessoes WHERE usuario_id = ?', [$id]); // troca de senha derruba as sessões
        }
        if (($d['ativo'] ?? 1) === 0) {
            Db::exec('DELETE FROM sessoes WHERE usuario_id = ?', [$id]);
        }
        Db::atualizar('usuarios', $id, $d);
        unset($d['senha_hash']);
        Auditoria::registrar((int) $req->usuario['id'], 'usuarios', $id, 'alterar', $antes, $d);
        return ['usuario' => self::buscar($id)];
    }

    public static function trocarMinhaSenha(Request $req): array
    {
        $atual = (string) $req->get('senha_atual', '');
        $nova = (string) $req->get('senha_nova', '');
        $u = Db::um('SELECT senha_hash FROM usuarios WHERE id = ?', [$req->usuario['id']]);
        if (!$u || !password_verify($atual, $u['senha_hash'])) {
            throw new HttpErro(422, 'Senha atual incorreta.', ['senha_atual' => 'Senha atual incorreta.']);
        }
        Auth::validarSenha($nova);
        Db::exec('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $req->usuario['id']]);
        // mantém a sessão atual, derruba as outras
        $token = $req->cookies[Auth::COOKIE] ?? '';
        Db::exec('DELETE FROM sessoes WHERE usuario_id = ? AND id <> ?', [$req->usuario['id'], hash('sha256', $token)]);
        return ['ok' => true];
    }

    private static function validar(array $dados, bool $novo): array
    {
        return Validar::de($dados)
            ->texto('nome', $novo, 120, 'Nome')
            ->email('email', $novo)
            ->texto('telefone', false, 30)
            ->opcao('perfil', ['vendedor', 'administrativo', 'gestao', 'tv'], $novo)
            ->bool('acesso_financeiro')
            ->bool('ativo')
            ->ok();
    }

    private static function buscar(int $id): array
    {
        $u = Db::um('SELECT ' . self::CAMPOS . ' FROM usuarios WHERE id = ?', [$id]);
        if (!$u) {
            throw new HttpErro(404, 'Usuário não encontrado.');
        }
        return self::tipar($u);
    }

    private static function tipar(array $u): array
    {
        $u['id'] = (int) $u['id'];
        $u['acesso_financeiro'] = (int) $u['acesso_financeiro'] === 1;
        $u['ativo'] = (int) $u['ativo'] === 1;
        return $u;
    }
}
