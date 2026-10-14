<?php
declare(strict_types=1);

namespace Up\Controllers;

use Up\App;
use Up\Auditoria;
use Up\Db;
use Up\Http\HttpErro;
use Up\Http\Request;
use Up\Importacao\ImportadorTabela;
use Up\Validar;

final class CatalogoController
{
    // ------------------------------------------------ tabelas
    public static function tabelas(Request $req): array
    {
        $repId = (int) $req->get('representada_id', 0);
        $sql = 'SELECT t.*, c.nome AS canal_nome, c.contribuinte, r.nome AS representada_nome,
                       (SELECT COUNT(*) FROM precos p WHERE p.tabela_id = t.id) AS qtd_precos,
                       (SELECT MAX(p.updated_at) FROM precos p WHERE p.tabela_id = t.id) AS precos_atualizados_em
                  FROM tabelas_preco t
                  JOIN representadas r ON r.id = t.representada_id
             LEFT JOIN canais c ON c.id = t.canal_id';
        $params = [];
        if ($repId) {
            $sql .= ' WHERE t.representada_id = ?';
            $params[] = $repId;
        }
        $sql .= " ORDER BY r.nome, t.ativo DESC, FIELD(t.tipo,'normal','casa_construtor','promocional','outra'), t.nome";
        $hoje = App::hoje()->format('Y-m-d');
        return ['tabelas' => array_map(function ($t) use ($hoje) {
            $t['id'] = (int) $t['id'];
            $t['representada_id'] = (int) $t['representada_id'];
            $t['canal_id'] = $t['canal_id'] !== null ? (int) $t['canal_id'] : null;
            $t['qtd_precos'] = (int) $t['qtd_precos'];
            $t['ativo'] = (int) $t['ativo'] === 1;
            $t['vigente'] = $t['ativo'] && ($t['vigencia_inicio'] === null || $t['vigencia_inicio'] <= $hoje) && ($t['vigencia_fim'] === null || $t['vigencia_fim'] >= $hoje);
            return $t;
        }, Db::todos($sql, $params))];
    }

    public static function criarTabela(Request $req): array
    {
        $d = self::validarTabela($req->corpo, true);
        if (!Db::valor('SELECT id FROM representadas WHERE id = ?', [$d['representada_id']])) {
            throw new HttpErro(422, 'Representada não encontrada.');
        }
        if (!empty($d['canal_id']) && !Db::valor('SELECT id FROM canais WHERE id = ? AND representada_id = ?', [$d['canal_id'], $d['representada_id']])) {
            throw new HttpErro(422, 'O canal não é desta representada.');
        }
        $id = Db::inserir('tabelas_preco', $d);
        Auditoria::registrar((int) $req->usuario['id'], 'tabelas_preco', $id, 'criar', null, $d);
        return ['tabela' => Db::um('SELECT * FROM tabelas_preco WHERE id = ?', [$id])];
    }

    public static function atualizarTabela(Request $req): array
    {
        $id = $req->idParam();
        $antes = Db::um('SELECT * FROM tabelas_preco WHERE id = ?', [$id]);
        if (!$antes) {
            throw new HttpErro(404, 'Tabela não encontrada.');
        }
        $d = self::validarTabela($req->corpo, false);
        unset($d['representada_id']);
        Db::atualizar('tabelas_preco', $id, $d);
        Auditoria::registrar((int) $req->usuario['id'], 'tabelas_preco', $id, 'alterar', $antes, $d);
        return ['tabela' => Db::um('SELECT * FROM tabelas_preco WHERE id = ?', [$id])];
    }

    private static function validarTabela(array $dados, bool $novo): array
    {
        $d = Validar::de($dados)
            ->inteiro('representada_id', $novo)
            ->inteiro('canal_id')
            ->texto('nome', $novo, 120, 'Nome')
            ->opcao('tipo', ['normal', 'casa_construtor', 'promocional', 'outra'], $novo)
            ->data('vigencia_inicio')
            ->data('vigencia_fim')
            ->bool('ativo')
            ->ok();
        if (!empty($d['vigencia_inicio']) && !empty($d['vigencia_fim']) && $d['vigencia_fim'] < $d['vigencia_inicio']) {
            throw new HttpErro(422, 'A data final é antes da inicial.', ['vigencia_fim' => 'Data final antes da inicial.']);
        }
        if (array_key_exists('canal_id', $d) && !$d['canal_id']) {
            $d['canal_id'] = null;
        }
        return $d;
    }

    // ------------------------------------------------ produtos
    public static function produtos(Request $req): array
    {
        $repId = (int) $req->get('representada_id', 0);
        $tabId = (int) $req->get('tabela_id', 0);
        $q = trim((string) $req->get('q', ''));
        $pagina = max(1, (int) $req->get('pagina', 1));
        $porPagina = min(100, max(10, (int) $req->get('por_pagina', 50)));
        $situacao = (string) $req->get('situacao', 'ativos'); // ativos | todos | em_falta | sem_preco

        if (!$repId) {
            throw new HttpErro(422, 'Escolha a representada.');
        }
        $where = ['p.representada_id = :rep'];
        $params = ['rep' => $repId, 'tab' => $tabId];
        if ($situacao === 'ativos') {
            $where[] = 'p.ativo = 1';
        } elseif ($situacao === 'em_falta') {
            $where[] = "p.ativo = 1 AND p.disponibilidade <> 'normal'";
        } elseif ($situacao === 'sem_preco') {
            $where[] = 'p.ativo = 1 AND pr.preco IS NULL';
        }
        if ($q !== '') {
            // cada palavra precisa aparecer no código ou na descrição
            foreach (array_slice(preg_split('/\s+/', $q), 0, 6) as $i => $palavra) {
                $where[] = "(p.codigo LIKE :qc$i OR p.descricao LIKE :qd$i)";
                $params["qc$i"] = $params["qd$i"] = '%' . str_replace(['%', '_'], ['\%', '\_'], $palavra) . '%';
            }
        }
        $w = implode(' AND ', $where);
        $base = "FROM produtos p
            LEFT JOIN precos pr ON pr.produto_id = p.id AND pr.tabela_id = :tab
            LEFT JOIN categorias_produto c ON c.id = p.categoria_id
            LEFT JOIN produtos s ON s.id = p.substituido_por_id
            WHERE $w";
        $total = (int) Db::valor("SELECT COUNT(*) $base", $params);
        $off = ($pagina - 1) * $porPagina;
        $linhas = Db::todos(
            "SELECT p.id, p.codigo, p.descricao, p.unidade, p.multiplo_venda, p.ipi_pct, p.disponibilidade, p.previsao_disponivel,
                    p.ativo, p.foto, c.nome AS categoria, pr.preco, pr.desconto_max_pct,
                    s.codigo AS substituido_por_codigo, s.descricao AS substituido_por_descricao
             $base ORDER BY (pr.preco IS NULL), p.descricao LIMIT $porPagina OFFSET $off",
            $params
        );

        // preço promocional vigente (tabelas promocionais da mesma representada; do mesmo canal ou sem canal)
        $promos = [];
        if ($linhas) {
            $canal = $tabId ? Db::valor('SELECT canal_id FROM tabelas_preco WHERE id = ?', [$tabId]) : null;
            $hoje = App::hoje()->format('Y-m-d');
            $ids = implode(',', array_map(fn($l) => (int) $l['id'], $linhas));
            $promoSql = "SELECT pr.produto_id, pr.preco, t.nome, t.vigencia_fim
                           FROM precos pr JOIN tabelas_preco t ON t.id = pr.tabela_id
                          WHERE t.tipo = 'promocional' AND t.ativo = 1 AND t.representada_id = ?
                            AND (t.vigencia_inicio IS NULL OR t.vigencia_inicio <= ?) AND (t.vigencia_fim IS NULL OR t.vigencia_fim >= ?)
                            AND pr.produto_id IN ($ids)" . ($canal ? ' AND (t.canal_id IS NULL OR t.canal_id = ?)' : '') . ' ORDER BY pr.preco DESC';
            $pp = [$repId, $hoje, $hoje];
            if ($canal) {
                $pp[] = $canal;
            }
            foreach (Db::todos($promoSql, $pp) as $p) {
                $promos[(int) $p['produto_id']] = ['preco' => (float) $p['preco'], 'tabela' => $p['nome'], 'ate' => $p['vigencia_fim']];
            }
        }

        return [
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => (int) ceil($total / $porPagina),
            'produtos' => array_map(fn($l) => [
                'id' => (int) $l['id'],
                'codigo' => $l['codigo'],
                'descricao' => $l['descricao'],
                'unidade' => $l['unidade'],
                'multiplo_venda' => (float) $l['multiplo_venda'],
                'ipi_pct' => (float) $l['ipi_pct'],
                'categoria' => $l['categoria'],
                'preco' => $l['preco'] !== null ? (float) $l['preco'] : null,
                'desconto_max_pct' => $l['desconto_max_pct'] !== null ? (float) $l['desconto_max_pct'] : null,
                'promocao' => $promos[(int) $l['id']] ?? null,
                'disponibilidade' => $l['disponibilidade'],
                'previsao_disponivel' => $l['previsao_disponivel'],
                'substituido_por' => $l['substituido_por_codigo'] ? ['codigo' => $l['substituido_por_codigo'], 'descricao' => $l['substituido_por_descricao']] : null,
                'ativo' => (int) $l['ativo'] === 1,
            ], $linhas),
        ];
    }

    public static function atualizarProduto(Request $req): array
    {
        $id = $req->idParam();
        $antes = Db::um('SELECT * FROM produtos WHERE id = ?', [$id]);
        if (!$antes) {
            throw new HttpErro(404, 'Produto não encontrado.');
        }
        $d = Validar::de($req->corpo)
            ->texto('descricao', false, 255)
            ->opcao('disponibilidade', ['normal', 'em_falta', 'sob_encomenda'])
            ->texto('previsao_disponivel', false, 80)
            ->numero('ipi_pct', false, 0, 100)
            ->numero('multiplo_venda', false, 0.001)
            ->bool('ativo')
            ->ok();
        if (array_key_exists('substituido_por_codigo', $req->corpo)) {
            $cod = trim((string) $req->corpo['substituido_por_codigo']);
            if ($cod === '') {
                $d['substituido_por_id'] = null;
            } else {
                $sid = Db::valor('SELECT id FROM produtos WHERE representada_id = ? AND codigo = ?', [$antes['representada_id'], $cod]);
                if (!$sid || (int) $sid === $id) {
                    throw new HttpErro(422, 'Código substituto não encontrado nesta representada.', ['substituido_por_codigo' => 'Código não encontrado.']);
                }
                $d['substituido_por_id'] = (int) $sid;
            }
        }
        if (($d['disponibilidade'] ?? null) === 'normal') {
            $d['previsao_disponivel'] = null;
        }
        Db::atualizar('produtos', $id, $d);
        Auditoria::registrar((int) $req->usuario['id'], 'produtos', $id, 'alterar', array_intersect_key($antes, $d), $d);
        return ['ok' => true];
    }

    // ------------------------------------------------ importação de tabelas
    public static function importarReceber(Request $req): array
    {
        $arq = $req->arquivos['arquivo'] ?? null;
        if (!$arq) {
            throw new HttpErro(422, 'Envie a planilha da tabela.');
        }
        return ImportadorTabela::receber($arq, (int) $req->get('representada_id', 0), (int) $req->usuario['id']);
    }

    public static function importarAba(Request $req): array
    {
        return ImportadorTabela::trocarAba($req->idParam(), (int) $req->get('aba', 0));
    }

    public static function importarPrevia(Request $req): array
    {
        return ImportadorTabela::previa($req->idParam(), $req->corpo);
    }

    public static function importarConfirmar(Request $req): array
    {
        return ImportadorTabela::confirmar($req->idParam(), (int) $req->usuario['id']);
    }

    public static function importarDesfazer(Request $req): array
    {
        return ImportadorTabela::desfazer($req->idParam(), (int) $req->usuario['id']);
    }

    public static function importacoes(Request $req): array
    {
        $repId = (int) $req->get('representada_id', 0);
        $linhas = Db::todos(
            "SELECT i.id, i.nome_original, i.situacao, i.linhas_ok, i.linhas_erro, i.created_at, i.importada_em, i.relatorio,
                    r.nome AS representada, u.nome AS usuario
               FROM importacoes i
               LEFT JOIN representadas r ON r.id = i.representada_id
               LEFT JOIN usuarios u ON u.id = i.usuario_id
              WHERE i.tipo = 'precos' AND i.situacao <> 'previa'" . ($repId ? ' AND i.representada_id = ?' : '') . '
              ORDER BY i.id DESC LIMIT 30',
            $repId ? [$repId] : []
        );
        return ['importacoes' => array_map(function ($l) {
            $rel = $l['relatorio'] ? json_decode($l['relatorio'], true) : [];
            unset($l['relatorio']);
            $l['id'] = (int) $l['id'];
            $l['resumo'] = $rel['resumo'] ?? null;
            return $l;
        }, $linhas)];
    }
}
