<?php
declare(strict_types=1);

namespace Up;

use Up\Http\HttpErro;
use Up\Http\Request;
use Up\Http\Response;

final class Auth
{
    public const COOKIE = 'up_sessao';
    private const MAX_FALHAS_EMAIL = 5;   // em 15 minutos
    private const MAX_FALHAS_IP = 20;     // em 15 minutos

    public static function login(Request $req): Response
    {
        $email = mb_strtolower(trim((string) $req->get('email', '')));
        $senha = (string) $req->get('senha', '');
        $tipo = $req->get('tipo') === 'app' ? 'app' : 'navegador';

        if ($email === '' || $senha === '') {
            throw new HttpErro(422, 'Informe e-mail e senha.');
        }

        $falhasEmail = (int) Db::valor(
            'SELECT COUNT(*) FROM login_tentativas WHERE email = ? AND sucesso = 0 AND criado_em > UTC_TIMESTAMP() - INTERVAL 15 MINUTE',
            [$email]
        );
        $falhasIp = (int) Db::valor(
            'SELECT COUNT(*) FROM login_tentativas WHERE ip = ? AND sucesso = 0 AND criado_em > UTC_TIMESTAMP() - INTERVAL 15 MINUTE',
            [$req->ip]
        );
        if ($falhasEmail >= self::MAX_FALHAS_EMAIL || $falhasIp >= self::MAX_FALHAS_IP) {
            throw new HttpErro(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
        }

        $u = Db::um('SELECT * FROM usuarios WHERE email = ? AND ativo = 1', [$email]);
        $ok = $u && password_verify($senha, $u['senha_hash']);
        Db::inserir('login_tentativas', ['email' => $email, 'ip' => $req->ip, 'sucesso' => $ok ? 1 : 0]);
        if (!$ok) {
            throw new HttpErro(401, 'E-mail ou senha incorretos.');
        }

        if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
        }

        if ($u['perfil'] === 'tv') {
            $tipo = 'aparelho_tv';
        }
        $duracao = match ($tipo) {
            'aparelho_tv' => 'P' . (int) App::config('sessao.dias_tv', 365) . 'D',
            'app' => 'P' . (int) App::config('sessao.dias_app', 30) . 'D',
            default => 'PT' . (int) App::config('sessao.horas_navegador', 12) . 'H',
        };
        $expira = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add(new \DateInterval($duracao));

        $token = bin2hex(random_bytes(32));
        Db::inserir('sessoes', [
            'id' => hash('sha256', $token),
            'usuario_id' => $u['id'],
            'dispositivo' => mb_substr((string) $req->header('User-Agent'), 0, 190),
            'tipo' => $tipo,
            'expira_em' => $expira->format('Y-m-d H:i:s'),
        ]);
        Db::exec('UPDATE usuarios SET ultimo_login_em = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
        Db::inserir('auditoria', ['usuario_id' => $u['id'], 'entidade' => 'usuarios', 'entidade_id' => $u['id'], 'acao' => 'login']);

        return Response::json(['usuario' => self::publico($u)])
            ->cookie(self::COOKIE, $token, $expira->getTimestamp(), (bool) App::config('app.cookie_secure', true));
    }

    public static function logout(Request $req): Response
    {
        $token = $req->cookies[self::COOKIE] ?? '';
        if ($token !== '') {
            Db::exec('DELETE FROM sessoes WHERE id = ?', [hash('sha256', $token)]);
        }
        return Response::json(['ok' => true])->cookie(self::COOKIE, '', 1, (bool) App::config('app.cookie_secure', true));
    }

    public static function usuarioDaRequisicao(Request $req): ?array
    {
        $token = $req->cookies[self::COOKIE] ?? '';
        if ($token === '' || !ctype_xdigit($token) || strlen($token) !== 64) {
            return null;
        }
        $id = hash('sha256', $token);
        $u = Db::um(
            'SELECT u.*, s.tipo AS sessao_tipo FROM sessoes s JOIN usuarios u ON u.id = s.usuario_id
              WHERE s.id = ? AND s.expira_em > UTC_TIMESTAMP() AND u.ativo = 1',
            [$id]
        );
        if ($u) {
            Db::exec('UPDATE sessoes SET visto_em = UTC_TIMESTAMP() WHERE id = ? AND (visto_em IS NULL OR visto_em < UTC_TIMESTAMP() - INTERVAL 10 MINUTE)', [$id]);
        }
        return $u;
    }

    public static function publico(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'nome' => $u['nome'],
            'email' => $u['email'],
            'perfil' => $u['perfil'],
            'acesso_financeiro' => (int) $u['acesso_financeiro'] === 1,
        ];
    }

    public static function exigirPerfil(Request $req, array $perfis): void
    {
        if (!in_array($req->usuario['perfil'] ?? '', $perfis, true)) {
            throw new HttpErro(403, 'Sem permissão.');
        }
    }

    public static function ehVendedor(Request $req): bool
    {
        return ($req->usuario['perfil'] ?? '') === 'vendedor';
    }

    public static function validarSenha(string $senha): void
    {
        if (mb_strlen($senha) < 8) {
            throw new HttpErro(422, 'A senha precisa ter pelo menos 8 caracteres.');
        }
    }
}
