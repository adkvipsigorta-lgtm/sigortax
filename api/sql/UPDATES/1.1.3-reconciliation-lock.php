<?php
/**
 * Migration: Mutabakat kilidi ozelligi.
 * - policies tablosuna reconciliation_status alani eklenir (PENDING / RECONCILED)
 */

$pdo = Database::getInstance();

// reconciliation_status alani ekle
SchemaHelper::ensureColumn('policies', 'reconciliation_status', "VARCHAR(20) NOT NULL DEFAULT 'PENDING'", 'is_approved');
