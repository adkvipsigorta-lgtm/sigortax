<?php
/**
 * 1.3.4 - Takip Araması: Hafta sonu scheduled_for düzeltmesi
 *
 * scheduled_for (starts_at + stageDays) Cumartesi veya Pazar'a denk gelen
 * FOLLOW_UP_CALL görevleri bir sonraki Pazartesi'ye kaydırılır.
 */

$pdo = Database::getInstance();
$now = date('Y-m-d H:i:s');

// Cumartesi (DAYOFWEEK=7) → +2 gün = Pazartesi
// Pazar     (DAYOFWEEK=1) → +1 gün = Pazartesi
$updated = $pdo->exec("
    UPDATE tasks
    SET scheduled_for = CASE
            WHEN DAYOFWEEK(scheduled_for) = 7 THEN DATE_ADD(scheduled_for, INTERVAL 2 DAY)
            WHEN DAYOFWEEK(scheduled_for) = 1 THEN DATE_ADD(scheduled_for, INTERVAL 1 DAY)
        END,
        updated_at = '$now'
    WHERE type = 'FOLLOW_UP_CALL'
      AND deleted_at IS NULL
      AND scheduled_for IS NOT NULL
      AND DAYOFWEEK(scheduled_for) IN (1, 7)
");

 