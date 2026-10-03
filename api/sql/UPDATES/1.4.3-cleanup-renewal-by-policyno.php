<?php
/**
 * Migration: 1.4.3-cleanup-renewal-by-policyno
 *
 * Sorun: Zeyilname geldiğinde aynı policy_no farklı policy_id alıyor.
 * Cron'un NOT EXISTS kontrolü policy_id bazlı olduğu için eski görevi
 * görmüyor ve aynı poliçe numarası için ikinci RENEWAL görevi oluşturuyordu.
 *
 * Çözüm (runtime): NOT EXISTS artık policy_no bazlı kontrol ediyor.
 *
 * Bu migration: Aynı policy_no'ya ait mükerrer aktif RENEWAL görevlerini temizler.
 * Atanmışı korur, atanmamış fazlalıkları siler. Hepsi atanmamışsa en eskisini korur.
 */

$now = date('Y-m-d H:i:s');
$pdo = Database::getInstance();
$cleaned = 0;

$pnoDupes = $pdo->query("
    SELECT p.policy_no
    FROM tasks t
    INNER JOIN policies p ON t.policy_id = p.id
    WHERE t.type = 'RENEWAL' AND t.deleted_at IS NULL
      AND t.status IN ('PENDING', 'IN_PROGRESS', 'EXPIRED')
    GROUP BY p.policy_no
    HAVING COUNT(DISTINCT t.id) > 1
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($pnoDupes as $policyNo) {
    $rows = $pdo->prepare("
        SELECT t.id, t.assigned_to FROM tasks t
        INNER JOIN policies p ON t.policy_id = p.id
        WHERE t.type = 'RENEWAL' AND p.policy_no = ? AND t.deleted_at IS NULL
          AND t.status IN ('PENDING', 'IN_PROGRESS', 'EXPIRED')
        ORDER BY t.id ASC
    ");
    $rows->execute([$policyNo]);
    $all = $rows->fetchAll(PDO::FETCH_ASSOC);

    if (count($all) <= 1) continue;

    $assigned = array_filter($all, fn($r) => !empty($r['assigned_to']));
    $unassigned = array_filter($all, fn($r) => empty($r['assigned_to']));

    $deleteIds = [];
    if (count($assigned) > 0) {
        $deleteIds = array_column($unassigned, 'id');
        if (count($assigned) > 1) {
            $assignedIds = array_column($assigned, 'id');
            array_shift($assignedIds);
            $deleteIds = array_merge($deleteIds, $assignedIds);
        }
    } else {
        $allIds = array_column($all, 'id');
        array_shift($allIds);
        $deleteIds = $allIds;
    }

    if (empty($deleteIds)) continue;

    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE tasks
        SET status = 'CANCELLED', deleted_at = ?, updated_at = ?
        WHERE id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$now, $now], $deleteIds));
    $cleaned += count($deleteIds);
}

if ($cleaned > 0) {
    error_log("[Migration 1.4.3] $cleaned mükerrer RENEWAL görevi temizlendi (policy_no bazlı)");
}
