<?php
/**
 * Migration: 1.4.6-business-type
 *
 * Referans kaynaklarına iş türü (Yeni İş / Yenileme) eklenir.
 * Poliçelere iş türü kolonu eklenir.
 * Mevcut verilere dokunulmaz — eski poliçelerde business_type NULL kalır.
 */

// reference_sources tablosuna business_type ekle
SchemaHelper::ensureColumn('reference_sources', 'business_type', "ENUM('NEW','RENEWAL') NULL AFTER `commission_rate`");

// policies tablosuna business_type ekle
SchemaHelper::ensureColumn('policies', 'business_type', "ENUM('NEW','RENEWAL') NULL AFTER `production_type`");

// Mevcut referans kaynaklarını iş türüne göre güncelle (isimlerine göre)
$pdo = Database::getInstance();

// Yenileme olanlar
$pdo->exec("UPDATE reference_sources SET business_type = 'RENEWAL' WHERE LOWER(name) IN ('yenileme', 'mevcut müşteri', 'mevcut musteri') AND business_type IS NULL AND deleted_at IS NULL");

// Geri kalanlar yeni iş
$pdo->exec("UPDATE reference_sources SET business_type = 'NEW' WHERE business_type IS NULL AND deleted_at IS NULL");
