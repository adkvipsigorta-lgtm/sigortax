<?php
/**
 * Migration: 1.5.1-portal-otp
 * Müşteri portalı SMS doğrulama için OTP tablosu
 */

SchemaHelper::ensureTable('portal_otps', [
    'id'          => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'customer_id' => 'INT UNSIGNED NOT NULL',
    'phone'       => 'VARCHAR(20) NOT NULL',
    'code'        => 'VARCHAR(6) NOT NULL',
    'expires_at'  => 'DATETIME NOT NULL',
    'verified'    => 'TINYINT(1) NOT NULL DEFAULT 0',
    'attempts'    => 'TINYINT NOT NULL DEFAULT 0',
    'created_at'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('portal_otps', 'idx_otp_customer', ['customer_id']);
SchemaHelper::ensureIndex('portal_otps', 'idx_otp_expires', ['expires_at']);
