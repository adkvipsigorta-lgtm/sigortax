<?php
/**
 * Migration: documents tablosu (sifreleme destegi ile)
 */

SchemaHelper::ensureTable('documents', [
    'id'              => 'INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT',
    'name'            => 'VARCHAR(255) NOT NULL',
    'file_path'       => "VARCHAR(255) NOT NULL DEFAULT ''",
    'storage_hash'    => 'VARCHAR(64) NULL',
    'mime_type'       => 'VARCHAR(100) NULL',
    'file_size'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'encrypted'       => 'TINYINT(1) NOT NULL DEFAULT 0',
    'file_iv'         => 'VARBINARY(16) NULL',
    'file_auth_tag'   => 'VARBINARY(16) NULL',
    'dek_encrypted'   => 'VARBINARY(128) NULL',
    'dek_iv'          => 'VARBINARY(16) NULL',
    'dek_auth_tag'    => 'VARBINARY(16) NULL',
    'customer_id'     => 'INT UNSIGNED NULL',
    'policy_id'       => 'INT UNSIGNED NULL',
    'uploaded_by'     => 'INT UNSIGNED NULL',
    'created_at'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'deleted_at'      => 'DATETIME NULL',
]);

SchemaHelper::ensureIndex('documents', 'idx_documents_customer_id', ['customer_id']);
SchemaHelper::ensureIndex('documents', 'idx_documents_policy_id', ['policy_id']);
SchemaHelper::ensureIndex('documents', 'idx_documents_storage_hash', ['storage_hash']);
