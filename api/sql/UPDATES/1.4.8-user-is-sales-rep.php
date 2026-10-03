<?php
/**
 * Migration: 1.4.8-user-is-sales-rep
 * Kullanıcıya satış temsilcisi flag'i ekler. Pasif olanlar dropdown listelerinde görünmez.
 * Varsayılan: tüm aktif kullanıcılar satış temsilcisi.
 */

SchemaHelper::ensureColumn(
    'users',
    'is_sales_rep',
    "TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Satış temsilcisi olarak görünsün mü'",
    'is_active'
);
