<?php
declare(strict_types=1);

namespace Up;

use Up\Http\HttpErro;

/** Validação simples de entrada. Junta todos os erros e devolve 422 com a lista. */
final class Validar
{
    private array $erros = [];
    private array $limpo = [];

    public function __construct(private array $dados)
    {
    }

    public static function de(array $dados): self
    {
        return new self($dados);
    }

    public function texto(string $campo, bool $obrigatorio = false, int $max = 255, ?string $rotulo = null): self
    {
        $v = isset($this->dados[$campo]) ? trim((string) $this->dados[$campo]) : null;
        if ($v === null || $v === '') {
            if ($obrigatorio) {
                $this->erros[$campo] = ($rotulo ?? $campo) . ' é obrigatório.';
            } elseif (array_key_exists($campo, $this->dados)) {
                $this->limpo[$campo] = null;
            }
            return $this;
        }
        if (mb_strlen($v) > $max) {
            $this->erros[$campo] = ($rotulo ?? $campo) . " pode ter no máximo $max caracteres.";
            return $this;
        }
        $this->limpo[$campo] = $v;
        return $this;
    }

    public function email(string $campo, bool $obrigatorio = false): self
    {
        $this->texto($campo, $obrigatorio, 190, 'E-mail');
        if (isset($this->limpo[$campo]) && $this->limpo[$campo] !== null) {
            $v = mb_strtolower($this->limpo[$campo]);
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $this->erros[$campo] = 'E-mail inválido.';
            }
            $this->limpo[$campo] = $v;
        }
        return $this;
    }

    public function numero(string $campo, bool $obrigatorio = false, ?float $min = null, ?float $max = null, ?string $rotulo = null): self
    {
        if (!array_key_exists($campo, $this->dados) || $this->dados[$campo] === '' || $this->dados[$campo] === null) {
            if ($obrigatorio) {
                $this->erros[$campo] = ($rotulo ?? $campo) . ' é obrigatório.';
            } elseif (array_key_exists($campo, $this->dados)) {
                $this->limpo[$campo] = null;
            }
            return $this;
        }
        $v = Numero::paraFloat($this->dados[$campo]);
        if ($v === null) {
            $this->erros[$campo] = ($rotulo ?? $campo) . ' deve ser um número.';
            return $this;
        }
        if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
            $this->erros[$campo] = ($rotulo ?? $campo) . ' fora do intervalo permitido.';
            return $this;
        }
        $this->limpo[$campo] = $v;
        return $this;
    }

    public function inteiro(string $campo, bool $obrigatorio = false): self
    {
        $this->numero($campo, $obrigatorio);
        if (isset($this->limpo[$campo])) {
            $this->limpo[$campo] = (int) $this->limpo[$campo];
        }
        return $this;
    }

    public function bool(string $campo): self
    {
        if (array_key_exists($campo, $this->dados)) {
            $v = $this->dados[$campo];
            $this->limpo[$campo] = ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'sim') ? 1 : 0;
        }
        return $this;
    }

    public function opcao(string $campo, array $opcoes, bool $obrigatorio = false): self
    {
        $v = $this->dados[$campo] ?? null;
        if ($v === null || $v === '') {
            if ($obrigatorio) {
                $this->erros[$campo] = "$campo é obrigatório.";
            } elseif (array_key_exists($campo, $this->dados)) {
                $this->limpo[$campo] = null;
            }
            return $this;
        }
        if (!in_array($v, $opcoes, true)) {
            $this->erros[$campo] = "Valor inválido para $campo.";
            return $this;
        }
        $this->limpo[$campo] = $v;
        return $this;
    }

    public function data(string $campo, bool $obrigatorio = false): self
    {
        $v = $this->dados[$campo] ?? null;
        if ($v === null || $v === '') {
            if ($obrigatorio) {
                $this->erros[$campo] = "$campo é obrigatório.";
            } elseif (array_key_exists($campo, $this->dados)) {
                $this->limpo[$campo] = null;
            }
            return $this;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $this->erros[$campo] = 'Data inválida.';
            return $this;
        }
        $this->limpo[$campo] = $v;
        return $this;
    }

    public function ok(): array
    {
        if ($this->erros) {
            throw new HttpErro(422, 'Confira os campos destacados.', $this->erros);
        }
        return $this->limpo;
    }
}
