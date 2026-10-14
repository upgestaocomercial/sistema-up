<?php
declare(strict_types=1);

namespace Up;

final class Auditoria
{
    public static function registrar(?int $usuarioId, string $entidade, int $id, string $acao, ?array $antes = null, ?array $depois = null): void
    {
        Db::inserir('auditoria', [
            'usuario_id' => $usuarioId,
            'entidade' => $entidade,
            'entidade_id' => $id,
            'acao' => $acao,
            'antes' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            'depois' => $depois !== null ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
