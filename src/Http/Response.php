<?php
declare(strict_types=1);

namespace Up\Http;

final class Response
{
    private array $cookies = [];

    public function __construct(
        public mixed $corpo = null,
        public int $status = 200,
        public array $headers = ['Content-Type' => 'application/json; charset=utf-8'],
    ) {
    }

    public static function json(mixed $dados, int $status = 200): self
    {
        return new self($dados, $status);
    }

    public static function arquivo(string $conteudo, string $tipo, string $nome): self
    {
        return new self($conteudo, 200, [
            'Content-Type' => $tipo,
            'Content-Disposition' => 'attachment; filename="' . addslashes($nome) . '"',
        ]);
    }

    public function cookie(string $nome, string $valor, int $expira, bool $seguro): self
    {
        $this->cookies[] = [$nome, $valor, [
            'expires' => $expira,
            'path' => '/',
            'secure' => $seguro,
            'httponly' => true,
            'samesite' => 'Strict',
        ]];
        return $this;
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    public function enviar(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        foreach ($this->cookies as [$n, $v, $o]) {
            setcookie($n, $v, $o);
        }
        if (is_string($this->corpo) && !str_starts_with($this->headers['Content-Type'] ?? '', 'application/json')) {
            echo $this->corpo;
            return;
        }
        echo json_encode($this->corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
