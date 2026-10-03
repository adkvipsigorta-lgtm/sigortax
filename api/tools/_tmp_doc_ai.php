<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/Database.php';

$pdo = Database::getInstance();

// documents tablosu AUTO_INCREMENT kontrolü
$col = $pdo->query("SHOW COLUMNS FROM documents WHERE Field = 'id'")->fetch();
echo "documents.id: Type={$col['Type']} Extra={$col['Extra']}\n";

$ai = Database::fetch("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'crm' AND TABLE_NAME = 'documents'");
echo "AUTO_INCREMENT: " . ($ai['AUTO_INCREMENT'] ?? 'NULL') . "\n";

// id=0 doküman
$d = Database::fetch("SELECT id, name, policy_id FROM documents WHERE id = 0");
echo "id=0 doküman: " . ($d ? $d['name'] : 'YOK') . "\n";

// TÜM tabloları kontrol et
$tables = $pdo->query("
    SELECT TABLE_NAME, AUTO_INCREMENT 
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = 'crm' AND AUTO_INCREMENT IS NULL AND TABLE_TYPE = 'BASE TABLE'
")->fetchAll(PDO::FETCH_ASSOC);
echo "\nAUTO_INCREMENT eksik tablolar:\n";
foreach ($tables as $t) {
    echo "  - {$t['TABLE_NAME']}\n";
}
