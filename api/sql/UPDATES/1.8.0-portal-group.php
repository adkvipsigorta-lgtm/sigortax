<?php
/**
 * Migration: 1.8.0-portal-group
 * Müşteri portalı grup şirketi desteği
 * Aynı portal_group_id'ye sahip müşteriler portalda birbirinin poliçelerini görür
 */

SchemaHelper::ensureColumn('customers', 'portal_group_id', "INT UNSIGNED DEFAULT NULL COMMENT 'Portal grup ID — ayni gruptaki musteriler portalda birlikte gorunur'", 'identity_no');
SchemaHelper::ensureIndex('customers', 'idx_portal_group', ['portal_group_id']);
