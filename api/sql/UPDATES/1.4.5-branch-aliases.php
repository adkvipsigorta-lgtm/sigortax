<?php
/**
 * Migration: 1.4.5-branch-aliases
 *
 * Acentelere alternatif isimler (aliases) eklenir.
 * JSON array formatında saklanır: ["LAVİNYA SİGORTA ARAC...", "ZUHAL SİGORTA..."]
 * Gemini PDF eşleştirmede hem name hem aliases kullanılır.
 */

SchemaHelper::ensureColumn('branches', 'aliases', "JSON NULL AFTER `is_active`");
