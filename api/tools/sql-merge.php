<?php
/**
 * SQL Merge Tool
 *
 * Converted (eski veri) SQL ile migrations (yeni tablolar) SQL'i birlestirir.
 * Ayni isimli tablolarda eski veri onceliklidir.
 *
 * Kullanim:
 *   php sql-merge.php <converted.sql> <migrations.sql> <output.sql>
 */

if (php_sapi_name() !== 'cli') {
    die("Bu script sadece CLI'dan calistirilabilir.\n");
}

if ($argc < 4) {
    echo "Kullanim: php sql-merge.php <converted.sql> <migrations.sql> <output.sql>\n";
    exit(1);
}

$convertedFile = $argv[1];
$migrationsFile = $argv[2];
$outputFile = $argv[3];

if (!file_exists($convertedFile)) die("Hata: '$convertedFile' bulunamadi.\n");
if (!file_exists($migrationsFile)) die("Hata: '$migrationsFile' bulunamadi.\n");

$converted = file_get_contents($convertedFile);
$migrations = file_get_contents($migrationsFile);

// converted.sql'deki tablo isimlerini bul
preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?[`"]?(\w+)[`"]?/i', $converted, $convTables);
$existingTables = array_map('strtolower', $convTables[1]);

fwrite(STDERR, "Mevcut tablolar (converted): " . implode(', ', $existingTables) . "\n");

// migrations.sql'den sadece YENi tablolari ve INSERT'leri al
$blocks = preg_split('/(?=--\s.*tablosu|--\s=+\s*\n--\s*Varsay)/', $migrations);

$newTableSql = [];
$newInsertSql = [];

foreach ($blocks as $block) {
    $block = trim($block);
    if (empty($block)) continue;

    // CREATE TABLE blogu mu?
    if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?[`"]?(\w+)[`"]?/i', $block, $m)) {
        $tableName = strtolower($m[1]);
        if (!in_array($tableName, $existingTables)) {
            fwrite(STDERR, "  + Yeni tablo ekleniyor: $tableName\n");
            $newTableSql[] = $block;
        } else {
            fwrite(STDERR, "  - Atlanıyor (zaten var): $tableName\n");
        }
    }

    // Varsayilan veriler blogu mu? (yeni tablolar icin INSERT'ler)
    if (preg_match('/Varsay/', $block) && preg_match_all('/INSERT IGNORE INTO [`"]?(\w+)[`"]?/i', $block, $inserts)) {
        foreach ($inserts[0] as $idx => $fullMatch) {
            $insertTable = strtolower($inserts[1][$idx]);
            // Sadece yeni tablolara ait INSERT'leri al
            // options ve country zaten eski veride var, onlari atla
            if (!in_array($insertTable, $existingTables)) {
                // Bu INSERT'in tamamini bul
                $pos = strpos($block, $fullMatch);
                $end = strpos($block, ';', $pos);
                if ($end !== false) {
                    $stmt = substr($block, $pos, $end - $pos + 1);
                    $newInsertSql[] = "-- Varsayilan veri: $insertTable\n$stmt";
                    fwrite(STDERR, "  + Varsayilan veri ekleniyor: $insertTable\n");
                }
            }
        }
    }
}

// Birlestirilmis dosyayi olustur
// converted.sql'in sonundaki SET FOREIGN_KEY_CHECKS = 1; satirini bul ve oncesine ekle
$insertPoint = strrpos($converted, 'SET FOREIGN_KEY_CHECKS = 1;');

if ($insertPoint !== false) {
    $before = substr($converted, 0, $insertPoint);
    $after = substr($converted, $insertPoint);
} else {
    $before = $converted;
    $after = '';
}

$merged = $before;

if (!empty($newTableSql) || !empty($newInsertSql)) {
    $merged .= "\n-- ========================================================\n";
    $merged .= "-- Ek Tablolar (Yeni Ozellikler)\n";
    $merged .= "-- ========================================================\n\n";

    foreach ($newTableSql as $sql) {
        $merged .= $sql . "\n\n";
    }

    if (!empty($newInsertSql)) {
        $merged .= "-- --------------------------------------------------------\n";
        $merged .= "-- Varsayilan Veriler (Yeni Tablolar)\n";
        $merged .= "-- --------------------------------------------------------\n\n";
        foreach ($newInsertSql as $sql) {
            $merged .= $sql . "\n\n";
        }
    }
}

$merged .= $after;

file_put_contents($outputFile, $merged);

$outSize = round(strlen($merged) / 1024, 1);
fwrite(STDERR, "\nBirlestirme tamamlandi: $outputFile ({$outSize} KB)\n");
