<?php
/**
 * Migration: 1.3.6-cleanup-duplicate-completed-renewal-tasks
 *
 * Sorun: Cron bug'ı nedeniyle aynı poliçe için birden fazla COMPLETED RENEWAL
 * görevi oluştu. Her biri tamamlanırken görev notu da yazıldı.
 *
 * Bu migration:
 * 1. Her poliçe için en eski (ilk) COMPLETED görevi korur.
 * 2. Sonradan oluşan duplicate COMPLETED görevleri ve notlarını siler.
 */

$pdo = Database::getInstance();
$now = date('Y-m-d H:i:s');

$keepIds = $pdo->query("
    SELECT MIN(id) as keep_id
    FROM tasks
    WHERE type = 'RENEWAL'
      AND status = 'COMPLETED'
      AND deleted_at IS NULL
    GROUP BY policy_id
    HAVING COUNT(*) > 1
")->fetchAll(PDO::FETCH_COLUMN);

if (!empty($keepIds)) {
   
$keepPlaceholders = implode(',', array_fill(0, count($keepIds), '?'));

$stmt = $pdo->prepare("
    SELECT t.id
    FROM tasks t
    WHERE t.type = 'RENEWAL'
      AND t.status = 'COMPLETED'
      AND t.deleted_at IS NULL
      AND t.id NOT IN ($keepPlaceholders)
      AND t.policy_id IN (
          SELECT policy_id FROM tasks WHERE id IN ($keepPlaceholders)
      )
");
$stmt->execute(array_merge($keepIds, $keepIds));
$deleteIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!empty($deleteIds)) {
    $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));

$pdo->prepare("DELETE FROM task_notes WHERE task_id IN ($deletePlaceholders)")->execute($deleteIds);
$pdo->prepare("DELETE FROM task_logs WHERE task_id IN ($deletePlaceholders)")->execute($deleteIds);
$pdo->prepare("
    UPDATE tasks
    SET status = 'CANCELLED', deleted_at = ?, updated_at = ?
    WHERE id IN ($deletePlaceholders)
")->execute(array_merge([$now, $now], $deleteIds));
}



}
