<?php
/**
 * Migration: 1.5.0-customer-portal
 * Musteri portali icin:
 * - users tablosuna customer_ids (JSON) alani ekler (role=3 icin bagli musteri ID listesi)
 * - role=3 destegi: musteri kullanicisi
 * - settings tablosuna customer_portal_enabled varsayilan deger ekler
 */

// users tablosuna customer_ids kolonu ekle
SchemaHelper::ensureColumn(
    'users',
    'customer_ids',
    "JSON DEFAULT NULL COMMENT 'Musteri portali: bagli musteri ID listesi (role=3)'",
    'is_sales_rep'
);

// Settings tablosuna portal ayari ekle (yoksa)
$existing = Database::fetch("SELECT id FROM settings WHERE `key` = 'customer_portal_enabled'");
if (!$existing) {
    Database::insert('settings', [
        'key' => 'customer_portal_enabled',
        'value' => 'false',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}
