<?php
/**
 * 1.2.6 - Follow-up backfill temizliği
 *
 * 1.1.13 ve 1.2.0 backfill scriptleri DATEDIFF >= stageDays kullandığı için
 * geçmişteki tüm poliçelere gereksiz yere toplu görev oluşturdu.
 *
 * Bu migration:
 *   a) Mükerrer FOLLOW_UP_CALL görevlerini siler (aynı policy + stage için
 *      en küçük ID dışındakiler)
 *   b) Geçerli pencere dışında kalan backfill görevlerini siler:
 *      - 2ND_MONTH  → poliçe başlangıcı backfill günü ±30 gün (60–90 gün önce)
 *      - 6TH_MONTH  → poliçe başlangıcı backfill günü ±30 gün (180–210 gün önce)
 *      - 10TH_MONTH → poliçe başlangıcı backfill günü ±30 gün (300–330 gün önce)
 */

$pdo = Database::getInstance();
$now = date('Y-m-d H:i:s');

// --- a) Mükerrer görevleri temizle (yüksek ID'li olanları sil) ---
$pdo->exec("
    UPDATE tasks t1
    INNER JOIN (
        SELECT MIN(id) as min_id, policy_id,
               JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.stage')) as stage
        FROM tasks
        WHERE type = 'FOLLOW_UP_CALL' AND deleted_at IS NULL
        GROUP BY policy_id, JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.stage'))
        HAVING COUNT(*) > 1
    ) keep_ids
        ON t1.policy_id = keep_ids.policy_id
       AND JSON_UNQUOTE(JSON_EXTRACT(t1.offer_data, '$.stage')) = keep_ids.stage
    SET t1.deleted_at = '$now'
    WHERE t1.type = 'FOLLOW_UP_CALL'
      AND t1.deleted_at IS NULL
      AND t1.id > keep_ids.min_id
");

// --- b) Pencere dışı backfill görevlerini temizle ---
// Backfill tarihi: backfill scriptlerin çalıştığı gün olan created_at kullanılır.
// Geçerli pencere: her stage için oluşturulduğu günde poliçe başlangıcının
// stageDays ila stageDays+30 gün önce olması gerekir.
$pdo->exec("
    UPDATE tasks t
    JOIN policies p ON t.policy_id = p.id
    SET t.deleted_at = '$now'
    WHERE t.type = 'FOLLOW_UP_CALL'
      AND t.status = 'PENDING'
      AND t.deleted_at IS NULL
      AND NOT (
          -- 2ND_MONTH: poliçe başlangıcı görev oluşturulma günü 60–90 gün önce
          (JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stage')) = '2ND_MONTH'
           AND DATEDIFF(DATE(t.created_at), DATE(p.starts_at)) BETWEEN 60 AND 90)
          OR
          -- 6TH_MONTH: 180–210 gün önce
          (JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stage')) = '6TH_MONTH'
           AND DATEDIFF(DATE(t.created_at), DATE(p.starts_at)) BETWEEN 180 AND 210)
          OR
          -- 10TH_MONTH: 300–330 gün önce
          (JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stage')) = '10TH_MONTH'
           AND DATEDIFF(DATE(t.created_at), DATE(p.starts_at)) BETWEEN 300 AND 330)
      )
");
