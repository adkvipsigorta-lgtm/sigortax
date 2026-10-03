<?php
/**
 * Migration: RENEWAL gorevlerinin offer_expires_at sutununu policy.expires_at'tan doldur.
 * Boylece OFFER ve RENEWAL ayni sutunu kullanir, days_remaining hesabi tek kolondan.
 */

$pdo = Database::getInstance();

$stmt = $pdo->prepare(
    "UPDATE tasks t
     INNER JOIN policies p ON t.policy_id = p.id
     SET t.offer_expires_at = p.expires_at
     WHERE t.type = 'RENEWAL'
       AND p.expires_at IS NOT NULL
       AND t.offer_expires_at IS NULL"
);
$stmt->execute();
