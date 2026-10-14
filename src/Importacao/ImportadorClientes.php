<?php
declare(strict_types=1);

namespace Up\Importacao;

use Up\Auditoria;
use Up\Db;
use Up\Http\HttpErro;
use Up\Limpeza;
use Up\Numero;
use Up\Planilha;

/**
 * Importa clientes de planilha (feito para o relatório "Carteira detalhada de clientes" do Mercos,
 * mas reconhece colunas de outras planilhas pelo título). Atualiza quem já existe (pelo CNPJ/CPF)
 * e cria quem não existe. Limpa os dados no caminho e permite desfazer.
 */
final class ImportadorClientes
{
    public const CAMPOS = [
        'razao_social' => ['razao social', 'razao', 'nome', 'cliente', 'nome cliente', 'nome do cliente'],
        'fantasia' => ['nome fantasia', 'fantasia'],
        'documento' => ['cnpj cpf', 'cnpj', 'cpf', 'documento', 'cpf cnpj'],
        'ie' => ['inscricao estadual', 'ie', 'insc estadual'],
        'email' => ['e mail', 'email', 'emails', 'e mails'],
        'telefone' => ['telefone', 'telefones', 'fone', 'celular', 'whatsapp'],
        'cidade' => ['cidade', 'municipio'],
        'uf' => ['estado', 'uf'],
        'bairro' => ['bairro'],
        'cep' => ['cep'],
        'endereco' => ['endereco', 'logradouro', 'rua'],
        'segmento' => ['segmento'],
        'rede' => ['rede'],
        'tags' => ['tags de cliente', 'tags', 'tag'],
        'vendedor' => ['vendedor', 'representante', 'responsavel', 'vendedor responsavel'],
        'data_cadastro' => ['data de cadastro', 'cadastro', 'data cadastro'],
        'ultimo_pedido_data' => ['data do ultimo pedido', 'ultima compra', 'data ultima compra'],
        'ultimo_pedido_valor' => ['valor do ultimo pedido', 'valor ultima compra'],
        'situacao' => ['situacao'],
        'acesso_b2b' => ['acesso b2b'],
    ];

    public static function receber(array $arquivo, int $usuarioId): array
    {
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpErro(422, 'Envie a planilha de clientes.');
        }
        if ($arquivo['size'] > 20 * 1024 * 1024) {
            throw new HttpErro(422, 'Arquivo maior que 20 MB.');
        }
        $nome = (string) $arquivo['name'];
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm', 'xls', 'csv', 'txt'], true)) {
            throw new HttpErro(422, 'Formato não reconhecido. Envie .xls, .xlsx ou .csv.');
        }
        $destino = self::pasta() . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!@move_uploaded_file($arquivo['tmp_name'], $destino) && !@rename($arquivo['tmp_name'], $destino)) {
            throw new HttpErro(500, 'Não foi possível guardar o arquivo.');
        }
        $lida = Planilha::ler($destino, $nome, 0);
        [$cab, $mapa] = self::detectar($lida['linhas']);
        if ($mapa['razao_social'] === null) {
            @unlink($destino);
            throw new HttpErro(422, 'Não encontrei a coluna com o nome do cliente (Razão social). Confira se é a planilha de clientes.');
        }
        $id = Db::inserir('importacoes', [
            'tipo' => 'clientes',
            'arquivo' => basename($destino),
            'nome_original' => mb_substr($nome, 0, 190),
            'usuario_id' => $usuarioId,
            'config' => json_encode(['linha_cabecalho' => $cab + 1, 'colunas' => $mapa]),
        ]);
        $previa = self::previa($id);
        $titulos = $lida['linhas'][$cab] ?? [];
        return [
            'importacao_id' => $id,
            'colunas_reconhecidas' => array_values(array_filter(array_map(
                fn($campo, $col) => $col === null ? null : ['campo' => $campo, 'coluna' => Planilha::letra($col), 'titulo' => $titulos[$col] ?? null],
                array_keys($mapa), $mapa
            ))),
            'colunas_ignoradas' => array_values(array_filter(array_map(
                fn($t, $i) => in_array($i, $mapa, true) || $t === null ? null : $t,
                $titulos, array_keys($titulos)
            ))),
        ] + $previa;
    }

    private static function detectar(array $linhas): array
    {
        $melhor = -1;
        $pontos = 0;
        $mapaMelhor = [];
        foreach (array_slice($linhas, 0, 30, true) as $i => $l) {
            $mapa = array_fill_keys(array_keys(self::CAMPOS), null);
            $p = 0;
            foreach ($l as $c => $v) {
                if (!is_string($v)) {
                    continue;
                }
                $t = Planilha::normalizar($v);
                foreach (self::CAMPOS as $campo => $palavras) {
                    if ($mapa[$campo] === null && in_array($t, $palavras, true)) {
                        $mapa[$campo] = $c;
                        $p++;
                        break;
                    }
                }
            }
            if ($p > $pontos) {
                $pontos = $p;
                $melhor = $i;
                $mapaMelhor = $mapa;
            }
        }
        if ($pontos < 2) {
            throw new HttpErro(422, 'Não reconheci o cabeçalho da planilha. A primeira linha da tabela precisa ter os títulos (Razão social, CNPJ, Cidade…).');
        }
        return [$melhor, $mapaMelhor];
    }

    // ------------------------------------------------------------------ prévia
    public static function previa(int $id): array
    {
        $imp = self::buscar($id, 'previa');
        $calc = self::calcular($imp);
        Db::exec('UPDATE importacoes SET linhas_total = ?, linhas_ok = ?, linhas_erro = ? WHERE id = ?', [
            $calc['resumo']['linhas'], $calc['resumo']['novos'] + $calc['resumo']['atualizados'] + $calc['resumo']['iguais'], count($calc['erros']), $id,
        ]);
        return [
            'resumo' => $calc['resumo'],
            'exemplos' => $calc['exemplos'],
            'avisos' => array_slice($calc['avisos'], 0, 300),
            'erros' => array_slice($calc['erros'], 0, 300),
            'vendedores_nao_encontrados' => $calc['vendedoresNaoEncontrados'],
        ];
    }

    private static function calcular(array $imp): array
    {
        $cfg = json_decode((string) $imp['config'], true);
        $col = $cfg['colunas'];
        $lida = Planilha::ler(self::pasta() . '/' . $imp['arquivo'], $imp['nome_original'], 0);
        $linhas = $lida['linhas'];
        $inicio = (int) $cfg['linha_cabecalho'];
        $v = fn(array $l, string $campo) => $col[$campo] !== null ? ($l[$col[$campo]] ?? null) : null;
        $txt = function ($x): ?string {
            if ($x === null) {
                return null;
            }
            $s = is_float($x) ? (floor($x) === $x ? (string) (int) $x : (string) $x) : trim((string) $x);
            return $s === '' ? null : $s;
        };

        // nome de cidade preferido: a grafia com acento que mais aparece no arquivo
        $grafias = [];
        for ($i = $inicio; $i < count($linhas); $i++) {
            $c = $txt($v($linhas[$i], 'cidade'));
            if ($c) {
                $k = Planilha::normalizar($c);
                $grafias[$k][$c] = ($grafias[$k][$c] ?? 0) + 1;
            }
        }
        $preferidos = [];
        foreach ($grafias as $k => $vs) {
            uksort($vs, function ($a, $b) use ($vs) {
                $acA = preg_match('/[^\x00-\x7F]/', $a);
                $acB = preg_match('/[^\x00-\x7F]/', $b);
                return [$acB, $vs[$b]] <=> [$acA, $vs[$a]];
            });
            $escolhida = array_key_first($vs);
            $preferidos[$k] = Limpeza::cidade($escolhida);
            // se a melhor grafia do arquivo não tem acento, deixa a regra de acentos agir
            if (!preg_match('/[^\x00-\x7F]/', $escolhida)) {
                unset($preferidos[$k]);
            }
        }

        $existentes = [];
        foreach (Db::todos('SELECT * FROM clientes WHERE cnpj_cpf IS NOT NULL') as $c) {
            $existentes[$c['cnpj_cpf']] = $c;
        }
        $contatosExistentes = [];
        foreach (Db::todos('SELECT cliente_id, telefone, email FROM cliente_contatos') as $c) {
            if ($c['telefone']) {
                $contatosExistentes[$c['cliente_id']]['t' . Limpeza::soDigitos($c['telefone'])] = true;
            }
            if ($c['email']) {
                $contatosExistentes[$c['cliente_id']]['e' . $c['email']] = true;
            }
        }
        $segmentos = [];
        foreach (Db::todos('SELECT id, nome FROM segmentos') as $s) {
            $segmentos[Planilha::normalizar($s['nome'])] = (int) $s['id'];
        }
        $redes = [];
        foreach (Db::todos('SELECT id, nome FROM redes') as $s) {
            $redes[Planilha::normalizar($s['nome'])] = (int) $s['id'];
        }
        $tags = [];
        foreach (Db::todos('SELECT id, nome FROM tags') as $s) {
            $tags[Planilha::normalizar($s['nome'])] = (int) $s['id'];
        }
        $usuarios = Db::todos("SELECT id, nome FROM usuarios WHERE perfil <> 'tv'");

        $registros = [];
        $erros = [];
        $avisos = [];
        $vistos = [];
        $vendNao = [];
        $segNovos = [];
        $tagsNovas = [];
        $st = ['contribuinte_pelo_nome' => 0, 'contribuinte_pela_ie' => 0, 'contribuinte_sim' => 0, 'contribuinte_nao' => 0, 'emails_descartados' => 0, 'telefones' => 0, 'cidades_padronizadas' => 0, 'doc_invalido' => 0, 'sem_documento' => 0];
        $linhasDados = 0;

        for ($i = $inicio; $i < count($linhas); $i++) {
            $l = $linhas[$i];
            if (!array_filter($l, fn($x) => $x !== null)) {
                continue;
            }
            $linhasDados++;
            $n = $i + 1;
            $razaoBruta = $txt($v($l, 'razao_social'));
            if ($razaoBruta === null) {
                $erros[] = ['linha' => $n, 'cliente' => null, 'motivo' => 'Sem razão social.'];
                continue;
            }
            [$razao, $contrA] = Limpeza::marcaContribuinte($razaoBruta);
            [$fantasia, $contrB] = Limpeza::marcaContribuinte($txt($v($l, 'fantasia')));
            $doc = Limpeza::documento($v($l, 'documento'));
            if ($doc === null) {
                $st['sem_documento']++;
                $avisos[] = ['linha' => $n, 'cliente' => $razao, 'motivo' => 'Sem CNPJ/CPF: entra como cliente novo.'];
            } elseif (!$doc[2]) {
                $st['doc_invalido']++;
                $avisos[] = ['linha' => $n, 'cliente' => $razao, 'motivo' => "CNPJ/CPF {$doc[0]} com dígito verificador inválido. Importado assim mesmo; confira."];
            }
            if ($doc && isset($vistos[$doc[0]])) {
                $erros[] = ['linha' => $n, 'cliente' => $razao, 'motivo' => "CNPJ/CPF repetido (já aparece na linha {$vistos[$doc[0]]})."];
                continue;
            }
            if ($doc) {
                $vistos[$doc[0]] = $n;
            }

            $ie = Limpeza::inscricaoEstadual($txt($v($l, 'ie')));
            $contrib = $contrA ?? $contrB;
            if ($contrib !== null) {
                $st['contribuinte_pelo_nome']++;
            } elseif ($ie !== null) {
                $contrib = $ie !== 'ISENTO'; // tem inscrição estadual = contribuinte; "ISENTO" = não contribuinte
                $st['contribuinte_pela_ie']++;
            }
            if ($contrib === true) {
                $st['contribuinte_sim']++;
            } elseif ($contrib === false) {
                $st['contribuinte_nao']++;
            }

            $emailBruto = (string) $txt($v($l, 'email'));
            $emails = Limpeza::emails($emailBruto);
            $qtdBruto = count(array_filter(preg_split('/[,;\s\/]+/', $emailBruto)));
            $st['emails_descartados'] += max(0, $qtdBruto - count($emails));
            $tels = Limpeza::telefones($txt($v($l, 'telefone')));
            $st['telefones'] += count($tels);

            $cidadeBruta = $txt($v($l, 'cidade'));
            $cidade = $cidadeBruta ? Limpeza::cidade($cidadeBruta, $preferidos) : null;
            if ($cidade !== null && $cidade !== $cidadeBruta) {
                $st['cidades_padronizadas']++;
            }
            [$logr, $num, $compl] = Limpeza::endereco($txt($v($l, 'endereco')));

            $segNome = $txt($v($l, 'segmento'));
            $segKey = $segNome ? Planilha::normalizar($segNome) : null;
            if ($segKey && !isset($segmentos[$segKey])) {
                $segNovos[$segKey] = $segNome;
            }
            $redeNome = $txt($v($l, 'rede'));
            $tagNomes = [];
            foreach (preg_split('/,(?![^(]*\))/', (string) $txt($v($l, 'tags'))) as $t) {
                $t = trim($t);
                if ($t !== '') {
                    $tagNomes[] = $t;
                    if (!isset($tags[Planilha::normalizar($t)])) {
                        $tagsNovas[Planilha::normalizar($t)] = $t;
                    }
                }
            }
            $vendNome = $txt($v($l, 'vendedor'));
            $vendId = null;
            if ($vendNome) {
                $vendId = self::acharUsuario($vendNome, $usuarios);
                if (!$vendId) {
                    $vendNao[$vendNome] = ($vendNao[$vendNome] ?? 0) + 1;
                }
            }
            $b2b = $txt($v($l, 'acesso_b2b'));

            $dados = array_filter([
                'tipo_pessoa' => $doc[1] ?? null,
                'cnpj_cpf' => $doc[0] ?? null,
                'razao_social' => mb_substr($razao ?? $razaoBruta, 0, 190),
                'fantasia' => $fantasia ? mb_substr($fantasia, 0, 190) : null,
                'inscricao_estadual' => $ie,
                'contribuinte' => $contrib === null ? null : ($contrib ? 1 : 0),
                'cep' => Limpeza::cep($txt($v($l, 'cep'))),
                'endereco' => $logr ? mb_substr($logr, 0, 190) : null,
                'numero' => $num,
                'complemento' => $compl,
                'bairro' => ($b = $txt($v($l, 'bairro'))) ? mb_substr($b, 0, 80) : null,
                'municipio_nome' => $cidade,
                'uf' => ($u = $txt($v($l, 'uf'))) ? mb_strtoupper(mb_substr($u, 0, 2)) : null,
                'vendedor_id' => $vendId,
                'data_cadastro' => Limpeza::data($v($l, 'data_cadastro')),
                'ultimo_pedido_data' => Limpeza::data($v($l, 'ultimo_pedido_data')),
                'ultimo_pedido_valor' => ($vv = $v($l, 'ultimo_pedido_valor')) !== null ? Numero::paraFloat($vv) : null,
                'mercos_situacao' => $txt($v($l, 'situacao')),
                'acesso_b2b' => $b2b === null ? null : (int) (Planilha::normalizar($b2b) === 'sim'),
            ], fn($x) => $x !== null);

            $existente = $doc ? ($existentes[$doc[0]] ?? null) : null;
            $mudancas = $dados;
            if ($existente) {
                foreach ($dados as $k => $val) {
                    $atual = $existente[$k];
                    if ($atual !== null && (string) $atual === (string) $val) {
                        unset($mudancas[$k]);
                    } elseif (is_float($val) && $atual !== null && abs((float) $atual - $val) < 0.005) {
                        unset($mudancas[$k]);
                    }
                }
            }
            $contatosNovos = [];
            $jaTem = $existente ? ($contatosExistentes[$existente['id']] ?? []) : [];
            foreach ($tels as $k => $t) {
                if (!isset($jaTem['t' . Limpeza::soDigitos($t['telefone'])])) {
                    $contatosNovos[] = ['nome' => $t['nome'], 'telefone' => $t['telefone'], 'whatsapp' => $t['celular'] ? 1 : 0, 'email' => null];
                }
            }
            foreach ($emails as $k => $e) {
                if (isset($jaTem['e' . $e])) {
                    continue;
                }
                // primeiro e-mail vai junto do primeiro telefone novo sem nome
                $par = null;
                foreach ($contatosNovos as $ci => $c) {
                    if ($c['email'] === null && $c['nome'] === null) {
                        $par = $ci;
                        break;
                    }
                }
                if ($par !== null && $k === 0) {
                    $contatosNovos[$par]['email'] = $e;
                } else {
                    $contatosNovos[] = ['nome' => null, 'telefone' => null, 'whatsapp' => 0, 'email' => $e];
                }
            }

            $registros[] = [
                'linha' => $n,
                'existente' => $existente,
                'dados' => $dados,
                'mudancas' => $mudancas,
                'segmento' => $segNome,
                'rede' => $redeNome,
                'tags' => $tagNomes,
                'contatos' => $contatosNovos,
                'original' => ['razao' => $razaoBruta, 'telefone' => $txt($v($l, 'telefone')), 'email' => $emailBruto ?: null, 'cidade' => $cidadeBruta],
            ];
        }

        $novos = array_filter($registros, fn($r) => !$r['existente']);
        $atualizados = array_filter($registros, fn($r) => $r['existente'] && ($r['mudancas'] || $r['contatos']));
        // exemplos que mostram a limpeza
        $exemplos = [];
        foreach ($registros as $r) {
            $limpou = $r['original']['razao'] !== $r['dados']['razao_social']
                || ($r['original']['cidade'] && $r['original']['cidade'] !== ($r['dados']['municipio_nome'] ?? null))
                || count($r['contatos']) > 1;
            if ($limpou && count($exemplos) < 12) {
                $exemplos[] = [
                    'linha' => $r['linha'],
                    'antes' => $r['original'],
                    'depois' => [
                        'razao' => $r['dados']['razao_social'],
                        'contribuinte' => $r['dados']['contribuinte'] ?? null,
                        'cidade' => $r['dados']['municipio_nome'] ?? null,
                        'contatos' => $r['contatos'],
                    ],
                ];
            }
        }

        return [
            'registros' => $registros,
            'segNovos' => $segNovos,
            'tagsNovas' => $tagsNovas,
            'segmentos' => $segmentos,
            'redes' => $redes,
            'tagsMapa' => $tags,
            'erros' => $erros,
            'avisos' => $avisos,
            'exemplos' => $exemplos,
            'vendedoresNaoEncontrados' => array_map(fn($k, $q) => ['nome' => $k, 'clientes' => $q], array_keys($vendNao), $vendNao),
            'resumo' => [
                'linhas' => $linhasDados,
                'novos' => count($novos),
                'atualizados' => count($atualizados),
                'iguais' => count($registros) - count($novos) - count($atualizados),
                'erros' => count($erros),
                'avisos' => count($avisos),
                'segmentos_novos' => array_values($segNovos),
                'tags_novas' => array_values($tagsNovas),
                'tem_coluna_vendedor' => $col['vendedor'] !== null,
            ] + $st,
        ];
    }

    // ------------------------------------------------------------------ gravar
    public static function confirmar(int $id, int $usuarioId): array
    {
        $imp = self::buscar($id, 'previa');
        @set_time_limit(300);
        return Db::transacao(function () use ($imp, $id, $usuarioId) {
            $calc = self::calcular($imp);
            $log = function (string $tipo, int $clienteId, ?array $antes) use ($id) {
                Db::inserir('importacao_alteracoes', [
                    'importacao_id' => $id, 'tipo' => $tipo, 'registro_id' => $clienteId,
                    'antes' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
                ]);
            };
            $seg = $calc['segmentos'];
            foreach ($calc['segNovos'] as $k => $nome) {
                $seg[$k] = Db::inserir('segmentos', ['nome' => mb_substr($nome, 0, 120)]);
            }
            $tagsMapa = $calc['tagsMapa'];
            foreach ($calc['tagsNovas'] as $k => $nome) {
                $tagsMapa[$k] = Db::inserir('tags', ['nome' => mb_substr($nome, 0, 120)]);
            }
            $redes = $calc['redes'];

            $insCont = Db::pdo()->prepare('INSERT INTO cliente_contatos (cliente_id, nome, telefone, whatsapp, email, principal) VALUES (?, ?, ?, ?, ?, ?)');
            $insTag = Db::pdo()->prepare('INSERT IGNORE INTO cliente_tags (cliente_id, tag_id) VALUES (?, ?)');
            $novos = 0;
            $atualizados = 0;
            foreach ($calc['registros'] as $r) {
                $d = $r['dados'];
                if ($r['segmento']) {
                    $d['segmento_id'] = $seg[Planilha::normalizar($r['segmento'])] ?? null;
                }
                if ($r['rede']) {
                    $rk = Planilha::normalizar($r['rede']);
                    $d['rede_id'] = $redes[$rk] ?? ($redes[$rk] = Db::inserir('redes', ['nome' => mb_substr($r['rede'], 0, 120)]));
                }
                $contatosCriados = [];
                if (!$r['existente']) {
                    $cid = Db::inserir('clientes', $d);
                    $novos++;
                    $antes = null;
                } else {
                    $cid = (int) $r['existente']['id'];
                    $mud = $r['mudancas'];
                    if (isset($d['segmento_id']) && (int) $r['existente']['segmento_id'] !== $d['segmento_id']) {
                        $mud['segmento_id'] = $d['segmento_id'];
                    }
                    if (isset($d['rede_id']) && (int) $r['existente']['rede_id'] !== $d['rede_id']) {
                        $mud['rede_id'] = $d['rede_id'];
                    }
                    $antes = ['campos' => array_intersect_key($r['existente'], $mud)];
                    if ($mud) {
                        Db::atualizar('clientes', $cid, $mud);
                    }
                    if ($mud || $r['contatos']) {
                        $atualizados++;
                    }
                }
                $principal = !$r['existente'];
                foreach ($r['contatos'] as $c) {
                    $insCont->execute([$cid, $c['nome'], $c['telefone'], $c['whatsapp'], $c['email'], $principal ? 1 : 0]);
                    $contatosCriados[] = (int) Db::pdo()->lastInsertId();
                    $principal = false;
                }
                $tagsCriadas = [];
                foreach ($r['tags'] as $t) {
                    $tid = $tagsMapa[Planilha::normalizar($t)] ?? null;
                    if ($tid) {
                        $insTag->execute([$cid, $tid]);
                        if ($insTag->rowCount()) {
                            $tagsCriadas[] = $tid;
                        }
                    }
                }
                if (!$r['existente']) {
                    $log('cliente_novo', $cid, null);
                } elseif ($antes['campos'] || $contatosCriados || $tagsCriadas) {
                    $antes['contatos_criados'] = $contatosCriados;
                    $antes['tags_criadas'] = $tagsCriadas;
                    $log('cliente_alterado', $cid, $antes);
                }
            }
            Db::exec("UPDATE importacoes SET situacao = 'importada', importada_em = UTC_TIMESTAMP(), relatorio = ?, linhas_ok = ? WHERE id = ?", [
                json_encode(['resumo' => $calc['resumo'], 'erros' => array_slice($calc['erros'], 0, 500), 'avisos' => array_slice($calc['avisos'], 0, 500)], JSON_UNESCAPED_UNICODE),
                $novos + $atualizados, $id,
            ]);
            Auditoria::registrar($usuarioId, 'importacoes', $id, 'importar', null, ['novos' => $novos, 'atualizados' => $atualizados]);
            return ['novos' => $novos, 'atualizados' => $atualizados, 'resumo' => $calc['resumo']];
        });
    }

    public static function desfazer(int $id, int $usuarioId): array
    {
        self::buscar($id, 'importada');
        if (Db::valor("SELECT id FROM importacoes WHERE tipo = 'clientes' AND situacao = 'importada' AND id > ?", [$id])) {
            throw new HttpErro(422, 'Há uma importação de clientes mais nova. Desfaça a mais nova primeiro.');
        }
        return Db::transacao(function () use ($id, $usuarioId) {
            $n = 0;
            $mantidos = 0;
            foreach (Db::todos('SELECT * FROM importacao_alteracoes WHERE importacao_id = ? ORDER BY id DESC', [$id]) as $a) {
                $cid = (int) $a['registro_id'];
                if ($a['tipo'] === 'cliente_novo') {
                    $usado = Db::valor('SELECT 1 FROM pedidos WHERE cliente_id = ? UNION SELECT 1 FROM visitas WHERE cliente_id = ? LIMIT 1', [$cid, $cid]);
                    if ($usado) {
                        $mantidos++; // já tem pedido/visita: não apaga
                        continue;
                    }
                    Db::exec('DELETE FROM clientes WHERE id = ?', [$cid]);
                } elseif ($a['tipo'] === 'cliente_alterado') {
                    $antes = json_decode((string) $a['antes'], true);
                    if (!empty($antes['campos'])) {
                        Db::atualizar('clientes', $cid, $antes['campos']);
                    }
                    foreach ($antes['contatos_criados'] ?? [] as $cc) {
                        Db::exec('DELETE FROM cliente_contatos WHERE id = ?', [$cc]);
                    }
                    foreach ($antes['tags_criadas'] ?? [] as $t) {
                        Db::exec('DELETE FROM cliente_tags WHERE cliente_id = ? AND tag_id = ?', [$cid, $t]);
                    }
                }
                $n++;
            }
            Db::exec("UPDATE importacoes SET situacao = 'desfeita' WHERE id = ?", [$id]);
            Auditoria::registrar($usuarioId, 'importacoes', $id, 'alterar', null, ['desfeita' => true]);
            return ['ok' => true, 'desfeitos' => $n, 'mantidos_por_ter_pedido' => $mantidos];
        });
    }

    private static function acharUsuario(string $nome, array $usuarios): ?int
    {
        $alvo = Planilha::normalizar(preg_replace('/\s*-\s*\(?\d.*$/', '', $nome)); // "Sara Mistro - (11) 9..." -> "sara mistro"
        foreach ($usuarios as $u) {
            if (Planilha::normalizar($u['nome']) === $alvo) {
                return (int) $u['id'];
            }
        }
        foreach ($usuarios as $u) {
            $un = Planilha::normalizar($u['nome']);
            if ($alvo !== '' && (str_starts_with($un, $alvo . ' ') || str_starts_with($alvo, $un . ' '))) {
                return (int) $u['id'];
            }
        }
        return null;
    }

    private static function buscar(int $id, string $situacao): array
    {
        $imp = Db::um("SELECT * FROM importacoes WHERE id = ? AND tipo = 'clientes'", [$id]);
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
