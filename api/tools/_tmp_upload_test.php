<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/DocumentCrypto.php';

// Test: Türkçe karakterli dosya adı ile sanitize
$testNames = [
    '34 ABC 707 TRAFİK 2521251254.pdf',
    '34 ABC 707 KASKO.pdf',
    'test.pdf',
    '34 HHN 184 TSS 0001071009221007.pdf',
];

foreach ($testNames as $name) {
    $sanitized = basename($name);
    $sanitized = preg_replace('/[^\w\s\-\.]/u', '_', $sanitized);
    $sanitized = substr($sanitized, 0, 200);
    echo "$name → $sanitized\n";
}

echo "\nDocumentCrypto test:\n";
echo "Storage key exists: " . (file_exists(__DIR__ . '/../.storage_key') ? 'YES' : 'NO') . "\n";
