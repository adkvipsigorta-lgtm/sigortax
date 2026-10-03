<?php
/**
 * Migration: 1.6.1-portal-notifications
 * Müşteri portalı bildirim tablosu.
 * Gerçek olaylara bağlı bildirimler (vade yaklaşma, talep durumu vb.)
 */

SchemaHelper::ensureTable('portal_notifications', [
    'id'          => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
    'customer_id' => 'INT UNSIGNED NOT NULL',
    'type'        => "VARCHAR(30) NOT NULL COMMENT 'policy_expiring, request_update, general'",
    'title'       => 'VARCHAR(255) NOT NULL',
    'message'     => 'TEXT DEFAULT NULL',
    'ref_type'    => "VARCHAR(30) DEFAULT NULL COMMENT 'policy, request'",
    'ref_id'      => 'INT UNSIGNED DEFAULT NULL',
    'is_read'     => 'TINYINT(1) NOT NULL DEFAULT 0',
    'created_at'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);

if (!SchemaHelper::hasIndex('portal_notifications', 'idx_pn_customer_read')) {
    Database::query("ALTER TABLE portal_notifications ADD KEY idx_pn_customer_read (customer_id, is_read)");
}
if (!SchemaHelper::hasIndex('portal_notifications', 'idx_pn_created')) {
    Database::query("ALTER TABLE portal_notifications ADD KEY idx_pn_created (created_at)");
}
