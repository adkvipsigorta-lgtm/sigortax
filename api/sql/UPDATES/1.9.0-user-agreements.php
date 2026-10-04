<?php

/**
 * 1.9.0 — Personel Onboarding & Sözleşme Sistemi
 *
 * - users tablosuna yeni alanlar (kişisel telefon, şirket telefonu, adres, doğum tarihi vb.)
 * - user_agreements tablosu (KVKK + Taahhütname onay kayıtları)
 * - agreement_documents tablosu (belge versiyonları)
 */

use SchemaHelper as S;

// ── Users tablosu yeni alanlar ──
S::ensureColumn('users', 'tc_no', 'VARCHAR(11) DEFAULT NULL', 'phone');
S::ensureColumn('users', 'personal_phone', 'VARCHAR(20) DEFAULT NULL', 'phone');
S::ensureColumn('users', 'company_phone', 'VARCHAR(20) DEFAULT NULL', 'personal_phone');
S::ensureColumn('users', 'personal_email', 'VARCHAR(255) DEFAULT NULL', 'email');
S::ensureColumn('users', 'birth_date', 'DATE DEFAULT NULL', 'tc_no');
S::ensureColumn('users', 'address', 'TEXT DEFAULT NULL', 'personal_email');
S::ensureColumn('users', 'identity_front', 'VARCHAR(255) DEFAULT NULL', 'address');
S::ensureColumn('users', 'identity_back', 'VARCHAR(255) DEFAULT NULL', 'identity_front');
S::ensureColumn('users', 'onboarding_completed', 'TINYINT(1) NOT NULL DEFAULT 0', 'identity_back');

// Mevcut phone alanını "kişisel telefon" olarak kullanmaya devam ediyoruz
// personal_phone = kişisel telefon (onay SMS), company_phone = şirket telefonu (2FA)
// phone alanı geriye uyumluluk için korunuyor

// ── Sözleşme belge versiyonları ──
S::ensureTable('agreement_documents', [
    'id'          => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'type'        => "VARCHAR(30) NOT NULL COMMENT 'KVKK veya COMMITMENT'",
    'version'     => "VARCHAR(20) NOT NULL COMMENT 'v1.0, v1.1 vb.'",
    'title'       => 'VARCHAR(255) NOT NULL',
    'content'     => "LONGTEXT NOT NULL COMMENT 'HTML belge içeriği'",
    'is_active'   => 'TINYINT(1) NOT NULL DEFAULT 1',
    'created_at'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);
S::ensureIndex('agreement_documents', 'idx_agreement_type_version', ['type', 'version']);

// ── Kullanıcı sözleşme onay kayıtları ──
S::ensureTable('user_agreements', [
    'id'                  => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'user_id'             => 'INT NOT NULL',
    'agreement_type'      => "VARCHAR(30) NOT NULL COMMENT 'KVKK veya COMMITMENT'",
    'agreement_version'   => "VARCHAR(20) NOT NULL",
    'agreement_doc_id'    => 'INT UNSIGNED DEFAULT NULL',
    'accepted_at'         => 'DATETIME NOT NULL',
    'ip_address'          => 'VARCHAR(45) DEFAULT NULL',
    'user_agent'          => 'TEXT DEFAULT NULL',
    'sms_phone'           => "VARCHAR(20) DEFAULT NULL COMMENT 'Doğrulama SMS gönderilen telefon'",
    'sms_verified'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'SMS doğrulaması yapıldı mı'",
    'sms_verified_at'     => 'DATETIME DEFAULT NULL',
]);
S::ensureIndex('user_agreements', 'idx_user_agreement', ['user_id', 'agreement_type', 'agreement_version']);

// ── SMS doğrulama kodları (geçici) ──
S::ensureTable('agreement_sms_codes', [
    'id'          => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'user_id'     => 'INT NOT NULL',
    'phone'       => 'VARCHAR(20) NOT NULL',
    'code'        => "VARCHAR(6) NOT NULL",
    'type'        => "VARCHAR(30) NOT NULL COMMENT 'KVKK veya COMMITMENT'",
    'attempts'    => 'INT NOT NULL DEFAULT 0',
    'used'        => 'TINYINT(1) NOT NULL DEFAULT 0',
    'expires_at'  => 'DATETIME NOT NULL',
    'created_at'  => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);
S::ensureIndex('agreement_sms_codes', 'idx_sms_code_user', ['user_id', 'type', 'used']);

// ── Belge seed — yalnızca yoksa ekle ──
$existingKvkk = Database::fetch("SELECT id FROM agreement_documents WHERE type = 'KVKK' AND version = 'v1.0'");
if (!$existingKvkk) {
    Database::query(
        "INSERT INTO agreement_documents (type, version, title, content, is_active) VALUES (?, ?, ?, ?, 1)",
        ['KVKK', 'v1.0', 'KVKK Aydınlatma, Açık Rıza ve Sorumluluk Metni', file_get_contents(__DIR__ . '/../seeds/kvkk-v1.html')]
    );
}

$existingCommitment = Database::fetch("SELECT id FROM agreement_documents WHERE type = 'COMMITMENT' AND version = 'v1.0'");
if (!$existingCommitment) {
    Database::query(
        "INSERT INTO agreement_documents (type, version, title, content, is_active) VALUES (?, ?, ?, ?, 1)",
        ['COMMITMENT', 'v1.0', 'Gizlilik, İş Güvenliği, İş Kuralları ve Rekabet Yasağı Taahhütnamesi', file_get_contents(__DIR__ . '/../seeds/commitment-v1.html')]
    );
}

// ── Admin kullanıcılar onboarding'den muaf ──
Database::query("UPDATE users SET onboarding_completed = 1 WHERE role = 1 AND deleted_at IS NULL");
