<?php
/**
 * Migration: 1.5.2-portal-otp-rate-limit
 * Portal OTP tablosuna rate limit için IP ve sms_sent kolonu ekler.
 * Global günlük SMS limiti ayarı ekler.
 */

SchemaHelper::ensureColumn('portal_otps', 'ip', "VARCHAR(45) DEFAULT NULL", 'phone');
SchemaHelper::ensureColumn('portal_otps', 'sms_sent', "TINYINT(1) NOT NULL DEFAULT 0", 'verified');

// Global günlük SMS limiti
$existing = Database::fetch("SELECT id FROM settings WHERE `key` = 'portal_daily_sms_limit'");
if (!$existing) {
    Database::insert('settings', [
        'key' => 'portal_daily_sms_limit',
        'value' => '500',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}
