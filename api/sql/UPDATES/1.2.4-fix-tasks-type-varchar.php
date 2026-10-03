<?php
/**
 * Migration: 1.2.4-fix-tasks-type-varchar
 *
 * Sorun: tasks.type kolonu bazi sunucularda hala ENUM olarak kalmis.
 * Migration 1.1.2 bu donusumu try/catch ile sessizce atliyordu.
 *
 * Bu migration idempotent sekilde kolonun VARCHAR(30) oldugunu garantiler.
 * FOLLOW_UP_CALL ve REFERENCE gorevlerinin insert/query edilebilmesi icin
 * VARCHAR olmasi zorunludur.
 */

$pdo = Database::getInstance();

$col = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'")->fetch(PDO::FETCH_ASSOC);

if ($col) {
 

$colType = strtolower($col['Type']);

// Zaten VARCHAR ise atla
if (strpos($colType, 'varchar') === false) {
    $pdo->exec("ALTER TABLE tasks MODIFY COLUMN `type` VARCHAR(30) NOT NULL DEFAULT 'OTHER'");

// Dogrula
$colAfter = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
}

 
}