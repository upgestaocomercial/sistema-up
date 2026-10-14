<?php
declare(strict_types=1);

namespace Up;

/** Regras de limpeza dos dados que vêm do Mercos e de planilhas. */
final class Limpeza
{
    public static function soDigitos(?string $s): string
    {
        return preg_replace('/\D+/', '', (string) $s);
    }

    /** Formata e valida CNPJ/CPF. Devolve [formatado, tipo PJ|PF, valido] ou null se vazio */
    public static function documento(mixed $v): ?array
    {
        $d = self::soDigitos(is_float($v) ? sprintf('%.0f', $v) : (string) $v);
        if ($d === '') {
            return null;
        }
        if (strlen($d) <= 11 && strlen($d) >= 9) {
            $d = str_pad($d, 11, '0', STR_PAD_LEFT);
            return [substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2), 'PF', self::cpfValido($d)];
        }
        if (strlen($d) <= 14 && strlen($d) >= 12) {
            $d = str_pad($d, 14, '0', STR_PAD_LEFT);
            return [substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2), 'PJ', self::cnpjValido($d)];
        }
        return [$d, 'PJ', false];
    }

    public static function cnpjValido(string $c): bool
    {
        if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) {
            return false;
        }
        foreach ([12, 13] as $n) {
            $soma = 0;
            $peso = $n - 7;
            for ($i = 0; $i < $n; $i++) {
                $soma += (int) $c[$i] * $peso;
                $peso = $peso === 2 ? 9 : $peso - 1;
            }
            $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
            if ((int) $c[$n] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function cpfValido(string $c): bool
    {
        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }
        foreach ([9, 10] as $n) {
            $soma = 0;
            for ($i = 0; $i < $n; $i++) {
                $soma += (int) $c[$i] * ($n + 1 - $i);
            }
            $dv = ($soma * 10) % 11 % 10;
            if ((int) $c[$n] !== $dv) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tira a marcação de contribuinte escrita no nome: "(CONTRIBUINTE)", "(contri)", "(contr)", "(Não Contri)",
     * "- NÃO CONTRIBUINTE". Devolve [nome limpo, true|false|null].
     */
    public static function marcaContribuinte(?string $nome): array
    {
        if ($nome === null) {
            return [null, null];
        }
        $flag = null;
        $re = '/\s*[\(\[]\s*(n[ãa]o\s*[-\s]?\s*)?contr(?:i(?:b(?:uinte)?)?)?\.?\s*[\)\]]?/iu';
        if (preg_match($re, $nome, $m)) {
            $flag = empty($m[1]);
            $nome = preg_replace($re, ' ', $nome, 1);
        } elseif (preg_match('/\s*[-–]\s*(n[ãa]o\s+)?contribuinte\s*$/iu', $nome, $m)) {
            $flag = empty($m[1]);
            $nome = preg_replace('/\s*[-–]\s*(n[ãa]o\s+)?contribuinte\s*$/iu', '', $nome);
        }
        $nome = trim(preg_replace('/\s{2,}/', ' ', $nome), " -–");
        return [$nome !== '' ? $nome : null, $flag];
    }

    /** E-mails válidos de um campo com vários separados por vírgula/ponto e vírgula/espaço */
    public static function emails(?string $s): array
    {
        $out = [];
        foreach (preg_split('/[,;\s\/]+/', (string) $s) as $e) {
            $e = mb_strtolower(trim($e, " .<>"));
            if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            [$local, $dom] = explode('@', $e, 2);
            $falso = preg_match('/^(nao|n|sem|nt)[._-]?(tem|possui|informado)?$|naotem|sememail|nao\.?tem|naopossui|^teste?$|^x+$/', $local)
                || in_array($dom, ['com.br', 'com', 'email.com', 'teste.com', 'naotem.com', 'naotem.com.br'], true);
            if (!$falso && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * Telefones de um campo como "11 96440 0247,DANILO 11 3055-7600".
     * Devolve lista de ['telefone' => '(11) 96440-0247', 'nome' => 'Danilo'|null, 'celular' => bool]
     */
    public static function telefones(?string $s): array
    {
        $out = [];
        $vistos = [];
        foreach (preg_split('/[,;\/]|\s{3,}|\be\b/u', (string) $s) as $parte) {
            $parte = trim($parte);
            if ($parte === '' || preg_match('/n[ãa]o\s*tem|sem\s*tel/iu', $parte)) {
                continue;
            }
            $nome = null;
            if (preg_match('/^([\p{L}][\p{L}\s.]{1,40}?)[\s:\-]*(?=[\d(+])/u', $parte, $m)) {
                $nome = mb_convert_case(trim($m[1], " .:-"), MB_CASE_TITLE);
                $parte = substr($parte, strlen($m[0]));
            }
            $d = self::soDigitos($parte);
            if (str_starts_with($d, '55') && strlen($d) >= 12) {
                $d = substr($d, 2);
            }
            if (str_starts_with($d, '0') && strlen($d) >= 11) {
                $d = substr($d, 1);
            }
            if (strlen($d) < 8 || strlen($d) > 11 || preg_match('/^(\d)\1+$/', $d)) {
                continue;
            }
            if (strlen($d) === 8 || strlen($d) === 9) {
                $d = '11' . $d; // sem DDD: a maioria da carteira é da Grande SP
            }
            $fmt = strlen($d) === 11
                ? '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7)
                : '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
            if (isset($vistos[$d])) {
                continue;
            }
            $vistos[$d] = true;
            $out[] = ['telefone' => $fmt, 'nome' => $nome, 'celular' => strlen($d) === 11 && $d[2] === '9'];
        }
        return $out;
    }

    public static function cep(?string $s): ?string
    {
        $d = self::soDigitos($s);
        if ($d === '') {
            return null;
        }
        $d = str_pad($d, 8, '0', STR_PAD_LEFT);
        return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : null;
    }

    /** "AVENIDA DOS ESTADOS, 7328, GALPAO 10" -> [logradouro, numero, complemento] */
    public static function endereco(?string $s): array
    {
        $s = trim((string) $s);
        if ($s === '') {
            return [null, null, null];
        }
        $partes = array_map('trim', explode(',', $s));
        if (count($partes) >= 2 && preg_match('/^(\d+[A-Za-z]?|s\/?n|sn|km\s*[\d.,]+)$/i', $partes[1])) {
            return [$partes[0], mb_substr($partes[1], 0, 20), count($partes) > 2 ? mb_substr(implode(', ', array_slice($partes, 2)), 0, 80) : null];
        }
        if (preg_match('/^(.*?)[\s,]+(?:n[º°o.]?\s*)?(\d+[A-Za-z]?)\s*(?:[-,]\s*(.*))?$/u', $s, $m) && strlen($m[1]) > 3) {
            return [$m[1], $m[2], isset($m[3]) && $m[3] !== '' ? mb_substr($m[3], 0, 80) : null];
        }
        return [mb_substr($s, 0, 190), null, null];
    }

    public static function inscricaoEstadual(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^isent[oa]$/iu', $s)) {
            return 'ISENTO';
        }
        $d = self::soDigitos($s);
        return strlen($d) >= 8 && strlen($d) <= 14 && !str_contains($s, '@') ? $d : null;
    }

    /** "14/10/2026" ou número de data do Excel -> "2026-10-14" */
    public static function data(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_float($v) || is_int($v)) {
            if ($v < 20000 || $v > 80000) {
                return null;
            }
            return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $v . ' days')->format('Y-m-d');
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', trim($v), $m)) {
            $a = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            return checkdate((int) $m[2], (int) $m[1], (int) $a) ? sprintf('%04d-%02d-%02d', $a, $m[2], $m[1]) : null;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', trim($v), $m)) {
            return "$m[1]-$m[2]-$m[3]";
        }
        return null;
    }

    /** Nome de cidade padronizado: "SAO PAULO" e "São Paulo" viram "São Paulo" */
    public static function cidade(?string $s, array $preferidos = []): ?string
    {
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        if ($s === '') {
            return null;
        }
        $chave = Planilha::normalizar($s);
        if (isset($preferidos[$chave])) {
            return $preferidos[$chave];
        }
        $palavras = explode(' ', mb_strtolower($s));
        $minusculas = ['de', 'da', 'do', 'das', 'dos', 'e'];
        foreach ($palavras as $i => $p) {
            $semAcento = Planilha::normalizar($p);
            if (isset(self::ACENTOS[$semAcento]) && $semAcento === $p) {
                $p = self::ACENTOS[$semAcento];
            }
            $palavras[$i] = ($i > 0 && in_array($p, $minusculas, true)) ? $p : mb_convert_case($p, MB_CASE_TITLE);
        }
        return implode(' ', $palavras);
    }

    /** Acentos de palavras comuns em nomes de cidades de SP (quando a cidade vem toda sem acento) */
    private const ACENTOS = [
        'sao' => 'são', 'jose' => 'josé', 'joao' => 'joão', 'andre' => 'andré', 'taboao' => 'taboão', 'aruja' => 'arujá',
        'jundiai' => 'jundiaí', 'carapicuiba' => 'carapicuíba', 'parnaiba' => 'parnaíba', 'maua' => 'mauá', 'poa' => 'poá',
        'ribeirao' => 'ribeirão', 'guaruja' => 'guarujá', 'cubatao' => 'cubatão', 'peruibe' => 'peruíbe', 'itanhaem' => 'itanhaém',
        'mongagua' => 'mongaguá', 'taubate' => 'taubaté', 'jacarei' => 'jacareí', 'guaratingueta' => 'guaratinguetá',
        'cacapava' => 'caçapava', 'braganca' => 'bragança', 'varzea' => 'várzea', 'salesopolis' => 'salesópolis', 'juquia' => 'juquiá',
        'guacu' => 'guaçu', 'ibiuna' => 'ibiúna', 'apiai' => 'apiaí', 'tremembe' => 'tremembé', 'sebastiao' => 'sebastião',
        'conceicao' => 'conceição', 'mairipora' => 'mairiporã', 'santana' => 'santana', 'embu' => 'embu', 'mirim' => 'mirim',
        'goncalo' => 'gonçalo', 'vicente' => 'vicente', 'piraju' => 'piraju', 'itapetininga' => 'itapetininga', 'sorocaba' => 'sorocaba',
        'canaa' => 'canaã', 'paulinia' => 'paulínia', 'hortolandia' => 'hortolândia', 'sumare' => 'sumaré', 'aracariguama' => 'araçariguama',
        'cotia' => 'cotia', 'itaquaquecetuba' => 'itaquaquecetuba', 'biritiba' => 'biritiba', 'santo' => 'santo', 'bernardo' => 'bernardo',
        'caetano' => 'caetano', 'paraiba' => 'paraíba', 'jacarepagua' => 'jacarepaguá', 'florianopolis' => 'florianópolis',
        'brasilia' => 'brasília', 'goiania' => 'goiânia', 'belem' => 'belém', 'maceio' => 'maceió', 'vitoria' => 'vitória',
        'joinville' => 'joinville', 'itajai' => 'itajaí', 'balneario' => 'balneário', 'camboriu' => 'camboriú',
    ];
}
