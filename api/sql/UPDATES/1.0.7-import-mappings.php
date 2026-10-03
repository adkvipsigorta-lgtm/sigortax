<?php
/**
 * Migration: kullaniciya ozel excel import sablonlari
 */

SchemaHelper::ensureTable('import_mappings', [
    'id'           => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'user_id'      => 'INT UNSIGNED NOT NULL',
    'name'         => 'VARCHAR(100) NOT NULL',
    'mapping_json' => 'TEXT NOT NULL',
    'created_at'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('import_mappings', 'idx_im_user', ['user_id']);
SchemaHelper::ensureIndex('import_mappings', 'uq_im_user_name', ['user_id', 'name'], true);
