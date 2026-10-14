<?php
declare(strict_types=1);

namespace Up;

use Up\Http\HttpErro;

/**
 * Leitor de planilhas sem dependências: .xlsx (Excel 2007+) e .csv (vírgula, ponto e vírgula ou tab;
 * UTF-8 ou Windows-1252). Arquivos .xls antigos não são lidos: peça para salvar como .xlsx.
 * Devolve uma lista de linhas, cada linha uma lista de valores (string|float|null).
 */
final class Planilha
{
    public const MAX_LINHAS = 50000;

    /** @return array{abas: string[], linhas: array<int, array<int, string|float|null>>} */
    public static function ler(string $caminho, string $nomeOriginal, ?int $aba = null): array
    {
        $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        return match ($ext) {
            'xlsx', 'xlsm' => self::lerXlsx($caminho, $aba ?? 0),
            'csv', 'txt' => ['abas' => ['Planilha'], 'linhas' => self::lerCsv($caminho)],
            'xls' => throw new HttpErro(422, 'Arquivo .xls antigo. Abra no Excel e salve como .xlsx (Pasta de Trabalho do Excel).'),
            default => throw new HttpErro(422, 'Formato não reconhecido. Envie .xlsx ou .csv.'),
        };
    }

    private static function lerCsv(string $caminho): array
    {
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false) {
            throw new HttpErro(422, 'Não foi possível ler o arquivo.');
        }
        if (str_starts_with($conteudo, "\xEF\xBB\xBF")) {
            $conteudo = substr($conteudo, 3);
        }
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $amostra = substr($conteudo, 0, 5000);
        $sep = ';';
        $max = -1;
        foreach ([';', ',', "\t"] as $s) {
            $n = substr_count($amostra, $s);
            if ($n > $max) {
                $max = $n;
                $sep = $s;
            }
        }
        $linhas = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $conteudo);
        rewind($fh);
        while (($l = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            if ($l === [null]) {
                continue;
            }
            $linhas[] = array_map(fn($v) => $v === null || trim($v) === '' ? null : trim($v), $l);
            if (count($linhas) > self::MAX_LINHAS) {
                throw new HttpErro(422, 'Planilha grande demais (mais de ' . self::MAX_LINHAS . ' linhas). Divida em partes.');
            }
        }
        fclose($fh);
        return $linhas;
    }

    private static function lerXlsx(string $caminho, int $abaIndice): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($caminho) !== true) {
            throw new HttpErro(422, 'O arquivo não é um .xlsx válido.');
        }
        try {
            // abas e seus arquivos
            $wb = self::xml($zip, 'xl/workbook.xml');
            $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
            if (!$wb || !$rels) {
                throw new HttpErro(422, 'O arquivo não é um .xlsx válido.');
            }
            $alvos = [];
            foreach ($rels->children() as $r) {
                $alvos[(string) $r['Id']] = (string) $r['Target'];
            }
            $abas = [];
            $arquivos = [];
            $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($wb->xpath('//m:sheets/m:sheet') as $s) {
                $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $alvo = $alvos[$rid] ?? '';
                $alvo = str_starts_with($alvo, '/') ? ltrim($alvo, '/') : 'xl/' . $alvo;
                $abas[] = (string) $s['name'];
                $arquivos[] = $alvo;
            }
            if (!isset($arquivos[$abaIndice])) {
                throw new HttpErro(422, 'Aba não encontrada na planilha.');
            }

            // textos compartilhados
            $textos = [];
            $ss = $zip->getFromName('xl/sharedStrings.xml');
            if ($ss !== false) {
                $r = new \XMLReader();
                $r->XML($ss, null, LIBXML_NONET | LIBXML_COMPACT);
                $atual = null;
                while ($r->read()) {
                    if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                        $atual = '';
                    } elseif ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 't' && $atual !== null) {
                        $atual .= $r->readString();
                    } elseif ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'rPh') {
                        $r->next(); // ignora guia fonético
                    } elseif ($r->nodeType === \XMLReader::END_ELEMENT && $r->localName === 'si') {
                        $textos[] = $atual;
                        $atual = null;
                    }
                }
                $r->close();
            }

            $xmlAba = $zip->getFromName($arquivos[$abaIndice]);
            if ($xmlAba === false) {
                throw new HttpErro(422, 'Não foi possível ler a aba da planilha.');
            }
            $linhas = self::lerAba($xmlAba, $textos);
            return ['abas' => $abas, 'linhas' => $linhas];
        } finally {
            $zip->close();
        }
    }

    private static function lerAba(string $xml, array $textos): array
    {
        $r = new \XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $linhas = [];
        $linha = null;
        $numLinha = 0;
        while ($r->read()) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'row') {
                $n = (int) $r->getAttribute('r') ?: $numLinha + 1;
                // linhas vazias puladas no XML viram linhas vazias aqui, para manter a numeração
                while ($numLinha < $n - 1) {
                    $linhas[] = [];
                    $numLinha++;
                }
                $linha = [];
                $numLinha = $n;
                if ($r->isEmptyElement) {
                    $linhas[] = [];
                    $linha = null;
                }
            } elseif ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'c' && $linha !== null) {
                $ref = (string) $r->getAttribute('r');
                $tipo = (string) $r->getAttribute('t');
                $col = $ref !== '' ? self::coluna($ref) : count($linha);
                $valor = null;
                if (!$r->isEmptyElement) {
                    $no = new \SimpleXMLElement($r->readOuterXml());
                    if ($tipo === 'inlineStr') {
                        $valor = trim((string) implode('', array_map('strval', $no->xpath('.//*[local-name()="t"]'))));
                    } else {
                        $v = isset($no->v) ? (string) $no->v : null;
                        if ($v !== null) {
                            $valor = match ($tipo) {
                                's' => $textos[(int) $v] ?? null,
                                'str', 'e' => $v,
                                'b' => $v === '1' ? 'VERDADEIRO' : 'FALSO',
                                default => is_numeric($v) ? (float) $v : $v,
                            };
                        }
                    }
                    if (is_string($valor)) {
                        $valor = trim($valor);
                        if ($valor === '') {
                            $valor = null;
                        }
                    }
                }
                for ($i = count($linha); $i < $col; $i++) {
                    $linha[$i] = null;
                }
                $linha[$col] = $valor;
            } elseif ($r->nodeType === \XMLReader::END_ELEMENT && $r->localName === 'row' && $linha !== null) {
                $linhas[] = $linha;
                $linha = null;
                if (count($linhas) > self::MAX_LINHAS) {
                    throw new HttpErro(422, 'Planilha grande demais (mais de ' . self::MAX_LINHAS . ' linhas). Divida em partes.');
                }
            }
        }
        $r->close();
        // tira linhas vazias do fim
        while ($linhas && !array_filter(end($linhas), fn($v) => $v !== null)) {
            array_pop($linhas);
        }
        return $linhas;
    }

    /** "AB12" -> 27 (índice começando em 0) */
    public static function coluna(string $ref): int
    {
        $letras = preg_replace('/\d+/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split($letras) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }
        return $n - 1;
    }

    public static function letra(int $indice): string
    {
        $s = '';
        $n = $indice + 1;
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    private static function xml(\ZipArchive $zip, string $nome): ?\SimpleXMLElement
    {
        $c = $zip->getFromName($nome);
        if ($c === false) {
            return null;
        }
        $x = simplexml_load_string($c, \SimpleXMLElement::class, LIBXML_NONET);
        return $x === false ? null : $x;
    }

    /** Normaliza texto de cabeçalho para comparação: minúsculo, sem acento, sem pontuação */
    public static function normalizar(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return trim(preg_replace('/[^a-z0-9%]+/', ' ', $s));
    }
}
