<?php
declare(strict_types=1);

namespace Up\Http;

final class HttpErro extends \RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem, public readonly array $detalhes = [])
    {
        parent::__construct($mensagem, $status);
    }
}
