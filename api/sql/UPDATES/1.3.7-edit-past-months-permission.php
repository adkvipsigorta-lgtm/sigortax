<?php
/**
 * Migration: 1.3.7-edit-past-months-permission
 * Gecmis ay policelerini duzenleme izni ekler.
 * Yonetici (role=1) otomatik olarak bu izne sahiptir (*).
 * Diger kullanicilara izinler ekranindan verilebilir.
 */

$pdo = Database::getInstance();

$pdo->prepare("
    INSERT IGNORE INTO `permissions` (`id`, `key`, `group`, `group_label`, `label`, `sort_order`)
    VALUES (33, 'policies.edit_past_months', 'policies', 'Poliçeler', 'Geçmiş Ay Düzenle', 24)
")->execute();
