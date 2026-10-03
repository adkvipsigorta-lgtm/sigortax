<?php
/**
 * 1.2.8 - policies tablosuna (policy_no, endorsement_no) composite index
 *
 * Müşteri listesindeki active_policies ve total_gross alt sorguları her
 * policy_no grubu içinde ORDER BY endorsement_no DESC LIMIT 1 yapıyor.
 * Composite index olmadan MySQL bu sıralamayı bellekte (Filesort) yapıyor.
 * Bu index ile aynı işlem index üzerinden yapılır, Filesort kalkar.
 */

$pdo = Database::getInstance();

// Index zaten varsa hata vermesin
$exists = $pdo->query("
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'policies'
      AND index_name = 'idx_pol_no_endorsement'
")->fetchColumn();

if (!$exists) {
    $pdo->exec("ALTER TABLE policies ADD KEY `idx_pol_no_endorsement` (`policy_no`, `endorsement_no`)");
}
