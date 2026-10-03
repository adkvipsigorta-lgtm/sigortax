<?php
/**
 * Migration: 1.4.7-customer-has-vehicle
 * Müşteri araç durumu takibi için has_vehicle ve has_vehicle_checked_at kolonları.
 */

SchemaHelper::ensureColumn(
    'customers',
    'has_vehicle',
    "ENUM('UNKNOWN','YES','NO') NOT NULL DEFAULT 'UNKNOWN' COMMENT 'Araç durumu'",
    'note'
);

SchemaHelper::ensureColumn(
    'customers',
    'has_vehicle_checked_at',
    "DATETIME NULL COMMENT 'Araç durumu son kontrol tarihi'",
    'has_vehicle'
);
