<?php
declare(strict_types=1);

namespace Up;

use Up\Http\HttpErro;

/**
 * Leitor de .xls (Excel 97-2003, formato BIFF8 dentro de um arquivo OLE/CFB), sem dependências.
 * Lê valores: textos (SST, LABEL), números (NUMBER, RK, MULRK), resultados de fórmula e booleanos.
 * É o formato em que o Mercos exporta os relatórios.
 */
final class PlanilhaXls
{
    private string $dados;
    private int $tamSetor;
    private int $tamMini;
    private int $corteMini;
    private array $fat = [];
    private array $miniFat = [];
    private string $miniStream = '';

    /** @return array{abas: string[], linhas: array} */
    public static function ler(string $caminho, int $aba): array
    {
        $bin = file_get_contents($caminho);
        if ($bin === false || substr($bin, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            // alguns sistemas salvam HTML ou texto com extensão .xls
            throw new HttpErro(422, 'Este .xls não está no formato do Excel. Abra no Excel e salve como .xlsx.');
        }
        $l = new self($bin);
        $wb = $l->fluxo(['Workbook', 'Book']);
        return self::lerWorkbook($wb, $aba);
    }

    private function __construct(string $bin)
    {
        $this->dados = $bin;
        $this->tamSetor = 1 << $this->u16(0x1E);
        $this->tamMini = 1 << $this->u16(0x20);
        $this->corteMini = $this->u32(0x38);

        // DIFAT: 109 entradas no cabeçalho + setores DIFAT extras
        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $s = $this->u32(0x4C + $i * 4);
            if ($s >= 0xFFFFFFFA) {
                break;
            }
            $difat[] = $s;
        }
        $prox = $this->u32(0x44);
        $n = $this->u32(0x48);
        $porSetor = intdiv($this->tamSetor, 4) - 1;
        for ($k = 0; $k < $n && $prox < 0xFFFFFFFA; $k++) {
            $off = $this->offSetor($prox);
            for ($i = 0; $i < $porSetor; $i++) {
                $s = $this->u32($off + $i * 4);
                if ($s < 0xFFFFFFFA) {
                    $difat[] = $s;
                }
            }
            $prox = $this->u32($off + $porSetor * 4);
        }
        foreach ($difat as $s) {
            $off = $this->offSetor($s);
            $bloco = unpack('V*', substr($this->dados, $off, $this->tamSetor)) ?: [];
            foreach ($bloco as $v) {
                $this->fat[] = $v;
            }
        }

        // diretório
        $dir = $this->cadeia($this->u32(0x30));
        $this->entradas = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $tamNome = unpack('v', substr($dir, $o + 64, 2))[1];
            if ($tamNome < 2) {
                continue;
            }
            $nome = mb_convert_encoding(substr($dir, $o, $tamNome - 2), 'UTF-8', 'UTF-16LE');
            $this->entradas[] = [
                'nome' => $nome,
                'tipo' => ord($dir[$o + 66]),
                'inicio' => unpack('V', substr($dir, $o + 116, 4))[1],
                'tamanho' => unpack('V', substr($dir, $o + 120, 4))[1],
            ];
        }
        $raiz = $this->entradas[0] ?? null;
        if ($raiz && $raiz['tipo'] === 5) {
            $this->miniStream = $this->cadeia($raiz['inicio']);
            $mf = $this->u32(0x3C);
            if ($mf < 0xFFFFFFFA) {
                $this->miniFat = array_values(unpack('V*', $this->cadeia($mf)) ?: []);
            }
        }
    }

    private array $entradas = [];

    private function fluxo(array $nomes): string
    {
        foreach ($this->entradas as $e) {
            if ($e['tipo'] === 2 && in_array($e['nome'], $nomes, true)) {
                if ($e['tamanho'] < $this->corteMini) {
                    return $this->cadeiaMini($e['inicio'], $e['tamanho']);
                }
                return substr($this->cadeia($e['inicio']), 0, $e['tamanho']);
            }
        }
        throw new HttpErro(422, 'Não foi possível ler este .xls. Abra no Excel e salve como .xlsx.');
    }

    private function cadeia(int $inicio): string
    {
        $out = '';
        $s = $inicio;
        $guarda = 0;
        while ($s < 0xFFFFFFFA && $guarda++ < 1_000_000) {
            $out .= substr($this->dados, $this->offSetor($s), $this->tamSetor);
            $s = $this->fat[$s] ?? 0xFFFFFFFE;
        }
        return $out;
    }

    private function cadeiaMini(int $inicio, int $tamanho): string
    {
        $out = '';
        $s = $inicio;
        $guarda = 0;
        while ($s < 0xFFFFFFFA && $guarda++ < 1_000_000) {
            $out .= substr($this->miniStream, $s * $this->tamMini, $this->tamMini);
            $s = $this->miniFat[$s] ?? 0xFFFFFFFE;
        }
        return substr($out, 0, $tamanho);
    }

    private function offSetor(int $s): int
    {
        return ($s + 1) * $this->tamSetor;
    }

    private function u16(int $o): int
    {
        return unpack('v', substr($this->dados, $o, 2))[1];
    }

    private function u32(int $o): int
    {
        return unpack('V', substr($this->dados, $o, 4))[1];
    }

    // ------------------------------------------------------------------ BIFF8
    private static function lerWorkbook(string $wb, int $abaIndice): array
    {
        $len = strlen($wb);
        $abas = [];
        $sst = [];
        $p = 0;
        // globais: BOUNDSHEET e SST
        while ($p + 4 <= $len) {
            ['t' => $t, 'n' => $n] = unpack('vt/vn', substr($wb, $p, 4));
            $corpo = substr($wb, $p + 4, $n);
            if ($t === 0x0085) {
                $pos = unpack('V', substr($corpo, 0, 4))[1];
                $cch = ord($corpo[6]);
                $abas[] = ['nome' => self::texto(substr($corpo, 8), $cch, ord($corpo[7]) & 1), 'pos' => $pos, 'tipo' => ord($corpo[5])];
            } elseif ($t === 0x00FC) {
                // junta SST + CONTINUEs guardando onde cada pedaço começa
                $partes = [$corpo];
                $q = $p + 4 + $n;
                while ($q + 4 <= $len) {
                    ['t' => $t2, 'n' => $n2] = unpack('vt/vn', substr($wb, $q, 4));
                    if ($t2 !== 0x003C) {
                        break;
                    }
                    $partes[] = substr($wb, $q + 4, $n2);
                    $q += 4 + $n2;
                }
                $sst = self::lerSst($partes);
            } elseif ($t === 0x000A) {
                break; // fim dos globais
            }
            $p += 4 + $n;
        }
        $planilhas = array_values(array_filter($abas, fn($a) => $a['tipo'] === 0));
        if (!isset($planilhas[$abaIndice])) {
            throw new HttpErro(422, 'Aba não encontrada na planilha.');
        }

        $linhas = [];
        $p = $planilhas[$abaIndice]['pos'];
        $formulaPendente = null;
        $poe = function (int $r, int $c, $v) use (&$linhas) {
            if (is_string($v)) {
                $v = trim($v);
                if ($v === '') {
                    return;
                }
            }
            $linhas[$r][$c] = $v;
        };
        while ($p + 4 <= $len) {
            ['t' => $t, 'n' => $n] = unpack('vt/vn', substr($wb, $p, 4));
            $d = substr($wb, $p + 4, $n);
            $p += 4 + $n;
            switch ($t) {
                case 0x000A: // EOF da aba
                    break 2;
                case 0x00FD: // LABELSST
                    $x = unpack('vr/vc/vf/Vi', $d);
                    $poe($x['r'], $x['c'], $sst[$x['i']] ?? null);
                    break;
                case 0x0203: // NUMBER
                    $x = unpack('vr/vc/vf', $d);
                    $poe($x['r'], $x['c'], unpack('e', substr($d, 6, 8))[1]);
                    break;
                case 0x027E: // RK
                    $x = unpack('vr/vc/vf/Vk', $d);
                    $poe($x['r'], $x['c'], self::rk($x['k']));
                    break;
                case 0x00BD: // MULRK
                    $r = unpack('v', substr($d, 0, 2))[1];
                    $c = unpack('v', substr($d, 2, 2))[1];
                    $qtd = intdiv(strlen($d) - 6, 6);
                    for ($i = 0; $i < $qtd; $i++) {
                        $poe($r, $c + $i, self::rk(unpack('V', substr($d, 4 + $i * 6 + 2, 4))[1]));
                    }
                    break;
                case 0x0204: // LABEL
                    $x = unpack('vr/vc/vf/vcch', $d);
                    $poe($x['r'], $x['c'], self::texto(substr($d, 9), $x['cch'], ord($d[8]) & 1));
                    break;
                case 0x0006: // FORMULA
                    $x = unpack('vr/vc/vf', $d);
                    $res = substr($d, 6, 8);
                    if (substr($res, 6, 2) === "\xFF\xFF") {
                        $tipo = ord($res[0]);
                        if ($tipo === 0) {
                            $formulaPendente = [$x['r'], $x['c']]; // texto vem no próximo STRING
                        } elseif ($tipo === 1) {
                            $poe($x['r'], $x['c'], ord($res[2]) ? 'VERDADEIRO' : 'FALSO');
                        }
                    } else {
                        $poe($x['r'], $x['c'], unpack('e', $res)[1]);
                    }
                    break;
                case 0x0207: // STRING (resultado de fórmula)
                    if ($formulaPendente) {
                        $cch = unpack('v', substr($d, 0, 2))[1];
                        $poe($formulaPendente[0], $formulaPendente[1], self::texto(substr($d, 3), $cch, ord($d[2]) & 1));
                        $formulaPendente = null;
                    }
                    break;
                case 0x0205: // BOOLERR
                    $x = unpack('vr/vc/vf/Cv/Ce', $d);
                    if (!$x['e']) {
                        $poe($x['r'], $x['c'], $x['v'] ? 'VERDADEIRO' : 'FALSO');
                    }
                    break;
            }
            if (count($linhas) > Planilha::MAX_LINHAS) {
                throw new HttpErro(422, 'Planilha grande demais (mais de ' . Planilha::MAX_LINHAS . ' linhas). Divida em partes.');
            }
        }

        // transforma em lista densa
        $saida = [];
        $max = $linhas ? max(array_keys($linhas)) : -1;
        for ($r = 0; $r <= $max; $r++) {
            $l = $linhas[$r] ?? [];
            if (!$l) {
                $saida[] = [];
                continue;
            }
            $ult = max(array_keys($l));
            $denso = [];
            for ($c = 0; $c <= $ult; $c++) {
                $denso[] = $l[$c] ?? null;
            }
            $saida[] = $denso;
        }
        while ($saida && !array_filter(end($saida), fn($v) => $v !== null)) {
            array_pop($saida);
        }
        return ['abas' => array_column($planilhas, 'nome'), 'linhas' => $saida];
    }

    /** Lê a tabela de textos compartilhados, tratando strings que atravessam registros CONTINUE */
    private static function lerSst(array $partes): array
    {
        $out = [];
        $pi = 0;
        $b = $partes[0];
        $p = 8; // pula total e únicos
        $total = unpack('V', substr($b, 4, 4))[1];
        $ler = function (int $n) use (&$pi, &$b, &$p, $partes): string {
            $s = '';
            while ($n > 0) {
                if ($p >= strlen($b)) {
                    $pi++;
                    if (!isset($partes[$pi])) {
                        return $s;
                    }
                    $b = $partes[$pi];
                    $p = 0;
                }
                $pega = min($n, strlen($b) - $p);
                $s .= substr($b, $p, $pega);
                $p += $pega;
                $n -= $pega;
            }
            return $s;
        };
        for ($i = 0; $i < $total; $i++) {
            if ($p >= strlen($b)) {
                $pi++;
                if (!isset($partes[$pi])) {
                    break;
                }
                $b = $partes[$pi];
                $p = 0;
            }
            $cch = unpack('v', $ler(2))[1];
            $flags = ord($ler(1));
            $largo = $flags & 0x01;
            $rich = ($flags & 0x08) ? unpack('v', $ler(2))[1] : 0;
            $ext = ($flags & 0x04) ? unpack('V', $ler(4))[1] : 0;
            $texto = '';
            $faltam = $cch;
            while ($faltam > 0) {
                if ($p >= strlen($b)) {
                    // continuação no meio do texto: novo byte de flag no início do CONTINUE
                    $pi++;
                    if (!isset($partes[$pi])) {
                        break;
                    }
                    $b = $partes[$pi];
                    $p = 0;
                    $largo = ord($b[0]) & 0x01;
                    $p = 1;
                }
                $bytesPorChar = $largo ? 2 : 1;
                $cabe = intdiv(strlen($b) - $p, $bytesPorChar);
                $n = min($faltam, $cabe);
                $pedaco = substr($b, $p, $n * $bytesPorChar);
                $texto .= $largo ? mb_convert_encoding($pedaco, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($pedaco, 'UTF-8', 'ISO-8859-1');
                $p += $n * $bytesPorChar;
                $faltam -= $n;
            }
            if ($rich) {
                $ler($rich * 4);
            }
            if ($ext) {
                $ler($ext);
            }
            $out[] = $texto;
        }
        return $out;
    }

    private static function texto(string $bytes, int $cch, int $largo): string
    {
        return $largo
            ? mb_convert_encoding(substr($bytes, 0, $cch * 2), 'UTF-8', 'UTF-16LE')
            : mb_convert_encoding(substr($bytes, 0, $cch), 'UTF-8', 'ISO-8859-1');
    }

    private static function rk(int $rk): float
    {
        $div100 = $rk & 1;
        if ($rk & 2) {
            $v = (float) ($rk >> 2);
            if ($rk & 0x80000000) {
                $v = (float) (($rk >> 2) - (1 << 30)); // inteiro negativo de 30 bits
            }
        } else {
            $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
        }
        return $div100 ? $v / 100 : $v;
    }
}
