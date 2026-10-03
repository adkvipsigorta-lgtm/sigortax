<?php

require_once __DIR__ . '/../config.php';

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$instance;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int
    {
        $fields = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `$table` ($fields) VALUES ($placeholders)";
        self::query($sql, array_values($data));
        return (int) self::getInstance()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        $sql = "UPDATE `$table` SET $set WHERE $where";
        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }

    public static function commit(): void
    {
        self::getInstance()->commit();
    }

    public static function rollBack(): void
    {
        self::getInstance()->rollBack();
    }

    public static function softDelete(string $table, int $id): int
    {
        return self::update($table, ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    public static function paginate(string $sql, array $params, int $page, int $limit): array
    {
        // Sadece en distaki ORDER BY'i kaldir (subquery'lerdekine dokunma)
        $countInner = preg_replace('/ORDER BY\s+[\w`.]+\s+(?:ASC|DESC)\s*$/i', '', $sql);
        $countSql = "SELECT COUNT(*) as total FROM (" . $countInner . ") as _count_query";
        $countResult = self::fetch($countSql, $params);
        $total = (int) ($countResult['total'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql .= " LIMIT $limit OFFSET $offset";
        $data = self::fetchAll($sql, $params);

        return [
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ];
    }
}
