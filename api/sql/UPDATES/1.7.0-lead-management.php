<?php
/**
 * Migration: 1.7.0-lead-management
 * Lead yonetimi tablolari: lead_sources, lead_products, leads, lead_activities
 * + permissions tablosuna lead izinleri
 * + settings tablosuna lead atama ayari
 */

// 1) Lead Kaynaklari
SchemaHelper::ensureTable('lead_sources', [
    'id'         => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'name'       => "VARCHAR(100) NOT NULL COMMENT 'Kaynak adi'",
    'color'      => "VARCHAR(7) NULL COMMENT 'Badge rengi (#hex)'",
    'is_active'  => "TINYINT(1) NOT NULL DEFAULT 1",
    'auto_assign_to' => "INT UNSIGNED NULL COMMENT 'Otomatik atanacak kullanici id'",
    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at' => 'DATETIME NULL',
    'deleted_at' => 'DATETIME NULL',
]);

// Varsayilan kaynaklar
$defaultSources = ['sigortax.net', 'adkvipsigorta.com', 'Musteri Portali', 'Allianz', 'Manuel'];
foreach ($defaultSources as $src) {
    $exists = Database::fetch("SELECT id FROM lead_sources WHERE name = ? AND deleted_at IS NULL", [$src]);
    if (!$exists) {
        Database::insert('lead_sources', [
            'name' => $src,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

// 2) Lead Urunleri
SchemaHelper::ensureTable('lead_products', [
    'id'         => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'name'       => "VARCHAR(100) NOT NULL COMMENT 'Urun adi'",
    'color'      => "VARCHAR(7) NULL COMMENT 'Badge rengi (#hex)'",
    'is_active'  => "TINYINT(1) NOT NULL DEFAULT 1",
    'sort_order' => "INT NOT NULL DEFAULT 0",
    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'deleted_at' => 'DATETIME NULL',
]);

// 3) Leads ana tablosu
SchemaHelper::ensureTable('leads', [
    'id'             => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'source_id'      => "INT UNSIGNED NULL COMMENT 'lead_sources FK'",
    'product_id'     => "INT UNSIGNED NULL COMMENT 'lead_products FK'",
    'assigned_to'    => "INT UNSIGNED NULL COMMENT 'Atanan kullanici (users FK)'",
    'status'         => "ENUM('ACIK','DEVAM','KAZANILDI','KAYBEDILDI') NOT NULL DEFAULT 'ACIK'",
    'full_name'      => "VARCHAR(255) NULL COMMENT 'Ad Soyad'",
    'tc_no'          => "VARCHAR(11) NULL COMMENT 'TC Kimlik No'",
    'birth_date'     => "DATE NULL COMMENT 'Dogum Tarihi'",
    'phone'          => "VARCHAR(20) NOT NULL COMMENT 'Telefon'",
    'lost_reason'    => "TEXT NULL COMMENT 'Kaybedilme nedeni'",
    'converted_policy_id' => "INT UNSIGNED NULL COMMENT 'Donusen police id'",
    'created_by'     => "INT UNSIGNED NULL COMMENT 'Olusturan kullanici'",
    'created_at'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at'     => 'DATETIME NULL',
    'closed_at'      => "DATETIME NULL COMMENT 'Kapanma tarihi'",
    'deleted_at'     => 'DATETIME NULL',
]);

SchemaHelper::ensureIndex('leads', 'idx_leads_status', ['status']);
SchemaHelper::ensureIndex('leads', 'idx_leads_assigned', ['assigned_to']);
SchemaHelper::ensureIndex('leads', 'idx_leads_source', ['source_id']);
SchemaHelper::ensureIndex('leads', 'idx_leads_phone', ['phone']);

// 4) Lead Aktiviteleri (zaman cizelgesi)
SchemaHelper::ensureTable('lead_activities', [
    'id'         => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'lead_id'    => "INT UNSIGNED NOT NULL COMMENT 'leads FK'",
    'user_id'    => "INT UNSIGNED NOT NULL COMMENT 'Islemi yapan kullanici'",
    'type'       => "ENUM('NOT','ARAMA','DURUM','ATAMA') NOT NULL DEFAULT 'NOT'",
    'content'    => "TEXT NULL COMMENT 'Not icerigi'",
    'old_value'  => "VARCHAR(255) NULL",
    'new_value'  => "VARCHAR(255) NULL",
    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('lead_activities', 'idx_lead_act_lead', ['lead_id']);

// 5) Lead izinlerini permissions tablosuna ekle
$leadPerms = [
    ['leads', 'Lead Yonetimi', 'leads.view', 'Leadleri Goruntule', 100],
    ['leads', 'Lead Yonetimi', 'leads.manage', 'Lead Ekle/Duzenle', 101],
    ['leads', 'Lead Yonetimi', 'leads.delete', 'Lead Sil', 102],
    ['leads', 'Lead Yonetimi', 'leads.assign', 'Lead Ata', 103],
    ['leads', 'Lead Yonetimi', 'leads.settings', 'Lead Ayarlari', 104],
];

foreach ($leadPerms as [$group, $groupLabel, $key, $label, $sort]) {
    $exists = Database::fetch("SELECT `key` FROM permissions WHERE `key` = ?", [$key]);
    if (!$exists) {
        Database::insert('permissions', [
            'group' => $group,
            'group_label' => $groupLabel,
            'key' => $key,
            'label' => $label,
            'sort_order' => $sort,
        ]);
    }
}

// 6) Lead atama ayari (settings tablosu)
$setting = Database::fetch("SELECT `key` FROM settings WHERE `key` = 'lead_auto_assign'");
if (!$setting) {
    Database::insert('settings', [
        'key' => 'lead_auto_assign',
        'value' => 'manual',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
