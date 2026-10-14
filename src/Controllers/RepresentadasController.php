<?php
declare(strict_types=1);

namespace Up\Controllers;

use Up\Auditoria;
use Up\Db;
use Up\Http\HttpErro;
use Up\Http\Request;
use Up\Validar;

final class RepresentadasController
{
    public static function listar(Request $req): array
    {
        $reps = Db::todos('SELECT * FROM representadas ORDER BY ativo DESC, nome');
        $canais = Db::todos('SELECT * FROM canais ORDER BY representada_id, ativo DESC, nome, contribuinte');
        $porRep = [];
        foreach ($canais as $c) {
            $porRep[(int) $c['representada_id']][] = self::tiparCanal($c);
        }
        return ['representadas' => array_map(function ($r) use ($porRep) {
            $r = self::tiparRep($r);
            $r['canais'] = $porRep[$r['id']] ?? [];
            return $r;
        }, $reps)];
    }

    public static function criar(Request $req): array
    {
        $d = self::validarRep($req->corpo, true);
        if (Db::valor('SELECT id FROM representadas WHERE nome = ?', [$d['nome']])) {
            throw new HttpErro(422, 'Já existe uma representada com esse nome.', ['nome' => 'Nome já cadastrado.']);
        }
        $id = Db::inserir('representadas', $d);
        Auditoria::registrar((int) $req->usuario['id'], 'representadas', $id, 'criar', null, $d);
        return ['representada' => self::buscarRep($id)];
    }

    public static function atualizar(Request $req): array
    {
        $id = $req->idParam();
        $antes = self::buscarRep($id);
        $d = self::validarRep($req->corpo, false);
        if (isset($d['nome']) && Db::valor('SELECT id FROM representadas WHERE nome = ? AND id <> ?', [$d['nome'], $id])) {
            throw new HttpErro(422, 'Já existe uma representada com esse nome.', ['nome' => 'Nome já cadastrado.']);
        }
        Db::atualizar('representadas', $id, $d);
        Auditoria::registrar((int) $req->usuario['id'], 'representadas', $id, 'alterar', $antes, $d);
        return ['representada' => self::buscarRep($id)];
    }

    public static function criarCanal(Request $req): array
    {
        $repId = $req->idParam();
        self::buscarRep($repId);
        $d = self::validarCanal($req->corpo, true);
        $d['representada_id'] = $repId;
        $id = Db::transacao(function () use ($d) {
            $id = Db::inserir('canais', $d);
            // todo canal nasce com uma tabela de preço própria
            Db::inserir('tabelas_preco', [
                'representada_id' => $d['representada_id'],
                'canal_id' => $id,
                'nome' => $d['nome'],
                'tipo' => ($d['casa_construtor'] ?? 0) ? 'casa_construtor' : 'normal',
            ]);
            return $id;
        });
        Auditoria::registrar((int) $req->usuario['id'], 'canais', $id, 'criar', null, $d);
        return ['canal' => self::tiparCanal(Db::um('SELECT * FROM canais WHERE id = ?', [$id]))];
    }

    public static function atualizarCanal(Request $req): array
    {
        $id = $req->idParam();
        $antes = Db::um('SELECT * FROM canais WHERE id = ?', [$id]);
        if (!$antes) {
            throw new HttpErro(404, 'Canal não encontrado.');
        }
        $d = self::validarCanal($req->corpo, false);
        Db::atualizar('canais', $id, $d);
        // desativar canal desativa as tabelas dele, mas não apaga nada
        if (array_key_exists('ativo', $d) && $d['ativo'] === 0) {
            Db::exec('UPDATE tabelas_preco SET ativo = 0 WHERE canal_id = ?', [$id]);
        }
        Auditoria::registrar((int) $req->usuario['id'], 'canais', $id, 'alterar', $antes, $d);
        return ['canal' => self::tiparCanal(Db::um('SELECT * FROM canais WHERE id = ?', [$id]))];
    }

    private static function validarRep(array $dados, bool $novo): array
    {
        return Validar::de($dados)
            ->texto('nome', $novo, 120, 'Nome')
            ->texto('razao_social', false, 190)
            ->texto('cnpj', false, 18)
            ->numero('comissao_pct', $novo, 0, 100, 'Comissão')
            ->opcao('comissao_base', ['recebido', 'faturado'])
            ->bool('comissao_sobre_ipi')
            ->bool('comissao_sobre_st')
            ->inteiro('dias_repasse_comissao')
            ->email('email_pedidos')
            ->texto('contato_financeiro', false, 190)
            ->texto('cor', false, 7)
            ->bool('ativo')
            ->ok();
    }

    private static function validarCanal(array $dados, bool $novo): array
    {
        return Validar::de($dados)
            ->texto('nome', $novo, 120, 'Nome')
            ->opcao('contribuinte', ['sim', 'nao', 'ambos'])
            ->bool('casa_construtor')
            ->numero('desconto_base_pct', false, 0, 100, 'Desconto')
            ->numero('comissao_pct', false, 0, 100, 'Comissão')
            ->bool('ativo')
            ->ok();
    }

    private static function buscarRep(int $id): array
    {
        $r = Db::um('SELECT * FROM representadas WHERE id = ?', [$id]);
        if (!$r) {
            throw new HttpErro(404, 'Representada não encontrada.');
        }
        return self::tiparRep($r);
    }

    private static function tiparRep(array $r): array
    {
        foreach (['id', 'dias_repasse_comissao'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['comissao_pct'] = (float) $r['comissao_pct'];
        foreach (['comissao_sobre_ipi', 'comissao_sobre_st', 'ativo'] as $k) {
            $r[$k] = (int) $r[$k] === 1;
        }
        return $r;
    }

    private static function tiparCanal(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['representada_id'] = (int) $c['representada_id'];
        $c['desconto_base_pct'] = (float) $c['desconto_base_pct'];
        $c['comissao_pct'] = $c['comissao_pct'] === null ? null : (float) $c['comissao_pct'];
        $c['casa_construtor'] = (int) $c['casa_construtor'] === 1;
        $c['ativo'] = (int) $c['ativo'] === 1;
        return $c;
    }
}
