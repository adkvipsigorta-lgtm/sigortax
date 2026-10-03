<?php
/**
 * Migration: 1.7.2-lead-files
 * Lead dosya yükleme: lead_products.requires_file, lead_files tablosu
 */

// lead_products tablosuna requires_file kolonu
SchemaHelper::ensureColumn('lead_products', 'requires_file', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Dosya yükleme gerekli mi'", 'sort_order');

// Lead dosyaları tablosu
SchemaHelper::ensureTable('lead_files', [
    'id'            => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'lead_id'       => "INT UNSIGNED NOT NULL COMMENT 'leads FK'",
    'original_name' => "VARCHAR(255) NOT NULL COMMENT 'Orijinal dosya adı'",
    'file_path'     => "VARCHAR(500) NOT NULL COMMENT 'Sunucu dosya yolu'",
    'file_size'     => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dosya boyutu (byte)'",
    'mime_type'     => "VARCHAR(100) NULL COMMENT 'Dosya tipi'",
    'uploaded_by'   => "INT UNSIGNED NOT NULL COMMENT 'Yükleyen kullanıcı'",
    'created_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('lead_files', 'idx_lead_files_lead', ['lead_id']);
