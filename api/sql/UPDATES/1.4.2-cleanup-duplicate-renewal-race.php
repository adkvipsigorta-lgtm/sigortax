<?php
/**
 * Migration: 1.4.2-cleanup-duplicate-renewal-race
 *
 * Sorunlar:
 * 1) Race condition — eşzamanlı cron çağrıları aynı policy_id için mükerrer görev oluşturuyordu
 * 2) Zeyilname sorunu — aynı policy_no'nun farklı endorsement'ları farklı policy_id alıyor,
 *    NOT EXISTS kontrolü eski policy_id'ye bağlı görevi görmüyordu
 *
 * Çözüm (runtime):
 * - GET_LOCK advisory lock (race condition)
 * - NOT EXISTS artık policy_no bazlı (zeyilname sorunu)
 *
 * Bu migration (tek seferlik temizlik):
 * 1) RENEWAL: Aynı policy_id mükerrerler
 * 2) RENEWAL: Aynı policy_no farklı policy_id mükerrerler (zeyilname kaynaklı)
 * 3) FOLLOW_UP_CALL: Aynı policy_id + aynı stage mükerrerler
 * Mantık: Atanmışı koru, atanmamış fazlalıkları sil. Hepsi atanmamışsa en eskisini koru.
 */

$now = date('Y-m-d H:i:s');
$pdo = Database::getInstance();
$cleaned = 0;

// --- Yardımcı fonksiyon: mükerrer grubu temizle ---
$cleanGroup = function (array $rows) use ($pdo, $now, &$cleaned) {
    if (count($rows) <= 1) return;

    $assigned = array_filter($rows, fn($r) => !empty($r['assigned_to']));
    $unassigned = array_filter($rows, fn($r) => empty($r['assigned_to']));

    $deleteIds = [];

    if (count($assigned) > 0) {
        $deleteIds = array_column($unassigned, 'id');
        if (count($assigned) > 1) {
            $assignedIds = array_column($assigned, 'id');
            array_shift($assignedIds);
            $deleteIds = array_merge($deleteIds, $assignedIds);
        }
    } else {
        $allIds = array_column($rows, 'id');
        array_shift($allIds);
        $deleteIds = $allIds;
    }

    if (empty($deleteIds)) return;

    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE tasks
        SET status = 'CANCELLED', deleted_at = ?, updated_at = ?
        WHERE id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$now, $now], $deleteIds));
    $cleaned += count($deleteIds);
};

// --- 1) Mükerrer RENEWAL görevleri (aynı policy_id) ---
$dupes = $pdo->query("
    SELECT policy_id FROM tasks
    WHERE type = 'RENEWAL' AND deleted_at IS NULL
    GROUP BY policy_id HAVING COUNT(*) > 1
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($dupes as $policyId) {
    $rows = $pdo->prepare("
        SELECT id, assigned_to FROM tasks
        WHERE type = 'RENEWAL' AND policy_id = ? AND deleted_at IS NULL
        ORDER BY id ASC
    ");
    $rows->execute([$policyId]);
    $cleanGroup($rows->fetchAll(PDO::FETCH_ASSOC));
}

// --- 2) Mükerrer RENEWAL görevleri (aynı policy_no, farklı policy_id — zeyilname kaynaklı) ---
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
    $cleanGroup($rows->fetchAll(PDO::FETCH_ASSOC));
}

// --- 3) Mükerrer FOLLOW_UP_CALL görevleri (aynı policy_id + aynı stage) ---
$fcDupes = $pdo->query("
    SELECT policy_id, JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.stage')) as stage
    FROM tasks
    WHERE type = 'FOLLOW_UP_CALL' AND deleted_at IS NULL
    GROUP BY policy_id, stage HAVING COUNT(*) > 1
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($fcDupes as $d) {
    $rows = $pdo->prepare("
        SELECT id, assigned_to FROM tasks
        WHERE type = 'FOLLOW_UP_CALL' AND policy_id = ? AND deleted_at IS NULL
          AND JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.stage')) = ?
        ORDER BY id ASC
    ");
    $rows->execute([$d['policy_id'], $d['stage']]);
    $cleanGroup($rows->fetchAll(PDO::FETCH_ASSOC));
}

if ($cleaned > 0) {
    error_log("[Migration 1.4.2] $cleaned mükerrer görev temizlendi (RENEWAL + FOLLOW_UP_CALL)");
}
