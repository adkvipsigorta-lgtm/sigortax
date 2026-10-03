<?php
/**
 * Migration: 1.4.4-branch-is-active
 *
 * Acentelere aktif/pasif durumu eklenir.
 * Pasif acenteler formların dropdown listelerinde görünmez.
 * Varsayılan: tüm mevcut acenteler aktif.
 */

SchemaHelper::ensureColumn('branches', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `iban`");
