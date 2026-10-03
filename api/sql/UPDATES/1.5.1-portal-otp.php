<?php
/**
 * Migration: 1.5.1-portal-otp
 * Müşteri portalı SMS doğrulama için OTP tablosu
 */

SchemaHelper::ensureTable('portal_otps', "
CREATE TABLE portal_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  code VARCHAR(6) NOT NULL,
  expires_at DATETIME NOT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  attempts TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_otp_customer (customer_id),
  KEY idx_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
