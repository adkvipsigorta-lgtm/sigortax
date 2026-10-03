<?php
/**
 * Migration: komisyon oranlari kusuratli (decimal) + komisyon tutari saklama.
 *   - company_comm_rate / branch_comm_rate: tinyint -> decimal(6,2)
 *   - company_comm_amount / branch_comm_amount: yeni decimal(12,2) kolonlar
 */

$pdo = Database::getInstance();

// 1) Oran kolonlarini decimal(6,2)'ye cevir (tinyint kusurat saklayamiyor)
foreach (['company_comm_rate', 'branch_comm_rate'] as $col) {
    $type = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='policies' AND COLUMN_NAME='$col'"
    )->fetchColumn();
    if ($type && stripos($type, 'decimal') === false) {
        $pdo->exec("ALTER TABLE `policies` MODIFY `$col` DECIMAL(6,2) NOT NULL DEFAULT 0");
    }
}

// 2) Komisyon tutari kolonlarini ekle
SchemaHelper::ensureColumn('policies', 'company_comm_amount', 'DECIMAL(12,2) NULL', 'company_comm_rate');
SchemaHelper::ensureColumn('policies', 'branch_comm_amount', 'DECIMAL(12,2) NULL', 'branch_comm_rate');
