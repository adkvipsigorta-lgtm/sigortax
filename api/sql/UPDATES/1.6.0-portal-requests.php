<?php
/**
 * Migration: 1.6.0-portal-requests
 * Müşteri portalı talep sistemi tablosu.
 */

SchemaHelper::ensureTable('portal_requests', [
    'id'            => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
    'customer_id'   => 'INT UNSIGNED NOT NULL',
    'type'          => "VARCHAR(30) NOT NULL COMMENT 'phone_change, address_change, vehicle_change, family_member, policy_document, health_insurance, new_insurance, other'",
    'title'         => 'VARCHAR(255) NOT NULL',
    'message'       => 'TEXT DEFAULT NULL',
    'insurance_type'=> "VARCHAR(50) DEFAULT NULL COMMENT 'Yeni sigorta talebi için: saglik, trafik, kasko, konut, isyeri, diger'",
    'status'        => "VARCHAR(20) NOT NULL DEFAULT 'NEW' COMMENT 'NEW, IN_REVIEW, COMPLETED, CANCELLED'",
    'admin_reply'   => 'TEXT DEFAULT NULL',
    'replied_at'    => 'DATETIME DEFAULT NULL',
    'ip'            => 'VARCHAR(45) DEFAULT NULL',
    'created_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
]);

// Index'leri ekle
if (!SchemaHelper::hasIndex('portal_requests', 'idx_pr_customer')) {
    Database::query("ALTER TABLE portal_requests ADD KEY idx_pr_customer (customer_id)");
}
if (!SchemaHelper::hasIndex('portal_requests', 'idx_pr_status')) {
    Database::query("ALTER TABLE portal_requests ADD KEY idx_pr_status (status)");
}
if (!SchemaHelper::hasIndex('portal_requests', 'idx_pr_type')) {
    Database::query("ALTER TABLE portal_requests ADD KEY idx_pr_type (type)");
}
