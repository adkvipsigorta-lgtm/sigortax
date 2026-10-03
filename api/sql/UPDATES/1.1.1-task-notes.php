<?php
/**
 * Migration: task_notes tablosu.
 * Gorev satirlarina hizli not ekleme ozelligini destekler.
 */

SchemaHelper::ensureTable('task_notes', [
    'id'         => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'task_id'    => 'BIGINT UNSIGNED NOT NULL',
    'note'       => 'TEXT NOT NULL',
    'created_by' => 'INT UNSIGNED NOT NULL',
    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
]);

SchemaHelper::ensureIndex('task_notes', 'idx_task_notes_task_id', ['task_id']);
