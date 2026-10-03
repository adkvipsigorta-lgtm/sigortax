<?php
/**
 * Migration: 1.7.4-webhook
 * Webhook entegrasyonu: webhook_event_id kolonu + API key ayari
 */

// leads tablosuna webhook_event_id kolonu (idempotency icin)
SchemaHelper::ensureColumn('leads', 'webhook_event_id', "VARCHAR(100) NULL COMMENT 'Webhook event ID (idempotency)'", 'created_by');

// Unique index: ayni event iki kez islenmez (NULL degerler haric)
SchemaHelper::ensureIndex('leads', 'idx_leads_webhook_event', ['webhook_event_id'], true);

// Webhook kaynak bilgisi
SchemaHelper::ensureColumn('leads', 'webhook_source', "VARCHAR(50) NULL COMMENT 'Webhook kaynak domain'", 'webhook_event_id');

// Webhook API Key ayari
$setting = Database::fetch("SELECT `key` FROM settings WHERE `key` = 'webhook_api_key'");
if (!$setting) {
    // Guvenli rastgele key olustur
    $key = 'whk_' . bin2hex(random_bytes(16));
    Database::insert('settings', [
        'key' => 'webhook_api_key',
        'value' => $key,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
