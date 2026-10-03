<?php
/**
 * Migration: Takip Araması (Follow-up Call) özelliği.
 * - tasks.type enum'una FOLLOW_UP_CALL eklenir
 * - tasks.result enum'una CALLED, NOT_CALLED eklenir
 * - customer_notes tablosu oluşturulur (yapılandırılmış müşteri notları)
 */

$pdo = Database::getInstance();

// 1. tasks.type enum'una FOLLOW_UP_CALL ekle
try {
    $col = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], 'FOLLOW_UP_CALL') === false) {
        $pdo->exec("ALTER TABLE tasks MODIFY COLUMN `type` VARCHAR(30) NOT NULL DEFAULT 'OTHER'");
    }
} catch (Exception $e) {
    // ignore
}

// 2. tasks.result enum'una CALLED, NOT_CALLED ekle
try {
    $col = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'result'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], 'CALLED') === false) {
        $pdo->exec("ALTER TABLE tasks MODIFY COLUMN `result` VARCHAR(30) DEFAULT NULL");
    }
} catch (Exception $e) {
    // ignore
}

// 3. customer_notes tablosu
SchemaHelper::ensureTable('customer_notes', [
    'id'          => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'customer_id' => 'INT UNSIGNED NOT NULL',
    'type'        => "VARCHAR(20) NOT NULL DEFAULT 'NOTE'",
    'note'        => 'TEXT NOT NULL',
    'task_id'     => 'INT UNSIGNED DEFAULT NULL',
    'policy_id'   => 'INT UNSIGNED DEFAULT NULL',
    'created_by'  => 'INT UNSIGNED NOT NULL',
    'created_at'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('customer_notes', 'idx_customer_notes_customer', ['customer_id']);
