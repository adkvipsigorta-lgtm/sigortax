<?php
/**
 * Migration: 1.2.3-fix-commission-rate-decimal
 *
 * Sorun: Bazi policelerde company_comm_rate ondalik olarak girilmis.
 * Ornek: %15 yerine 0.15 kayitli. Bu durum komisyon hesaplamalarinda
 * yanlis sonuc veriyor (gercek degerin 100 kati kucuk).
 *
 * Etkilenen kayitlar: company_comm_rate > 0 AND company_comm_rate < 1
 * Duzeltme: degeri 100 ile carp (0.15 -> 15.00)
 */

$pdo = Database::getInstance();

// 1. Etkilenen kayit sayisi
$check = $pdo->query("
    SELECT COUNT(*) as cnt, MIN(company_comm_rate) as min_rate, MAX(company_comm_rate) as max_rate
    FROM policies
    WHERE deleted_at IS NULL
      AND company_comm_rate > 0
      AND company_comm_rate < 1
")->fetch(PDO::FETCH_ASSOC);

 
if ((int) $check['cnt'] > 0) {
    
// 2. Duzelt: rate * 100
$stmt = $pdo->prepare("
    UPDATE policies
    SET
        company_comm_rate = ROUND(company_comm_rate * 100, 4),
        updated_at        = NOW()
    WHERE deleted_at IS NULL
      AND company_comm_rate > 0
      AND company_comm_rate < 1
");
$stmt->execute();

$affected = $stmt->rowCount();
}

 