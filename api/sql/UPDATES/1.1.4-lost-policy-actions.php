<?php
/**
 * Migration: lost_policy_actions tablosu.
 * Kacirilan policeler (lost-policies) modulu icin durum/not kayitlari.
 * Daha once kodda referans veriliyordu ama tablo hicbir migration ile
 * olusturulmuyordu; bos veritabaninda / online sunucuda ozellik patliyordu.
 */

SchemaHelper::ensureTable('lost_policy_actions', [
    'id'         => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'policy_id'  => 'INT UNSIGNED NOT NULL',
    'status'     => "VARCHAR(20) NOT NULL DEFAULT 'PENDING'",
    'note'       => 'TEXT NULL',
    'updated_by' => 'INT UNSIGNED NULL',
    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('lost_policy_actions', 'uniq_lost_policy_policy', ['policy_id'], true);
