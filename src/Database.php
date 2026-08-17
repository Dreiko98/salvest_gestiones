<?php
declare(strict_types=1);

namespace Salvest;

use PDO;

final class Database
{
    private PDO $pdo;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'], $config['port'] ?? 3306, $config['name'], $config['charset'] ?? 'utf8mb4');
        $this->pdo = new PDO($dsn, (string)$config['user'], (string)$config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function pdo(): PDO { return $this->pdo; }

    /** @param array<int|string,mixed> $params */
    public function one(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($params);
        $row = $statement->fetch(); return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($params);
        return $statement->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($params);
        return $statement->rowCount();
    }
}
