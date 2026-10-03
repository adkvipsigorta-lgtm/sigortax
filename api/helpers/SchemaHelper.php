<?php

/**
 * Idempotent schema yardimcisi. Migration dosyalarinda kullanin.
 *
 * Her metot once kontrol eder, gerekiyorsa uygular. Yoksa sessizce gecer.
 * Birden fazla kez calistirilsa bile hata vermez.
 *
 * Kullanim ornegi (.php migration dosyasi icinde):
 *
 *   use SchemaHelper as S;
 *
 *   S::ensureTable('foo', [
 *       'id'         => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
 *       'name'       => 'VARCHAR(120) NOT NULL',
 *       'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
 *   ]);
 *
 *   S::ensureColumn('policies', 'no_renewal_reminder',
 *       'TINYINT(1) NOT NULL DEFAULT 0', 'is_cancelled');
 *
 *   S::ensureIndex('policies', 'idx_policy_no_renewal',
 *       ['no_renewal_reminder']);
 *
 *   S::dropColumn('policies', 'legacy_field');
 */
class SchemaHelper
{
    private static function db(): PDO
    {
        require_once __DIR__ . '/Database.php';
        return Database::getInstance();
    }

    public static function hasTable(string $table): bool
    {
        $row = self::db()->query(
            "SELECT COUNT(*) c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = " . self::db()->quote($table)
        )->fetch(PDO::FETCH_ASSOC);
        return ((int) ($row['c'] ?? 0)) > 0;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $row = self::db()->query(
            "SELECT COUNT(*) c FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = " . self::db()->quote($table) . "
               AND column_name = " . self::db()->quote($column)
        )->fetch(PDO::FETCH_ASSOC);
        return ((int) ($row['c'] ?? 0)) > 0;
    }

    public static function hasIndex(string $table, string $indexName): bool
    {
        $row = self::db()->query(
            "SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = " . self::db()->quote($table) . "
               AND index_name = " . self::db()->quote($indexName)
        )->fetch(PDO::FETCH_ASSOC);
        return ((int) ($row['c'] ?? 0)) > 0;
    }

    /**
     * Tablo yoksa olustur. Sutun listesi: ['col' => 'TIP VE OZELLIKLER', ...]
     * Tablo varsa sutun listesini bir bir kontrol edip eksik olanlari ekler.
     */
    public static function ensureTable(string $table, array $columns, string $engineCharset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'): void
    {
        $tbl = '`' . str_replace('`', '', $table) . '`';

        if (!self::hasTable($table)) {
            $defs = [];
            foreach ($columns as $name => $type) {
                $defs[] = '`' . str_replace('`', '', $name) . '` ' . $type;
            }
            $sql = "CREATE TABLE $tbl (" . implode(", ", $defs) . ") $engineCharset";
            self::db()->exec($sql);
            return;
        }

        // Tablo varsa eksik kolonlari tek tek ekle (sirayi koru: oncekinin AFTER'i)
        $previous = null;
        foreach ($columns as $name => $type) {
            self::ensureColumn($table, $name, $type, $previous);
            $previous = $name;
        }
    }

    /**
     * Sutun yoksa ekle. Varsa dokunma.
     * $after: hangi kolonun ardina eklensin (null ise sona).
     */
    public static function ensureColumn(string $table, string $column, string $type, ?string $after = null): void
    {
        if (self::hasColumn($table, $column)) return;
        $tbl = '`' . str_replace('`', '', $table) . '`';
        $col = '`' . str_replace('`', '', $column) . '`';
        $sql = "ALTER TABLE $tbl ADD COLUMN $col $type";
        if ($after !== null) {
            $sql .= ' AFTER `' . str_replace('`', '', $after) . '`';
        }
        self::db()->exec($sql);
    }

    /**
     * Index yoksa ekle. $columns: kolon adlari listesi.
     * Unique icin son arguman true.
     */
    public static function ensureIndex(string $table, string $indexName, array $columns, bool $unique = false): void
    {
        if (self::hasIndex($table, $indexName)) return;
        $tbl = '`' . str_replace('`', '', $table) . '`';
        $idx = '`' . str_replace('`', '', $indexName) . '`';
        $cols = implode(', ', array_map(fn($c) => '`' . str_replace('`', '', $c) . '`', $columns));
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        self::db()->exec("ALTER TABLE $tbl ADD $kind $idx ($cols)");
    }

    /**
     * Sutun varsa kaldir. Yoksa dokunma.
     */
    public static function dropColumn(string $table, string $column): void
    {
        if (!self::hasColumn($table, $column)) return;
        $tbl = '`' . str_replace('`', '', $table) . '`';
        $col = '`' . str_replace('`', '', $column) . '`';
        self::db()->exec("ALTER TABLE $tbl DROP COLUMN $col");
    }

    /**
     * Index varsa kaldir.
     */
    public static function dropIndex(string $table, string $indexName): void
    {
        if (!self::hasIndex($table, $indexName)) return;
        $tbl = '`' . str_replace('`', '', $table) . '`';
        $idx = '`' . str_replace('`', '', $indexName) . '`';
        self::db()->exec("ALTER TABLE $tbl DROP INDEX $idx");
    }

    /**
     * Raw SQL calistir (idempotency disinda komutlar icin).
     */
    public static function exec(string $sql): void
    {
        self::db()->exec($sql);
    }
}
