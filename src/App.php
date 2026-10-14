<?php
declare(strict_types=1);

namespace Up;

final class App
{
    private static ?array $config = null;

    public static function config(?string $chave = null, mixed $padrao = null): mixed
    {
        if (self::$config === null) {
            $arquivo = getenv('UP_CONFIG') ?: __DIR__ . '/../config/config.php';
            if (!is_file($arquivo)) {
                throw new \RuntimeException('config/config.php não encontrado. Copie config.example.php.');
            }
            self::$config = require $arquivo;
            date_default_timezone_set('UTC'); // banco em UTC; a aplicação converte para exibição
        }
        if ($chave === null) {
            return self::$config;
        }
        $valor = self::$config;
        foreach (explode('.', $chave) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return $padrao;
            }
            $valor = $valor[$parte];
        }
        return $valor;
    }

    /** Para testes */
    public static function definirConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function fuso(): \DateTimeZone
    {
        return new \DateTimeZone((string) self::config('app.timezone', 'America/Sao_Paulo'));
    }

    /** "Hoje" no fuso da empresa */
    public static function hoje(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', self::fuso());
    }
}
