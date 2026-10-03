<?php
/**
 * 1.2.9 - Müşteri identity_no benzersizlik kısıtı (soft-delete uyumlu)
 *
 * MySQL'de WHERE koşullu partial UNIQUE index yok.
 * Çözüm: Virtual generated column — silinmiş kayıtlarda NULL döner,
 * aktif kayıtlarda identity_no döner. MySQL UNIQUE index'te birden fazla
 * NULL'a izin verdiği için soft-delete çakışması olmaz.
 *
 * Koşul: identity_no boş veya NULL ise yine NULL döner
 * (boş identity_no'lu birden fazla aktif kayda izin verilir).
 */

$pdo = Database::getInstance();

// 1. Mevcut aktif mükerrer kayıtları raporla (migration'dan önce bilinmeli)
$duplicates = $pdo->query("
    SELECT identity_no, COUNT(*) as cnt
    FROM customers
    WHERE deleted_at IS NULL
      AND identity_no IS NOT NULL
      AND identity_no != ''
    GROUP BY identity_no
    HAVING cnt > 1
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    $colExists = $pdo->query("
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'customers'
      AND column_name = 'identity_no_active'
	")->fetchColumn();
	
	
if (!$colExists) {
    // Aktif kayıtlarda identity_no'yu, silinmiş veya boş olanlarda NULL döner
    $pdo->exec("
        ALTER TABLE customers
        ADD COLUMN `identity_no_active` VARCHAR(50)
            GENERATED ALWAYS AS (
                IF(deleted_at IS NULL AND identity_no IS NOT NULL AND identity_no != '',
                   identity_no,
                   NULL)
            ) VIRTUAL
    ");
}

// 3. UNIQUE index zaten varsa atla
$idxExists = $pdo->query("
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'customers'
      AND index_name = 'uk_cust_identity_active'
")->fetchColumn();

if (!$idxExists) {
    // Mevcut mükerrerler varsa index eklenemez; hata oluşursa logla
    try {
        $pdo->exec("
            ALTER TABLE customers
            ADD UNIQUE KEY `uk_cust_identity_active` (`identity_no_active`)
        ");
    } catch (\Exception $e) {
 
    }
}
}
 


