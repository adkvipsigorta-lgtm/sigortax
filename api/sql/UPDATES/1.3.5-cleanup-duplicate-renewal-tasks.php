<?php
/**
 * Migration: 1.3.5-cleanup-duplicate-renewal-tasks
 *
 * Sorun: Cron job'daki NOT EXISTS koşulu COMPLETED görevleri kontrol etmiyordu.
 * Bu yüzden kullanıcı bir yenileme görevini tamamlayınca cron, aynı poliçe için
 * yeni bir PENDING görev oluşturuyordu.
 *
 * Bu migration:
 * COMPLETED görevi olan poliçelere ait sahte PENDING/IN_PROGRESS/EXPIRED görevleri temizler.
 */

$now = date('Y-m-d H:i:s');

$pdo = Database::getInstance();

// MySQL: UPDATE ile aynı tabloyu doğrudan subquery'de kullanmak yasak.
// Çözüm: subquery'yi bir kat daha sarmalayarak derived table üretmek.
$pdo->exec("
    UPDATE tasks t
    INNER JOIN (
        SELECT id FROM (
            SELECT t1.id FROM tasks t1
            WHERE t1.type = 'RENEWAL'
              AND t1.status IN ('PENDING', 'IN_PROGRESS', 'EXPIRED')
              AND t1.deleted_at IS NULL
              AND EXISTS (
                  SELECT 1 FROM tasks t2
                  WHERE t2.type = 'RENEWAL'
                    AND t2.policy_id = t1.policy_id
                    AND t2.status = 'COMPLETED'
                    AND t2.deleted_at IS NULL
              )
        ) inner_q
    ) dup ON t.id = dup.id
    SET t.status = 'CANCELLED',
        t.deleted_at = '$now',
        t.updated_at = '$now'
");
