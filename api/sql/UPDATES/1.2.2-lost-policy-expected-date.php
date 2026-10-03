<?php
/**
 * Migration: 1.1.9-lost-policy-expected-date
 * lost_policy_actions tablosuna expected_date kolonunu ekler.
 * Kolon zaten varsa atlar.
 */

SchemaHelper::ensureColumn(
    'lost_policy_actions',
    'expected_date',
    "DATE NULL COMMENT 'Tahmini yenileme tarihi (elle girilirse)'",
    'note'
);
 
SchemaHelper::ensureColumn(
    'lost_policy_actions',
    'registration_no',
    "VARCHAR(50) NULL COMMENT 'Belge seri no'",
    'expected_date'
);