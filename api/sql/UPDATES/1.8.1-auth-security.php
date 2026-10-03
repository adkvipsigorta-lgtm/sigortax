<?php

/**
 * Migration: 1.8.1-auth-security
 *
 * - users: TOTP brute-force koruması, replay koruması, encrypted secret kolonları
 * - sessions: expires_at kolonu (zorunlu logout boundary)
 * - auth_challenges: challenge token tablosu (userId açığa çıkarmayan 2FA akışı)
 */

// ── users tablosuna güvenlik kolonları ──

SchemaHelper::ensureColumn('users', 'two_factor_secret_enc', 'TEXT DEFAULT NULL', 'two_factor_secret');
SchemaHelper::ensureColumn('users', 'two_factor_secret_iv', 'VARCHAR(32) DEFAULT NULL', 'two_factor_secret_enc');
SchemaHelper::ensureColumn('users', 'two_factor_secret_tag', 'VARCHAR(48) DEFAULT NULL', 'two_factor_secret_iv');

SchemaHelper::ensureColumn('users', 'two_factor_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0', 'two_factor_recovery_codes');
SchemaHelper::ensureColumn('users', 'two_factor_locked_until', 'DATETIME DEFAULT NULL', 'two_factor_attempts');
SchemaHelper::ensureColumn('users', 'two_factor_last_step', 'BIGINT DEFAULT NULL', 'two_factor_locked_until');

// ── sessions tablosuna expires_at ──

SchemaHelper::ensureColumn('sessions', 'expires_at', 'DATETIME DEFAULT NULL', 'logged_in_at');

// ── auth_challenges tablosu ──

SchemaHelper::ensureTable('auth_challenges', [
    'id'              => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'token'           => 'VARCHAR(128) NOT NULL',
    'user_id'         => 'INT UNSIGNED NOT NULL',
    'requires_setup'  => 'TINYINT(1) NOT NULL DEFAULT 0',
    'attempts'        => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'max_attempts'    => 'INT UNSIGNED NOT NULL DEFAULT 5',
    'used'            => 'TINYINT(1) NOT NULL DEFAULT 0',
    'ip_address'      => 'VARCHAR(45) DEFAULT NULL',
    'expires_at'      => 'DATETIME NOT NULL',
    'created_at'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('auth_challenges', 'idx_auth_challenges_token', ['token'], true);
SchemaHelper::ensureIndex('auth_challenges', 'idx_auth_challenges_expires', ['expires_at']);
