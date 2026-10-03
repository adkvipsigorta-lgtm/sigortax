<?php
/**
 * Migration: insurance_types.is_renewable kolonu.
 * Bircok yerde (renewals, lost-policies, task filtreleme) kullaniliyordu
 * ama hicbir migration/sema ile eklenmiyordu; bos veritabaninda /
 * online sunucuda ilgili sorgular "Unknown column it.is_renewable" hatasi veriyordu.
 * Varsayilan 1 (cogu sigorta turu yenilenebilir).
 */

SchemaHelper::ensureColumn('insurance_types', 'is_renewable', 'TINYINT(1) NOT NULL DEFAULT 1', 'show_in_charts');
