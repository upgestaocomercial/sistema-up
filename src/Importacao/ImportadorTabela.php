<?php
declare(strict_types=1);

namespace Up\Importacao;

use Up\Db;
use Up\Http\HttpErro;
use Up\Numero;
use Up\Planilha;

/**
 * Importa a tabela de preços de uma fábrica (qualquer layout de planilha).
 *
 * Etapas:
 *   1. receber()   — guarda o arquivo, acha a linha de cabeçalho e sugere as colunas
 *   2. previa()    — com as colunas escolhidas, calcula o que vai mudar, sem gravar
 *   3. confirmar() — grava tudo numa transação e registra o "antes" para poder desfazer
 *   4. desfazer()  — volta preços e produtos ao que eram
 */
final class ImportadorTabela
{
    /** campos que o sistema entende => palavras que costumam aparecer no cabeçalho da fábrica */
    public const CAMPOS = [
        'codigo' => ['codigo', 'cod', 'cod produto', 'codigo produto', 'referencia', 'ref', 'sku', 'cod item', 'item', 'codigo item'],
        'descricao' => ['descricao', 'produto', 'nome', 'descricao produto', 'denominacao', 'nome produto', 'descricao item', 'modelo'],
        'preco' => ['preco', 'valor', 'preco tabela', 'preco unitario', 'valor unitario', 'preco lista', 'vlr', 'vlr unit', 'unitario', 'preco venda', 'preco de tabela', 'preco r', 'valor r'],
        'ipi' => ['ipi', '% ipi', 'ipi %', 'aliq ipi', 'aliquota ipi'],
        'unidade' => ['un', 'und', 'unid', 'unidade', 'um'],
        'categoria' => ['categoria', 'grupo', 'familia', 'linha', 'segmento'],
        'desconto_max' => ['desconto max', 'desc max', 'desconto maximo', 'desc maximo'],
        'multiplo' => ['multiplo', 'embalagem', 'qtd embalagem', 'qtde embalagem', 'multiplo venda'],
    ];

    // ------------------------------------------------------------------ 1. receber
    public static function receber(array $arquivo, int $representadaId, int $usuarioId): array
    {
        if (!Db::valor('SELECT id FROM representadas WHERE id = ?', [$representadaId])) {
            throw new HttpErro(422, 'Escolha a representada.');
        }
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpErro(422, 'Envie a planilha da tabela.');
        }
        if ($arquivo['size'] > 15 * 1024 * 1024) {
            throw new HttpErro(422, 'Arquivo maior que 15 MB.');
        }
        $nome = (string) $arquivo['name'];
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm', 'csv', 'txt', 'xls'], true)) {
            throw new HttpErro(422, 'Formato não reconhecido. Envie .xlsx ou .csv.');
        }
        $pasta = self::pasta();
        $destino = $pasta . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!@move_uploaded_file($arquivo['tmp_name'], $destino) && !@rename($arquivo['tmp_name'], $destino)) {
            throw new HttpErro(500, 'Não foi possível guardar o arquivo.');
        }

        $lida = Planilha::ler($destino, $nome, 0);
        $id = Db::inserir('importacoes', [
            'tipo' => 'precos',
            'representada_id' => $representadaId,
            'arquivo' => basename($destino),
            'nome_original' => mb_substr($nome, 0, 190),
            'usuario_id' => $usuarioId,
        ]);
        return ['importacao_id' => $id] + self::estrutura($lida, 0);
    }

    /** Lê de novo com outra aba */
    public static function trocarAba(int $id, int $aba): array
    {
        $imp = self::buscar($id, 'previa');
        $lida = Planilha::ler(self::pasta() . '/' . $imp['arquivo'], $imp['nome_original'], $aba);
        return ['importacao_id' => $id] + self::estrutura($lida, $aba);
    }

    private static function estrutura(array $lida, int $aba): array
    {
        $linhas = $lida['linhas'];
        if (!$linhas) {
            throw new HttpErro(422, 'A planilha está vazia.');
        }
        $cab = self::acharCabecalho($linhas);
        $cabecalho = $linhas[$cab] ?? [];
        $nCols = 0;
        foreach (array_slice($linhas, max(0, $cab), 200) as $l) {
            $nCols = max($nCols, count($l));
        }
        $colunas = [];
        for ($c = 0; $c < $nCols; $c++) {
            $amostra = [];
            foreach (array_slice($linhas, $cab + 1, 40) as $l) {
                if (isset($l[$c]) && $l[$c] !== null && count($amostra) < 3) {
                    $amostra[] = is_float($l[$c]) ? self::numeroTexto($l[$c]) : (string) $l[$c];
                }
            }
            $colunas[] = [
                'indice' => $c,
                'letra' => Planilha::letra($c),
                'titulo' => isset($cabecalho[$c]) && $cabecalho[$c] !== null ? (string) $cabecalho[$c] : null,
                'amostra' => $amostra,
            ];
        }
        return [
            'abas' => $lida['abas'],
            'aba' => $aba,
            'linha_cabecalho' => $cab + 1, // 1 = primeira linha
            'total_linhas' => count($linhas) - $cab - 1,
            'colunas' => $colunas,
            'sugestao' => self::sugerir($cabecalho, $linhas, $cab),
        ];
    }

    /** linha com mais palavras de cabeçalho conhecidas entre as 25 primeiras */
    private static function acharCabecalho(array $linhas): int
    {
        $melhor = 0;
        $pontos = 0;
        foreach (array_slice($linhas, 0, 25, true) as $i => $l) {
            $p = 0;
            foreach ($l as $v) {
                if (is_string($v) && self::campoDoTitulo($v) !== null) {
                    $p++;
                }
            }
            if ($p > $pontos) {
                $pontos = $p;
                $melhor = $i;
            }
        }
        return $pontos >= 2 ? $melhor : -1; // -1 = planilha sem cabeçalho
    }

    private static function campoDoTitulo(string $titulo): ?string
    {
        $t = Planilha::normalizar($titulo);
        if ($t === '') {
            return null;
        }
        // comparação exata primeiro, depois "começa com"
        foreach (self::CAMPOS as $campo => $palavras) {
            if (in_array($t, $palavras, true)) {
                return $campo;
            }
        }
        foreach (self::CAMPOS as $campo => $palavras) {
            foreach ($palavras as $p) {
                if (strlen($p) >= 3 && (str_starts_with($t, $p . ' ') || str_ends_with($t, ' ' . $p))) {
                    return $campo;
                }
            }
        }
        return null;
    }

    private static function sugerir(array $cabecalho, array $linhas, int $cab): array
    {
        $s = ['codigo' => null, 'descricao' => null, 'ipi' => null, 'unidade' => null, 'categoria' => null, 'desconto_max' => null, 'multiplo' => null, 'precos' => []];
        foreach ($cabecalho as $c => $titulo) {
            if (!is_string($titulo)) {
                continue;
            }
            $campo = self::campoDoTitulo($titulo);
            if ($campo === 'preco') {
                $s['precos'][] = $c;
            } elseif ($campo !== null && $s[$campo] === null) {
                $s[$campo] = $c;
            }
        }
        // sem cabeçalho: código = 1ª coluna, descrição = 1ª coluna de texto longo, preço = última coluna numérica
        if ($s['codigo'] === null || $s['descricao'] === null || !$s['precos']) {
            $amostra = array_slice($linhas, $cab + 1, 30);
            $texto = [];
            $num = [];
            foreach ($amostra as $l) {
                foreach ($l as $c => $v) {
                    if (is_float($v)) {
                        $num[$c] = ($num[$c] ?? 0) + 1;
                    } elseif (is_string($v) && Numero::paraFloat($v) !== null) {
                        $num[$c] = ($num[$c] ?? 0) + 1;
                    } elseif (is_string($v) && mb_strlen($v) > 12) {
                        $texto[$c] = ($texto[$c] ?? 0) + 1;
                    }
                }
            }
            if ($s['descricao'] === null && $texto) {
                arsort($texto);
                $s['descricao'] = array_key_first($texto);
            }
            if ($s['codigo'] === null) {
                $s['codigo'] = 0 === $s['descricao'] ? 1 : 0;
            }
            if (!$s['precos'] && $num) {
                $cands = array_filter(array_keys($num), fn($c) => $c !== $s['codigo']);
                if ($cands) {
                    $s['precos'][] = max($cands);
                }
            }
        }
        return $s;
    }

    // ------------------------------------------------------------------ 2. prévia
    /**
     * $config = [
     *   'aba' => 0, 'linha_cabecalho' => 1,
     *   'colunas' => ['codigo'=>0,'descricao'=>1,'ipi'=>null,'unidade'=>null,'categoria'=>null,'desconto_max'=>null,'multiplo'=>null],
     *   'precos' => [['coluna'=>3,'tabela_id'=>12], ...],
     *   'ausentes' => 'manter' | 'tirar_da_tabela' | 'desativar',
     * ]
     */
    public static function previa(int $id, array $config): array
    {
        $imp = self::buscar($id, 'previa');
        $config = self::validarConfig($config, (int) $imp['representada_id']);
        $calc = self::calcular($imp, $config);
        Db::exec('UPDATE importacoes SET config = ?, linhas_total = ?, linhas_ok = ?, linhas_erro = ? WHERE id = ?', [
            json_encode($config, JSON_UNESCAPED_UNICODE), $calc['resumo']['linhas'], $calc['resumo']['linhas_ok'], count($calc['erros']), $id,
        ]);
        return self::resumoPublico($calc);
    }

    private static function validarConfig(array $c, int $repId): array
    {
        $cols = $c['colunas'] ?? [];
        $int = fn($v) => ($v === null || $v === '') ? null : (int) $v;
        $out = [
            'aba' => (int) ($c['aba'] ?? 0),
            'linha_cabecalho' => (int) ($c['linha_cabecalho'] ?? 1), // 0 = sem cabeçalho
            'colunas' => [],
            'precos' => [],
            'ausentes' => in_array($c['ausentes'] ?? null, ['tirar_da_tabela', 'desativar'], true) ? $c['ausentes'] : 'manter',
        ];
        foreach (array_keys(self::CAMPOS) as $campo) {
            if ($campo !== 'preco') {
                $out['colunas'][$campo] = $int($cols[$campo] ?? null);
            }
        }
        if ($out['colunas']['codigo'] === null) {
            throw new HttpErro(422, 'Escolha a coluna do código do produto.');
        }
        if ($out['colunas']['descricao'] === null) {
            throw new HttpErro(422, 'Escolha a coluna da descrição.');
        }
        $tabelasValidas = array_map('intval', array_column(Db::todos('SELECT id FROM tabelas_preco WHERE representada_id = ?', [$repId]), 'id'));
        $usadas = [];
        foreach ($c['precos'] ?? [] as $p) {
            $col = $int($p['coluna'] ?? null);
            $tab = $int($p['tabela_id'] ?? null);
            if ($col === null || $tab === null) {
                continue;
            }
            if (!in_array($tab, $tabelasValidas, true)) {
                throw new HttpErro(422, 'Tabela de destino não pertence a esta representada.');
            }
            if (isset($usadas[$tab])) {
                throw new HttpErro(422, 'A mesma tabela foi escolhida para duas colunas de preço.');
            }
            $usadas[$tab] = true;
            $out['precos'][] = ['coluna' => $col, 'tabela_id' => $tab];
        }
        if (!$out['precos']) {
            throw new HttpErro(422, 'Escolha ao menos uma coluna de preço e a tabela onde ela entra.');
        }
        return $out;
    }

    /** Calcula todas as mudanças sem gravar */
    private static function calcular(array $imp, array $cfg): array
    {
        $repId = (int) $imp['representada_id'];
        $lida = Planilha::ler(self::pasta() . '/' . $imp['arquivo'], $imp['nome_original'], $cfg['aba']);
        $linhas = $lida['linhas'];
        $inicio = max(0, $cfg['linha_cabecalho']); // índice da 1ª linha de dados
        $col = $cfg['colunas'];

        $produtos = [];
        foreach (Db::todos('SELECT * FROM produtos WHERE representada_id = ?', [$repId]) as $p) {
            $produtos[mb_strtoupper($p['codigo'])] = $p;
        }
        // tabela promocional só mexe em preço: não altera descrição, IPI nem disponibilidade do produto
        $tiposDestino = array_column(Db::todos(
            'SELECT tipo FROM tabelas_preco WHERE id IN (' . implode(',', array_map('intval', array_column($cfg['precos'], 'tabela_id'))) . ')'
        ), 'tipo');
        $soPromocional = $tiposDestino && !array_diff($tiposDestino, ['promocional']);

        $categorias = [];
        foreach (Db::todos('SELECT id, nome FROM categorias_produto WHERE representada_id = ?', [$repId]) as $c) {
            $categorias[mb_strtolower($c['nome'])] = (int) $c['id'];
        }
        $precosAtuais = [];
        foreach ($cfg['precos'] as $p) {
            $precosAtuais[$p['tabela_id']] = [];
            foreach (Db::todos('SELECT produto_id, preco, desconto_max_pct FROM precos WHERE tabela_id = ?', [$p['tabela_id']]) as $r) {
                $precosAtuais[$p['tabela_id']][(int) $r['produto_id']] = $r;
            }
        }

        $itens = [];       // código => dados da planilha
        $erros = [];
        $vistos = [];
        $linhasDados = 0;
        for ($i = $inicio; $i < count($linhas); $i++) {
            $l = $linhas[$i];
            if (!array_filter($l, fn($v) => $v !== null)) {
                continue;
            }
            $linhasDados++;
            $nLinha = $i + 1;
            $codigo = self::texto($l[$col['codigo']] ?? null);
            $descricaoBruta = self::texto($l[$col['descricao']] ?? null);
            if ($codigo === null) {
                // linhas de título/seção no meio da planilha (sem código e sem preço) são ignoradas sem erro
                $temPreco = false;
                foreach ($cfg['precos'] as $p) {
                    if (Numero::paraFloat($l[$p['coluna']] ?? null)) {
                        $temPreco = true;
                    }
                }
                if ($temPreco) {
                    $erros[] = ['linha' => $nLinha, 'codigo' => null, 'motivo' => 'Linha com preço mas sem código.'];
                }
                continue;
            }
            $chave = mb_strtoupper($codigo);
            if (isset($vistos[$chave])) {
                $erros[] = ['linha' => $nLinha, 'codigo' => $codigo, 'motivo' => "Código repetido (já aparece na linha {$vistos[$chave]}). Ficou valendo a primeira."];
                continue;
            }
            if ($descricaoBruta === null) {
                $temPreco = false;
                foreach ($cfg['precos'] as $p) {
                    if (Numero::paraFloat($l[$p['coluna']] ?? null)) {
                        $temPreco = true;
                    }
                }
                if ($temPreco) {
                    $erros[] = ['linha' => $nLinha, 'codigo' => $codigo, 'motivo' => 'Sem descrição.'];
                } else {
                    $linhasDados--; // título de seção (ex.: "MOTOGERADORES"): ignora
                }
                continue;
            }
            $vistos[$chave] = $nLinha;

            $marcas = self::lerMarcas($descricaoBruta);
            $precos = [];
            $semPreco = 0;
            foreach ($cfg['precos'] as $p) {
                $v = Numero::paraFloat($l[$p['coluna']] ?? null);
                if ($v === null || $v <= 0) {
                    $semPreco++;
                    continue;
                }
                $precos[$p['tabela_id']] = round($v, 2);
            }
            if (!$precos) {
                $erros[] = ['linha' => $nLinha, 'codigo' => $codigo, 'motivo' => 'Sem preço (vazio ou zero). Produto não entra nas tabelas.'];
            }
            $ipi = $col['ipi'] !== null ? Numero::paraFloat($l[$col['ipi']] ?? null) : null;
            if ($ipi !== null && $ipi > 0 && $ipi < 1 && is_float($l[$col['ipi']] ?? null)) {
                $ipi *= 100; // Excel guarda 3,25% como 0,0325
            }
            $desc = $col['desconto_max'] !== null ? Numero::paraFloat($l[$col['desconto_max']] ?? null) : null;
            if ($desc !== null && $desc > 0 && $desc < 1 && is_float($l[$col['desconto_max']] ?? null)) {
                $desc *= 100;
            }
            $itens[$chave] = [
                'linha' => $nLinha,
                'codigo' => $codigo,
                'descricao' => mb_substr($marcas['descricao'], 0, 255),
                'ipi' => $ipi,
                'unidade' => $col['unidade'] !== null ? mb_substr((string) self::texto($l[$col['unidade']] ?? null), 0, 10) ?: null : null,
                'categoria' => $col['categoria'] !== null ? self::texto($l[$col['categoria']] ?? null) : null,
                'desconto_max' => $desc,
                'multiplo' => $col['multiplo'] !== null ? Numero::paraFloat($l[$col['multiplo']] ?? null) : null,
                'precos' => $precos,
                'marcas' => $marcas,
            ];
        }

        // compara com o que existe
        $novos = [];
        $alteracoesPreco = [];
        $iguais = 0;
        $produtosAlterados = [];
        foreach ($itens as $chave => $it) {
            $p = $produtos[$chave] ?? null;
            if (!$p) {
                $novos[] = $it;
            } elseif (!$soPromocional) {
                $mud = [];
                if ($p['descricao'] !== $it['descricao']) {
                    $mud['descricao'] = $it['descricao'];
                }
                if ($it['ipi'] !== null && abs((float) $p['ipi_pct'] - $it['ipi']) > 0.0005) {
                    $mud['ipi_pct'] = $it['ipi'];
                }
                if ($it['unidade'] && $p['unidade'] !== $it['unidade']) {
                    $mud['unidade'] = $it['unidade'];
                }
                if ($it['multiplo'] !== null && $it['multiplo'] > 0 && abs((float) $p['multiplo_venda'] - $it['multiplo']) > 0.0005) {
                    $mud['multiplo_venda'] = $it['multiplo'];
                }
                if ((int) $p['ativo'] === 0) {
                    $mud['ativo'] = 1;
                }
                $disp = $it['marcas']['disponibilidade'];
                if ($disp !== $p['disponibilidade'] || $it['marcas']['previsao'] !== $p['previsao_disponivel']) {
                    $mud['disponibilidade'] = $disp;
                    $mud['previsao_disponivel'] = $it['marcas']['previsao'];
                }
                if ($mud) {
                    $produtosAlterados[] = ['produto' => $p, 'mudancas' => $mud, 'item' => $it];
                }
            }
            foreach ($it['precos'] as $tab => $novo) {
                $atual = $p ? ($precosAtuais[$tab][(int) $p['id']] ?? null) : null;
                if ($atual === null) {
                    continue; // preço novo nesta tabela: contado em "novos" ou "entram na tabela"
                }
                if (abs((float) $atual['preco'] - $novo) >= 0.005) {
                    $alteracoesPreco[] = [
                        'codigo' => $it['codigo'], 'descricao' => $it['descricao'], 'tabela_id' => $tab,
                        'de' => (float) $atual['preco'], 'para' => $novo,
                        'variacao_pct' => (float) $atual['preco'] > 0 ? round(($novo / (float) $atual['preco'] - 1) * 100, 2) : null,
                    ];
                } else {
                    $iguais++;
                }
            }
        }
        $entramNaTabela = 0;
        foreach ($itens as $chave => $it) {
            $p = $produtos[$chave] ?? null;
            foreach ($it['precos'] as $tab => $_) {
                if (!$p || !isset($precosAtuais[$tab][(int) $p['id']])) {
                    $entramNaTabela++;
                }
            }
        }

        // ausentes: produtos com preço numa das tabelas de destino que não vieram na planilha
        $ausentes = [];
        if ($cfg['ausentes'] !== 'manter') {
            $chavePorId = [];
            foreach ($produtos as $chave => $pr) {
                $chavePorId[(int) $pr['id']] = $chave;
            }
            foreach ($cfg['precos'] as $pp) {
                foreach ($precosAtuais[$pp['tabela_id']] as $prodId => $_) {
                    $ch = $chavePorId[$prodId] ?? null;
                    if ($ch !== null && !isset($itens[$ch])) {
                        $prod = $produtos[$ch];
                        $ausentes[$prodId] = ['codigo' => $prod['codigo'], 'descricao' => $prod['descricao'], 'produto_id' => $prodId];
                    }
                }
            }
        }

        $substituicoes = [];
        foreach ($itens as $it) {
            if ($it['marcas']['usar_codigo']) {
                $substituicoes[] = ['codigo' => $it['codigo'], 'substituido_por' => $it['marcas']['usar_codigo']];
            }
            if ($it['marcas']['substituiu_codigo']) {
                $substituicoes[] = ['codigo' => $it['marcas']['substituiu_codigo'], 'substituido_por' => $it['codigo']];
            }
        }

        $subiram = count(array_filter($alteracoesPreco, fn($a) => $a['para'] > $a['de']));
        usort($alteracoesPreco, fn($a, $b) => abs($b['variacao_pct'] ?? 0) <=> abs($a['variacao_pct'] ?? 0));

        return [
            'itens' => $itens,
            'produtos' => $produtos,
            'categorias' => $categorias,
            'precosAtuais' => $precosAtuais,
            'novos' => $novos,
            'alteracoesPreco' => $alteracoesPreco,
            'produtosAlterados' => $produtosAlterados,
            'ausentes' => array_values($ausentes),
            'substituicoes' => $substituicoes,
            'erros' => $erros,
            'cfg' => $cfg,
            'resumo' => [
                'so_promocional' => $soPromocional,
                'linhas' => $linhasDados,
                'linhas_ok' => count($itens),
                'produtos_novos' => count($novos),
                'produtos_atualizados' => count($produtosAlterados),
                'precos_entram' => $entramNaTabela,
                'precos_subiram' => $subiram,
                'precos_cairam' => count($alteracoesPreco) - $subiram,
                'precos_iguais' => $iguais,
                'ausentes' => count($ausentes),
                'acao_ausentes' => $cfg['ausentes'],
                'em_falta' => count(array_filter($itens, fn($i) => $i['marcas']['disponibilidade'] === 'em_falta')),
                'substituicoes' => count($substituicoes),
                'erros' => count($erros),
            ],
        ];
    }

    private static function resumoPublico(array $calc): array
    {
        return [
            'resumo' => $calc['resumo'],
            'novos' => array_map(fn($n) => ['linha' => $n['linha'], 'codigo' => $n['codigo'], 'descricao' => $n['descricao'], 'precos' => $n['precos']], array_slice($calc['novos'], 0, 100)),
            'precos_alterados' => array_slice($calc['alteracoesPreco'], 0, 200),
            'ausentes' => array_slice($calc['ausentes'], 0, 100),
            'substituicoes' => array_slice($calc['substituicoes'], 0, 100),
            'erros' => array_slice($calc['erros'], 0, 200),
        ];
    }

    // ------------------------------------------------------------------ 3. confirmar
    public static function confirmar(int $id, int $usuarioId): array
    {
        $imp = self::buscar($id, 'previa');
        if (!$imp['config']) {
            throw new HttpErro(422, 'Confira a prévia antes de importar.');
        }
        $cfg = json_decode($imp['config'], true);
        $repId = (int) $imp['representada_id'];

        return Db::transacao(function () use ($imp, $cfg, $repId, $id, $usuarioId) {
            $calc = self::calcular($imp, $cfg);
            $log = function (string $tipo, int $produtoId, ?int $tabelaId = null, ?array $antes = null) use ($id) {
                Db::inserir('importacao_alteracoes', [
                    'importacao_id' => $id, 'tipo' => $tipo, 'tabela_id' => $tabelaId, 'registro_id' => $produtoId,
                    'antes' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
                ]);
            };
            $catId = function (?string $nome) use (&$calc, $repId): ?int {
                if ($nome === null || $nome === '') {
                    return null;
                }
                $k = mb_strtolower($nome);
                if (!isset($calc['categorias'][$k])) {
                    $calc['categorias'][$k] = Db::inserir('categorias_produto', ['representada_id' => $repId, 'nome' => mb_substr($nome, 0, 120)]);
                }
                return $calc['categorias'][$k];
            };

            $idPorCodigo = [];
            foreach ($calc['produtos'] as $chave => $p) {
                $idPorCodigo[$chave] = (int) $p['id'];
            }

            // produtos novos
            foreach ($calc['novos'] as $n) {
                $pid = Db::inserir('produtos', [
                    'representada_id' => $repId,
                    'codigo' => mb_substr($n['codigo'], 0, 60),
                    'descricao' => $n['descricao'],
                    'unidade' => $n['unidade'] ?: 'UN',
                    'ipi_pct' => $n['ipi'] ?? 0,
                    'multiplo_venda' => ($n['multiplo'] ?? 0) > 0 ? $n['multiplo'] : 1,
                    'categoria_id' => $catId($n['categoria']),
                    'disponibilidade' => $n['marcas']['disponibilidade'],
                    'previsao_disponivel' => $n['marcas']['previsao'],
                ]);
                $idPorCodigo[mb_strtoupper($n['codigo'])] = $pid;
                $log('produto_novo', $pid);
            }

            // produtos alterados
            foreach ($calc['produtosAlterados'] as $a) {
                $p = $a['produto'];
                $antes = array_intersect_key($p, $a['mudancas']);
                if ($a['item']['categoria'] !== null) {
                    $a['mudancas']['categoria_id'] = $catId($a['item']['categoria']);
                    $antes['categoria_id'] = $p['categoria_id'];
                }
                Db::atualizar('produtos', (int) $p['id'], $a['mudancas']);
                $log('produto_alterado', (int) $p['id'], null, $antes);
            }

            // preços
            foreach ($calc['itens'] as $chave => $it) {
                $pid = $idPorCodigo[$chave];
                foreach ($it['precos'] as $tab => $preco) {
                    $atual = $calc['precosAtuais'][$tab][$pid] ?? null;
                    if ($atual === null) {
                        Db::exec('INSERT INTO precos (tabela_id, produto_id, preco, desconto_max_pct) VALUES (?, ?, ?, ?)', [$tab, $pid, $preco, $it['desconto_max']]);
                        $log('preco_novo', $pid, $tab);
                    } elseif (abs((float) $atual['preco'] - $preco) >= 0.005 || ($it['desconto_max'] !== null && (float) $atual['desconto_max_pct'] !== $it['desconto_max'])) {
                        Db::exec('UPDATE precos SET preco = ?, desconto_max_pct = COALESCE(?, desconto_max_pct) WHERE tabela_id = ? AND produto_id = ?', [$preco, $it['desconto_max'], $tab, $pid]);
                        $log('preco_alterado', $pid, $tab, ['preco' => $atual['preco'], 'desconto_max_pct' => $atual['desconto_max_pct']]);
                    }
                }
            }

            // substituições indicadas na descrição
            foreach ($calc['substituicoes'] as $s) {
                $de = $idPorCodigo[mb_strtoupper($s['codigo'])] ?? null;
                $para = $idPorCodigo[mb_strtoupper($s['substituido_por'])] ?? null;
                if ($de && $para && $de !== $para) {
                    $antes = Db::um('SELECT substituido_por_id FROM produtos WHERE id = ?', [$de]);
                    if ((int) ($antes['substituido_por_id'] ?? 0) !== $para) {
                        Db::exec('UPDATE produtos SET substituido_por_id = ? WHERE id = ?', [$para, $de]);
                        $log('produto_alterado', $de, null, ['substituido_por_id' => $antes['substituido_por_id'] ?? null]);
                    }
                }
            }

            // ausentes
            foreach ($calc['ausentes'] as $a) {
                if ($calc['cfg']['ausentes'] === 'tirar_da_tabela') {
                    foreach ($calc['cfg']['precos'] as $pp) {
                        $atual = $calc['precosAtuais'][$pp['tabela_id']][$a['produto_id']] ?? null;
                        if ($atual) {
                            Db::exec('DELETE FROM precos WHERE tabela_id = ? AND produto_id = ?', [$pp['tabela_id'], $a['produto_id']]);
                            $log('preco_removido', $a['produto_id'], $pp['tabela_id'], ['preco' => $atual['preco'], 'desconto_max_pct' => $atual['desconto_max_pct']]);
                        }
                    }
                } elseif ($calc['cfg']['ausentes'] === 'desativar') {
                    Db::exec('UPDATE produtos SET ativo = 0 WHERE id = ? AND ativo = 1', [$a['produto_id']]);
                    $log('produto_desativado', $a['produto_id']);
                }
            }

            Db::exec("UPDATE importacoes SET situacao = 'importada', importada_em = UTC_TIMESTAMP(), relatorio = ?, linhas_ok = ?, linhas_erro = ? WHERE id = ?", [
                json_encode(['resumo' => $calc['resumo'], 'erros' => array_slice($calc['erros'], 0, 500)], JSON_UNESCAPED_UNICODE),
                $calc['resumo']['linhas_ok'], count($calc['erros']), $id,
            ]);
            \Up\Auditoria::registrar($usuarioId, 'importacoes', $id, 'importar', null, $calc['resumo']);
            return ['resumo' => $calc['resumo']];
        });
    }

    // ------------------------------------------------------------------ 4. desfazer
    public static function desfazer(int $id, int $usuarioId): array
    {
        $imp = self::buscar($id, 'importada');
        $posterior = Db::valor(
            "SELECT id FROM importacoes WHERE tipo = 'precos' AND representada_id = ? AND situacao = 'importada' AND id > ?",
            [$imp['representada_id'], $id]
        );
        if ($posterior) {
            throw new HttpErro(422, 'Há uma importação mais nova desta representada. Desfaça a mais nova primeiro.');
        }
        return Db::transacao(function () use ($id, $usuarioId) {
            $alts = Db::todos('SELECT * FROM importacao_alteracoes WHERE importacao_id = ? ORDER BY id DESC', [$id]);
            $n = 0;
            foreach ($alts as $a) {
                $antes = $a['antes'] ? json_decode($a['antes'], true) : null;
                $pid = (int) $a['registro_id'];
                switch ($a['tipo']) {
                    case 'preco_novo':
                        Db::exec('DELETE FROM precos WHERE tabela_id = ? AND produto_id = ?', [$a['tabela_id'], $pid]);
                        break;
                    case 'preco_alterado':
                        Db::exec('UPDATE precos SET preco = ?, desconto_max_pct = ? WHERE tabela_id = ? AND produto_id = ?', [$antes['preco'], $antes['desconto_max_pct'], $a['tabela_id'], $pid]);
                        break;
                    case 'preco_removido':
                        Db::exec('INSERT INTO precos (tabela_id, produto_id, preco, desconto_max_pct) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE preco = VALUES(preco)', [$a['tabela_id'], $pid, $antes['preco'], $antes['desconto_max_pct']]);
                        break;
                    case 'produto_alterado':
                        Db::atualizar('produtos', $pid, $antes);
                        break;
                    case 'produto_desativado':
                        Db::exec('UPDATE produtos SET ativo = 1 WHERE id = ?', [$pid]);
                        break;
                    case 'produto_novo':
                        // se já foi usado em pedido, só desativa
                        if (Db::valor('SELECT 1 FROM pedido_itens WHERE produto_id = ? LIMIT 1', [$pid])) {
                            Db::exec('UPDATE produtos SET ativo = 0 WHERE id = ?', [$pid]);
                        } else {
                            Db::exec('UPDATE produtos SET substituido_por_id = NULL WHERE substituido_por_id = ?', [$pid]);
                            Db::exec('DELETE FROM produtos WHERE id = ?', [$pid]);
                        }
                        break;
                }
                $n++;
            }
            Db::exec("UPDATE importacoes SET situacao = 'desfeita' WHERE id = ?", [$id]);
            \Up\Auditoria::registrar($usuarioId, 'importacoes', $id, 'alterar', null, ['desfeita' => true, 'alteracoes' => $n]);
            return ['ok' => true, 'alteracoes_desfeitas' => $n];
        });
    }

    // ------------------------------------------------------------------ apoio
    /**
     * Lê o que a fábrica (ou a UP) escreve dentro da descrição:
     *   "**SUBSTITUIU O COD. 3160** MOTOG..."  -> este produto substitui o 3160
     *   "*USAR COD. 60733* --- MOTOGERADOR..."  -> este produto foi substituído pelo 60733
     *   "ALISADORA ... - FALT.2ª QUINZENA OUTUBRO" -> em falta, previsão "2ª quinzena outubro"
     */
    public static function lerMarcas(string $descricao): array
    {
        $d = $descricao;
        $usar = null;
        $substituiu = null;
        $disp = 'normal';
        $prev = null;

        if (preg_match('/\*{0,2}\s*USAR\s+(?:O\s+)?C[ÓO]D(?:IGO)?\.?\s*([A-Z0-9.\-\/]+)\s*\*{0,2}/iu', $d, $m)) {
            $usar = rtrim($m[1], '.');
            $d = str_replace($m[0], ' ', $d);
        }
        if (preg_match('/\*{0,2}\s*SUBSTITUI[UÚ]?\s+(?:O\s+)?C[ÓO]D(?:IGO)?\.?\s*([A-Z0-9.\-\/]+)\s*\*{0,2}/iu', $d, $m)) {
            $substituiu = rtrim($m[1], '.');
            $d = str_replace($m[0], ' ', $d);
        }
        if (preg_match('/[-–]?\s*\b(?:FALT(?:A|ANDO)?\.?|EM\s+FALTA|SEM\s+ESTOQUE|INDISPON[IÍ]VEL)\b\s*[:.\-]?\s*(.*)$/iu', $d, $m)) {
            $disp = 'em_falta';
            $p = trim($m[1], " .-–*");
            $prev = $p !== '' ? mb_strtolower(mb_substr($p, 0, 80)) : null;
            $d = str_replace($m[0], ' ', $d);
        } elseif (preg_match('/\bSOB\s+ENCOMENDA\b/iu', $d, $m)) {
            $disp = 'sob_encomenda';
            $d = str_replace($m[0], ' ', $d);
        }
        $d = trim(preg_replace('/\s*-{2,}\s*/', ' ', $d));
        $d = trim(preg_replace('/\s{2,}/', ' ', $d), " -–*");
        return [
            'descricao' => $d !== '' ? $d : trim($descricao),
            'usar_codigo' => $usar,
            'substituiu_codigo' => $substituiu,
            'disponibilidade' => $disp,
            'previsao' => $prev,
        ];
    }

    private static function texto(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_float($v)) {
            return self::numeroTexto($v);
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function numeroTexto(float $v): string
    {
        return floor($v) === $v && abs($v) < 1e15 ? (string) (int) $v : rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }

    private static function buscar(int $id, string $situacao): array
    {
        $imp = Db::um("SELECT * FROM importacoes WHERE id = ? AND tipo = 'precos'", [$id]);
        if (!$imp) {
            throw new HttpErro(404, 'Importação não encontrada.');
        }
        if ($imp['situacao'] !== $situacao) {
            throw new HttpErro(422, $imp['situacao'] === 'importada' ? 'Esta importação já foi gravada.' : ($imp['situacao'] === 'desfeita' ? 'Esta importação foi desfeita.' : 'Esta importação ainda não foi gravada.'));
        }
        return $imp;
    }

    private static function pasta(): string
    {
        $p = rtrim((string) \Up\App::config('app.storage'), '/') . '/importacoes';
        if (!is_dir($p) && !mkdir($p, 0750, true) && !is_dir($p)) {
            throw new HttpErro(500, 'Pasta de importações indisponível.');
        }
        return $p;
    }
}
