<?php
declare(strict_types=1);

namespace Up\Http;

final class Request
{
    public array $params = [];
    /** usuário autenticado (linha de usuarios), preenchido pelo Auth */
    public ?array $usuario = null;

    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $query,
        public readonly array $corpo,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $ip,
        public readonly array $arquivos = [],
    ) {
    }

    public static function daGlobal(): self
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $caminho = '/' . trim($uri, '/');

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $corpo = [];
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $bruto = file_get_contents('php://input') ?: '';
            $corpo = $bruto !== '' ? (json_decode($bruto, true) ?? []) : [];
            if (!is_array($corpo)) {
                $corpo = [];
            }
        } else {
            $corpo = $_POST;
        }

        return new self($metodo, $caminho, $_GET, $corpo, $headers, $_COOKIE, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $_FILES);
    }

    public function get(string $chave, mixed $padrao = null): mixed
    {
        return $this->corpo[$chave] ?? $this->query[$chave] ?? $padrao;
    }

    public function header(string $nome): ?string
    {
        return $this->headers[strtolower($nome)] ?? null;
    }

    public function param(string $nome): string
    {
        return $this->params[$nome] ?? '';
    }

    public function idParam(string $nome = 'id'): int
    {
        $v = $this->params[$nome] ?? '';
        if (!ctype_digit($v)) {
            throw new HttpErro(404, 'Não encontrado.');
        }
        return (int) $v;
    }
}
