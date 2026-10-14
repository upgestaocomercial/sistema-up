<?php
declare(strict_types=1);

namespace Up\Controllers;

use Up\Auditoria;
use Up\Auth;
use Up\Db;
use Up\Http\HttpErro;
use Up\Http\Request;
use Up\Importacao\ImportadorClientes;
use Up\Limpeza;
use Up\Validar;

final class ClientesController
{
    /** Monta o WHERE a partir dos filtros. Vendedor só enxerga a própria carteira. */
    private static function filtro(Request $req): array
    {
        $w = ['1=1'];
        $p = [];
        if (Auth::ehVendedor($req)) {
            $w[] = 'c.vendedor_id = :eu';
            $p['eu'] = (int) $req->usuario['id'];
        } elseif (($v = $req->get('vendedor_id')) !== null && $v !== '') {
            if ($v === 'sem') {
                $w[] = 'c.vendedor_id IS NULL';
            } else {
                $w[] = 'c.vendedor_id = :vend';
                $p['vend'] = (int) $v;
            }
        }
        $q = trim((string) $req->get('q', ''));
        if ($q !== '') {
            $dig = Limpeza::soDigitos($q);
            if (strlen($dig) >= 5 && strlen($dig) === strlen(preg_replace('/[\s.\/-]/', '', $q))) {
                $w[] = "REPLACE(REPLACE(REPLACE(c.cnpj_cpf, '.', ''), '/', ''), '-', '') LIKE :doc";
                $p['doc'] = $dig . '%';
            } else {
                foreach (array_slice(preg_split('/\s+/', $q), 0, 5) as $i => $pal) {
                    $w[] = "(c.razao_social LIKE :a$i OR c.fantasia LIKE :b$i OR c.municipio_nome LIKE :c$i)";
                    $p["a$i"] = $p["b$i"] = $p["c$i"] = '%' . str_replace(['%', '_'], ['\%', '\_'], $pal) . '%';
                }
            }
        }
        if ($cid = trim((string) $req->get('cidade', ''))) {
            $w[] = 'c.municipio_nome = :cid';
            $p['cid'] = $cid;
        }
        if ($seg = (int) $req->get('segmento_id', 0)) {
            $w[] = 'c.segmento_id = :seg';
            $p['seg'] = $seg;
        }
        if ($tag = (int) $req->get('tag_id', 0)) {
            $w[] = 'EXISTS (SELECT 1 FROM cliente_tags ct WHERE ct.cliente_id = c.id AND ct.tag_id = :tag)';
            $p['tag'] = $tag;
        }
        if (($sc = (string) $req->get('carteira', '')) !== '') {
            $w[] = 'c.mercos_situacao = :sc';
            $p['sc'] = $sc;
        }
        if (($ct = (string) $req->get('contribuinte', '')) !== '') {
            $w[] = $ct === 'sem' ? 'c.contribuinte IS NULL' : 'c.contribuinte = :ct';
            if ($ct !== 'sem') {
                $p['ct'] = (int) $ct;
            }
        }
        if ($req->get('bloqueados') !== '1') {
            $w[] = "c.situacao <> 'inativo'";
        }
        return [implode(' AND ', $w), $p];
    }

    public static function listar(Request $req): array
    {
        [$w, $p] = self::filtro($req);
        $pagina = max(1, (int) $req->get('pagina', 1));
        $por = min(100, max(10, (int) $req->get('por_pagina', 40)));
        $ordem = match ((string) $req->get('ordem', 'nome')) {
            'ultima_compra' => 'c.ultimo_pedido_data IS NULL, c.ultimo_pedido_data DESC',
            'sem_comprar' => 'c.ultimo_pedido_data IS NULL, c.ultimo_pedido_data ASC',
            'cadastro' => 'c.data_cadastro DESC',
            default => 'c.razao_social',
        };
        $total = (int) Db::valor("SELECT COUNT(*) FROM clientes c WHERE $w", $p);
        $off = ($pagina - 1) * $por;
        $linhas = Db::todos(
            "SELECT c.id, c.razao_social, c.fantasia, c.cnpj_cpf, c.contribuinte, c.municipio_nome, c.uf, c.situacao,
                    c.mercos_situacao, c.ultimo_pedido_data, c.ultimo_pedido_valor, u.nome AS vendedor, s.nome AS segmento,
                    (SELECT telefone FROM cliente_contatos cc WHERE cc.cliente_id = c.id AND cc.telefone IS NOT NULL ORDER BY cc.principal DESC, cc.whatsapp DESC, cc.id LIMIT 1) AS telefone,
                    (SELECT GROUP_CONCAT(t.nome SEPARATOR '|') FROM cliente_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.cliente_id = c.id) AS tags
               FROM clientes c
          LEFT JOIN usuarios u ON u.id = c.vendedor_id
          LEFT JOIN segmentos s ON s.id = c.segmento_id
              WHERE $w ORDER BY $ordem LIMIT $por OFFSET $off",
            $p
        );
        return [
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => (int) ceil($total / $por),
            'clientes' => array_map(fn($c) => [
                'id' => (int) $c['id'],
                'razao_social' => $c['razao_social'],
                'fantasia' => $c['fantasia'],
                'cnpj_cpf' => $c['cnpj_cpf'],
                'contribuinte' => $c['contribuinte'] === null ? null : (int) $c['contribuinte'] === 1,
                'cidade' => $c['municipio_nome'],
                'uf' => $c['uf'],
                'situacao' => $c['situacao'],
                'carteira' => $c['mercos_situacao'],
                'ultimo_pedido_data' => $c['ultimo_pedido_data'],
                'ultimo_pedido_valor' => $c['ultimo_pedido_valor'] !== null ? (float) $c['ultimo_pedido_valor'] : null,
                'vendedor' => $c['vendedor'],
                'segmento' => $c['segmento'],
                'telefone' => $c['telefone'],
                'tags' => $c['tags'] ? explode('|', $c['tags']) : [],
            ], $linhas),
        ];
    }

    public static function opcoesFiltro(Request $req): array
    {
        $eu = Auth::ehVendedor($req) ? (int) $req->usuario['id'] : null;
        $cidades = Db::todos(
            'SELECT municipio_nome AS nome, COUNT(*) AS n FROM clientes WHERE municipio_nome IS NOT NULL' . ($eu ? ' AND vendedor_id = ?' : '') . ' GROUP BY municipio_nome ORDER BY municipio_nome',
            $eu ? [$eu] : []
        );
        return [
            'cidades' => array_map(fn($c) => ['nome' => $c['nome'], 'n' => (int) $c['n']], $cidades),
            'segmentos' => array_map(fn($s) => ['id' => (int) $s['id'], 'nome' => $s['nome']], Db::todos('SELECT id, nome FROM segmentos WHERE ativo = 1 ORDER BY nome')),
            'tags' => array_map(fn($s) => ['id' => (int) $s['id'], 'nome' => $s['nome']], Db::todos('SELECT id, nome FROM tags ORDER BY nome')),
            'redes' => array_map(fn($s) => ['id' => (int) $s['id'], 'nome' => $s['nome']], Db::todos('SELECT id, nome FROM redes WHERE ativo = 1 ORDER BY nome')),
            'vendedores' => array_map(fn($s) => ['id' => (int) $s['id'], 'nome' => $s['nome']], Db::todos("SELECT id, nome FROM usuarios WHERE ativo = 1 AND perfil <> 'tv' ORDER BY nome")),
            'carteira' => array_column(Db::todos('SELECT DISTINCT mercos_situacao FROM clientes WHERE mercos_situacao IS NOT NULL ORDER BY 1'), 'mercos_situacao'),
        ];
    }

    public static function ficha(Request $req): array
    {
        $c = self::buscarPermitido($req, $req->idParam());
        $c['contatos'] = Db::todos('SELECT id, nome, cargo, telefone, whatsapp, email, principal FROM cliente_contatos WHERE cliente_id = ? ORDER BY principal DESC, id', [$c['id']]);
        $c['tags'] = Db::todos('SELECT t.id, t.nome FROM cliente_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.cliente_id = ? ORDER BY t.nome', [$c['id']]);
        $c['anexos'] = Db::todos('SELECT id, tipo, nome_original, criado_em FROM cliente_anexos WHERE cliente_id = ? ORDER BY id DESC', [$c['id']]);
        $c['pedidos'] = Db::todos(
            'SELECT p.id, p.numero, p.data_emissao, p.situacao, p.tipo, p.total_liquido, r.nome AS representada
               FROM pedidos p JOIN representadas r ON r.id = p.representada_id WHERE p.cliente_id = ? ORDER BY p.data_emissao DESC LIMIT 20',
            [$c['id']]
        );
        return ['cliente' => $c];
    }

    public static function criar(Request $req): array
    {
        [$d, $contatos, $tags] = self::validar($req, null);
        if (Auth::ehVendedor($req)) {
            $d['vendedor_id'] = (int) $req->usuario['id'];
        }
        $uuid = $req->corpo['uuid_local'] ?? null;
        if ($uuid && ($ja = Db::valor('SELECT id FROM clientes WHERE uuid_local = ?', [$uuid]))) {
            return ['cliente' => ['id' => (int) $ja], 'repetido' => true]; // reenvio do celular
        }
        if ($uuid && preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            $d['uuid_local'] = $uuid;
        }
        $d['data_cadastro'] = \Up\App::hoje()->format('Y-m-d');
        $id = Db::transacao(function () use ($d, $contatos, $tags) {
            $id = Db::inserir('clientes', $d);
            self::gravarContatos($id, $contatos ?? []);
            self::gravarTags($id, $tags ?? []);
            return $id;
        });
        Auditoria::registrar((int) $req->usuario['id'], 'clientes', $id, 'criar', null, $d);
        return ['cliente' => ['id' => $id]];
    }

    public static function atualizar(Request $req): array
    {
        $id = $req->idParam();
        $antes = self::buscarPermitido($req, $id);
        [$d, $contatos, $tags] = self::validar($req, $id);
        if (Auth::ehVendedor($req)) {
            unset($d['vendedor_id'], $d['situacao']); // vendedor não troca dono nem bloqueia
        }
        Db::transacao(function () use ($id, $d, $contatos, $tags) {
            Db::atualizar('clientes', $id, $d);
            if ($contatos !== null) {
                self::gravarContatos($id, $contatos, true);
            }
            if ($tags !== null) {
                self::gravarTags($id, $tags, true);
            }
        });
        Auditoria::registrar((int) $req->usuario['id'], 'clientes', $id, 'alterar', array_intersect_key($antes, $d), $d);
        return ['cliente' => ['id' => $id]];
    }

    /** Gestão: passa um grupo de clientes (pelo filtro atual) para um vendedor */
    public static function atribuirVendedor(Request $req): array
    {
        $vend = $req->corpo['para_vendedor_id'] ?? null;
        $vendId = $vend === null || $vend === '' ? null : (int) $vend;
        if ($vendId !== null && !Db::valor("SELECT id FROM usuarios WHERE id = ? AND ativo = 1 AND perfil <> 'tv'", [$vendId])) {
            throw new HttpErro(422, 'Vendedor não encontrado.');
        }
        [$w, $p] = self::filtro($req);
        $ids = array_map('intval', array_column(Db::todos("SELECT c.id FROM clientes c WHERE $w", $p), 'id'));
        if (!$ids) {
            throw new HttpErro(422, 'Nenhum cliente neste filtro.');
        }
        if (count($ids) > 3000) {
            throw new HttpErro(422, 'Filtro muito amplo. Refine antes de passar para um vendedor.');
        }
        $lista = implode(',', $ids);
        $n = Db::exec("UPDATE clientes SET vendedor_id = ? WHERE id IN ($lista)", [$vendId]);
        Auditoria::registrar((int) $req->usuario['id'], 'clientes', 0, 'alterar', null, ['atribuir_vendedor' => $vendId, 'clientes' => count($ids)]);
        return ['alterados' => $n, 'no_filtro' => count($ids)];
    }

    public static function consultarCnpj(Request $req): array
    {
        $d = Limpeza::soDigitos($req->param('cnpj'));
        if (strlen($d) !== 14 || !Limpeza::cnpjValido($d)) {
            throw new HttpErro(422, 'CNPJ inválido.');
        }
        $ja = Db::um('SELECT id, razao_social FROM clientes WHERE cnpj_cpf = ?', [Limpeza::documento($d)[0]]);
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: SistemaUP/1.0\r\n"]]);
        $json = @file_get_contents("https://brasilapi.com.br/api/cnpj/v1/$d", false, $ctx);
        $r = $json ? json_decode($json, true) : null;
        if (!$r || isset($r['message'])) {
            return ['encontrado' => false, 'ja_cadastrado' => $ja];
        }
        [$logr, $num] = [$r['logradouro'] ?? null, $r['numero'] ?? null];
        return [
            'encontrado' => true,
            'ja_cadastrado' => $ja,
            'dados' => [
                'razao_social' => $r['razao_social'] ?? null,
                'fantasia' => $r['nome_fantasia'] ?: null,
                'cep' => Limpeza::cep($r['cep'] ?? null),
                'endereco' => trim(($r['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($logr ?? '')) ?: null,
                'numero' => $num,
                'complemento' => $r['complemento'] ?: null,
                'bairro' => $r['bairro'] ?? null,
                'municipio_nome' => Limpeza::cidade($r['municipio'] ?? null),
                'uf' => $r['uf'] ?? null,
                'situacao_receita' => $r['descricao_situacao_cadastral'] ?? null,
            ],
        ];
    }

    // ------------------------------------------------ importação
    public static function importarReceber(Request $req): array
    {
        $arq = $req->arquivos['arquivo'] ?? null;
        if (!$arq) {
            throw new HttpErro(422, 'Envie a planilha de clientes.');
        }
        return ImportadorClientes::receber($arq, (int) $req->usuario['id']);
    }

    public static function importarConfirmar(Request $req): array
    {
        return ImportadorClientes::confirmar($req->idParam(), (int) $req->usuario['id']);
    }

    public static function importarDesfazer(Request $req): array
    {
        return ImportadorClientes::desfazer($req->idParam(), (int) $req->usuario['id']);
    }

    public static function importacoes(Request $req): array
    {
        $linhas = Db::todos(
            "SELECT i.id, i.nome_original, i.situacao, i.linhas_ok, i.importada_em, i.created_at, i.relatorio, u.nome AS usuario
               FROM importacoes i LEFT JOIN usuarios u ON u.id = i.usuario_id
              WHERE i.tipo = 'clientes' AND i.situacao <> 'previa' ORDER BY i.id DESC LIMIT 20"
        );
        return ['importacoes' => array_map(function ($l) {
            $rel = $l['relatorio'] ? json_decode($l['relatorio'], true) : [];
            unset($l['relatorio']);
            $l['id'] = (int) $l['id'];
            $l['resumo'] = $rel['resumo'] ?? null;
            return $l;
        }, $linhas)];
    }

    // ------------------------------------------------ apoio
    private static function buscarPermitido(Request $req, int $id): array
    {
        $c = Db::um(
            'SELECT c.*, u.nome AS vendedor, s.nome AS segmento, r.nome AS rede FROM clientes c
          LEFT JOIN usuarios u ON u.id = c.vendedor_id LEFT JOIN segmentos s ON s.id = c.segmento_id LEFT JOIN redes r ON r.id = c.rede_id
              WHERE c.id = ?',
            [$id]
        );
        if (!$c || (Auth::ehVendedor($req) && (int) $c['vendedor_id'] !== (int) $req->usuario['id'])) {
            throw new HttpErro(404, 'Cliente não encontrado.');
        }
        return $c;
    }

    private static function validar(Request $req, ?int $id): array
    {
        $c = $req->corpo;
        $d = Validar::de($c)
            ->texto('razao_social', $id === null, 190, 'Razão social')
            ->texto('fantasia', false, 190)
            ->texto('inscricao_estadual', false, 30)
            ->opcao('tipo_pessoa', ['PJ', 'PF'])
            ->texto('cep', false, 9)
            ->texto('endereco', false, 190)
            ->texto('numero', false, 20)
            ->texto('complemento', false, 80)
            ->texto('bairro', false, 80)
            ->texto('municipio_nome', false, 120, 'Cidade')
            ->texto('uf', false, 2)
            ->inteiro('vendedor_id')
            ->inteiro('segmento_id')
            ->inteiro('rede_id')
            ->data('aniversario')
            ->numero('limite_credito_sugerido', false, 0)
            ->opcao('situacao', ['ativo', 'inativo', 'bloqueado'])
            ->texto('observacoes', false, 5000)
            ->ok();
        if (array_key_exists('contribuinte', $c)) {
            $d['contribuinte'] = $c['contribuinte'] === null || $c['contribuinte'] === '' ? null : ((bool) $c['contribuinte'] ? 1 : 0);
        }
        foreach (['vendedor_id', 'segmento_id', 'rede_id'] as $k) {
            if (array_key_exists($k, $d) && !$d[$k]) {
                $d[$k] = null;
            }
        }
        if (array_key_exists('cnpj_cpf', $c)) {
            $doc = Limpeza::documento($c['cnpj_cpf']);
            if ($doc === null) {
                $d['cnpj_cpf'] = null;
            } else {
                if (!$doc[2]) {
                    throw new HttpErro(422, 'CNPJ/CPF inválido.', ['cnpj_cpf' => 'Confira os números.']);
                }
                $outro = Db::um('SELECT id, razao_social FROM clientes WHERE cnpj_cpf = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$doc[0], $id] : [$doc[0]]);
                if ($outro) {
                    throw new HttpErro(422, "Este CNPJ/CPF já é do cliente {$outro['razao_social']}.", ['cnpj_cpf' => 'Já cadastrado.']);
                }
                $d['cnpj_cpf'] = $doc[0];
                $d['tipo_pessoa'] = $doc[1];
            }
        }
        if (isset($d['cep'])) {
            $d['cep'] = Limpeza::cep($d['cep']);
        }
        if (isset($d['uf'])) {
            $d['uf'] = mb_strtoupper($d['uf']);
        }
        if (($d['situacao'] ?? null) === 'bloqueado') {
            $d['motivo_bloqueio_id'] = isset($c['motivo_bloqueio_id']) ? (int) $c['motivo_bloqueio_id'] ?: null : null;
        }
        $contatos = null;
        if (isset($c['contatos']) && is_array($c['contatos'])) {
            $contatos = [];
            foreach ($c['contatos'] as $ct) {
                $tel = trim((string) ($ct['telefone'] ?? ''));
                $em = mb_strtolower(trim((string) ($ct['email'] ?? '')));
                $nome = trim((string) ($ct['nome'] ?? ''));
                if ($tel === '' && $em === '' && $nome === '') {
                    continue;
                }
                if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    throw new HttpErro(422, "E-mail inválido: $em");
                }
                $contatos[] = [
                    'id' => isset($ct['id']) ? (int) $ct['id'] : null,
                    'nome' => mb_substr($nome, 0, 120) ?: null,
                    'cargo' => mb_substr(trim((string) ($ct['cargo'] ?? '')), 0, 80) ?: null,
                    'telefone' => $tel !== '' ? (Limpeza::telefones($tel)[0]['telefone'] ?? mb_substr($tel, 0, 30)) : null,
                    'whatsapp' => !empty($ct['whatsapp']) ? 1 : 0,
                    'email' => $em ?: null,
                ];
            }
        }
        $tags = isset($c['tags']) && is_array($c['tags']) ? array_values(array_filter(array_map('intval', $c['tags']))) : null;
        return [$d, $contatos, $tags];
    }

    private static function gravarContatos(int $clienteId, array $contatos, bool $substituir = false): void
    {
        if ($substituir) {
            $manter = array_filter(array_column($contatos, 'id'));
            Db::exec('DELETE FROM cliente_contatos WHERE cliente_id = ?' . ($manter ? ' AND id NOT IN (' . implode(',', array_map('intval', $manter)) . ')' : ''), [$clienteId]);
        }
        foreach ($contatos as $i => $ct) {
            $dados = ['nome' => $ct['nome'], 'cargo' => $ct['cargo'], 'telefone' => $ct['telefone'], 'whatsapp' => $ct['whatsapp'], 'email' => $ct['email'], 'principal' => $i === 0 ? 1 : 0];
            if (!empty($ct['id'])) {
                Db::exec('UPDATE cliente_contatos SET nome=?, cargo=?, telefone=?, whatsapp=?, email=?, principal=? WHERE id = ? AND cliente_id = ?', [...array_values($dados), $ct['id'], $clienteId]);
            } else {
                Db::inserir('cliente_contatos', $dados + ['cliente_id' => $clienteId]);
            }
        }
    }

    private static function gravarTags(int $clienteId, array $tags, bool $substituir = false): void
    {
        if ($substituir) {
            Db::exec('DELETE FROM cliente_tags WHERE cliente_id = ?', [$clienteId]);
        }
        foreach ($tags as $t) {
            Db::exec('INSERT IGNORE INTO cliente_tags (cliente_id, tag_id) VALUES (?, ?)', [$clienteId, $t]);
        }
    }
}
