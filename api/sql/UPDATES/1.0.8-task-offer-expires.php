<?php
/**
 * Migration: tasks.offer_expires_at sutunu + JSON'dan mevcut veriyi tasi.
 *
 * Adimlar:
 *   1) offer_expires_at DATE NULL sutunu ekle (idempotent)
 *   2) idx_tasks_offer_expires_at indexini ekle (idempotent)
 *   3) Mevcut OFFER kayitlarini oku, offer_data JSON'undan expiresAt'i cikar,
 *      sutuna yaz. Sadece sutun NULL olanlari gunceller.
 */

SchemaHelper::ensureColumn('tasks', 'offer_expires_at', 'DATE NULL', 'deadline');
SchemaHelper::ensureIndex('tasks', 'idx_tasks_offer_expires_at', ['offer_expires_at']);

$pdo = Database::getInstance();
$rows = $pdo->query(
    "SELECT id, offer_data FROM tasks
     WHERE type = 'OFFER'
       AND offer_data IS NOT NULL
       AND offer_expires_at IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare("UPDATE tasks SET offer_expires_at = ? WHERE id = ?");
$updated = 0;
foreach ($rows as $r) {
    $data = json_decode($r['offer_data'], true);
    $exp = $data['expiresAt'] ?? null;
    if (!$exp) continue;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $exp)) continue;
    $upd->execute([substr($exp, 0, 10), $r['id']]);
    $updated++;
}

 