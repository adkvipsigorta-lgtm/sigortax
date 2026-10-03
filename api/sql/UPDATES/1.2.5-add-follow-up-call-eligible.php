<?php
/**
 * Migration: 1.2.5-add-follow-up-call-eligible
 *
 * insurance_types tablosuna follow_up_call_eligible kolonu eklenir.
 * Default 1 (tum turler eligible). Seyahat Saglik ve Dijital Doktorum
 * gibi turlerde 0 yapilarak takip aramasi olusturulmamasi saglanir.
 *
 * Etkilenen yer: TaskController::runCronJobs() — cron sorgusu bu kolona gore filtreler.
 */

$pdo = Database::getInstance();

// Kolon zaten var mi kontrol et
$col = $pdo->query("SHOW COLUMNS FROM insurance_types LIKE 'follow_up_call_eligible'")->fetch(PDO::FETCH_ASSOC);

if (!$col) {

    $pdo->exec("ALTER TABLE insurance_types ADD COLUMN `follow_up_call_eligible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `renewal_days`");

}

// Seyahat Saglik ve Dijital Doktorum turlerini 0 yap
$updated = $pdo->exec(
    "UPDATE insurance_types SET follow_up_call_eligible = 0
     WHERE (name LIKE '%Seyahat%' OR name LIKE '%Dijital Doktorum%')
       AND deleted_at IS NULL"
);


// Bu turlerden olusturulmus bekleyen/devam eden FOLLOW_UP_CALL gorevlerini iptal et
$cancelled = $pdo->exec(
    "UPDATE tasks t
     INNER JOIN policies p ON t.policy_id = p.id
     INNER JOIN insurance_types i ON p.insurance_type_id = i.id
     SET t.status = 'CANCELLED',
         t.result = 'FAILED',
         t.result_note = 'Sigorta turu takip aramasindan cikarildi',
         t.updated_at = NOW()
     WHERE t.type = 'FOLLOW_UP_CALL'
       AND t.status IN ('PENDING', 'IN_PROGRESS')
       AND t.deleted_at IS NULL
       AND i.follow_up_call_eligible = 0"
);
