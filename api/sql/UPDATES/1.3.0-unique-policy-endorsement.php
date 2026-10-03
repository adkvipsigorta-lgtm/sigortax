<?php
/**
 * 1.3.0 - Poliçe + Zeyil benzersizlik kısıtı (soft-delete uyumlu)
 *
 * (policy_no, endorsement_no) çifti aktif kayıtlarda tekrarlanamaz.
 * Silinmiş kayıtlar (deleted_at IS NOT NULL) NULL ürettiği için
 * UNIQUE index'e takılmaz — soft-delete uyumlu.
 */

$pdo = Database::getInstance();

// 1. Mevcut aktif mükerrer kayıtları logla
$duplicates = $pdo->query("
    SELECT policy_no, endorsement_no, COUNT(*) as cnt
    FROM policies
    WHERE deleted_at IS NULL
    GROUP BY policy_no, endorsement_no
    HAVING cnt > 1
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    
// 2. Virtual column zaten varsa atla
$colExists = $pdo->query("
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'policies'
      AND column_name = 'policy_active_hash'
")->fetchColumn();

if (!$colExists) {
    $pdo->exec("
        ALTER TABLE policies
        ADD COLUMN `policy_active_hash` VARCHAR(255)
            GENERATED ALWAYS AS (
                IF(deleted_at IS NULL,
                   CONCAT(policy_no, '_', endorsement_no),
                   NULL)
            ) VIRTUAL
    ");
}

// 3. UNIQUE index zaten varsa atla
$idxExists = $pdo->query("
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'policies'
      AND index_name = 'unique_policy_endorsement_active'
")->fetchColumn();

if (!$idxExists) {
    try {
        $pdo->exec("
            ALTER TABLE policies
            ADD UNIQUE KEY `unique_policy_endorsement_active` (`policy_active_hash`)
        ");
    } catch (\Exception $e) {
        
    }
}
}

