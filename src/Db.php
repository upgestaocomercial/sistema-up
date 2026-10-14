<?php
declare(strict_types=1);

namespace Up;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = App::config('db');
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset'] ?? 'utf8mb4');
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function todos(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function um(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $linha = $st->fetch();
        return $linha === false ? null : $linha;
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function inserir(string $tabela, array $dados): int
    {
        $cols = array_keys($dados);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $tabela,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_map(fn($c) => ":$c", $cols))
        );
        self::exec($sql, $dados);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(string $tabela, int $id, array $dados): int
    {
        if (!$dados) {
            return 0;
        }
        $sets = implode(',', array_map(fn($c) => "`$c` = :$c", array_keys($dados)));
        $dados['__id'] = $id;
        return self::exec("UPDATE `$tabela` SET $sets WHERE id = :__id", $dados);
    }

    public static function transacao(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Para testes */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
