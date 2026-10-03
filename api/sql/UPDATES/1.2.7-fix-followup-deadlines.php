<?php
/**
 * 1.2.7 - Takip Araması Deadline Düzeltmesi
 *
 * Sorun:
 *   1.1.13 ve 1.2.0 backfill scriptleri deadline = NOW() + deadlineDays ile
 *   görev oluşturdu. 25.06.2026'da çalışan backfill, tüm görevlere aynı
 *   son tarihi (02.07.2026) verdi → yüzlerce görev tek güne yığıldı.
 *
 * Çözüm:
 *   Her görevin deadline'ı = poliçe başlangıcı (starts_at) + stageDays + deadlineDays
 *   olarak hesaplanmalıdır (doğal son tarih).
 *   Eğer hesaplanan tarih geçmişteyse → CURDATE() + deadlineDays kullanılır.
 *
 * Aşamalar:
 *   2ND_MONTH  : stageDays=60,  deadlineDays=7 → starts_at + 67 gün
 *   6TH_MONTH  : stageDays=180, deadlineDays=7 → starts_at + 187 gün
 *   10TH_MONTH : stageDays=300, deadlineDays=5 → starts_at + 305 gün
 */

$pdo = Database::getInstance();
$now = date('Y-m-d H:i:s');

// --- 2ND_MONTH: starts_at + 67 gün ---
$pdo->exec("
    UPDATE tasks t
    JOIN policies p ON t.policy_id = p.id
    SET t.deadline = IF(
        DATE_ADD(p.starts_at, INTERVAL 67 DAY) >= CURDATE(),
        CONCAT(DATE(DATE_ADD(p.starts_at, INTERVAL 67 DAY)), ' 23:59:59'),
        CONCAT(DATE(DATE_ADD(CURDATE(), INTERVAL 7 DAY)), ' 23:59:59')
    ),
    t.updated_at = '$now'
    WHERE t.type = 'FOLLOW_UP_CALL'
      AND t.status = 'PENDING'
      AND t.deleted_at IS NULL
      AND JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stage')) = '2ND_MONTH'
");

// --- 6TH_MONTH: starts_at + 187 gün ---
$pdo->exec("
    UPDATE tasks t
    JOIN policies p ON t.policy_id = p.id
    SET t.deadline = IF(
        DATE_ADD(p.starts_at, INTERVAL 187 DAY) >= CURDATE(),
        CONCAT(DATE(DATE_ADD(p.starts_at, INTERVAL 187 DAY)), ' 23:59:59'),
        CONCAT(DATE(DATE_ADD(CURDATE(), INTERVAL 7 DAY)), ' 23:59:59')
    ),
    t.updated_at = '$now'
    WHERE t.type = 'FOLLOW_UP_CALL'
      AND t.status = 'PENDING'
      AND t.deleted_at IS NULL
      AND JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stage')) = '6TH_MONTH'
");

// --- 10TH_MONTH: starts_at + 305 gün ---
$pdo->exec("
    UPDATE tasks t
    JOIN policies p ON t.policy_id = p.id
    SET t.deadline = IF(
        DATE_ADD(p.starts_at, INTERVAL 305 DAY) >= CURDATE(),
        CONCAT(DATE(DATE_ADD(p.starts_at, INTERVAL 305 DAY)), ' 23:59:59'),
        CONCAT(DATE(DATE_ADD(CURDATE(), INTERVAL 5 DAY)), ' 23:59:59')
    ),
    t.updated_at = '$now'
    WHERE t.type = 'FOLLOW_UP_CALL'
      AND t.status = 'PENDING'
      AND t.deleted_at IS NULL
      AND JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stage')) = '10TH_MONTH'
");
