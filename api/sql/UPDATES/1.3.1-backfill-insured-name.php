<?php
/**
 * 1.3.1 - Sigorta Ettiren boş poliçeleri müşteri adıyla doldur
 *
 * Allianz XML importunda SIGORTALI = SIGORTA_ETTIREN olduğunda insured_name
 * kaydedilmiyordu (bug düzeltmesi: 2026-07-02). Mevcut NULL kayıtları
 * bağlı müşterinin adıyla doldurur.
 *
 * Güvenli: insured_name zaten dolu olan kayıtlara dokunmaz.
 */

$pdo = Database::getInstance();

$stmt = $pdo->prepare("
    UPDATE policies p
    INNER JOIN customers cu ON p.customer_id = cu.id
    SET p.insured_name = cu.name,
        p.updated_at  = NOW()
    WHERE p.insured_name IS NULL
      AND p.deleted_at  IS NULL
      AND p.customer_id IS NOT NULL
");
$stmt->execute();
$affected = $stmt->rowCount();

 