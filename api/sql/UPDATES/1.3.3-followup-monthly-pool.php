<?php
/**
 * 1.3.3 - Takip Araması: Aylık Görev Havuzu mimarisi
 *
 * Değişiklikler:
 *   1. tasks.scheduled_for kolonu eklenir.
 *      Bu alan FOLLOW_UP_CALL görevleri için aktivasyon/görünme tarihini tutar
 *      (= starts_at + stageDays). Temsilci görevi listede görür ancak bu tarih
 *      gelene kadar aramayı tamamlayamaz.
 *
 *   2. Mevcut tüm FOLLOW_UP_CALL görevleri soft-delete edilir.
 *      Sistem, yeni "Aylık Pencere" mantığıyla sıfırdan başlayacak.
 *      Diğer görev tiplerine (RENEWAL, OFFER, CROSS_SELL vb.) dokunulmaz.
 */

$pdo = Database::getInstance();
$now = date('Y-m-d H:i:s');

 
SchemaHelper::ensureColumn(
    'tasks',
    'scheduled_for',
    'DATE NULL',
    'deadline'
);
 
// 2. Mevcut tüm FOLLOW_UP_CALL görevlerini soft-delete et
$deleted = $pdo->exec("
    UPDATE tasks
    SET deleted_at = '$now',
        updated_at = '$now'
    WHERE type = 'FOLLOW_UP_CALL'
      AND deleted_at IS NULL
");

 