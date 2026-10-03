<?php
/**
 * SQL Converter Tool
 *
 * phpMyAdmin dump formatindaki eski SQL dosyalarini
 * temiz, guncel ve import edilebilir SQL migration dosyasina cevirir.
 *
 * Kullanim:
 *   php sql-converter.php <input.sql> [output.sql]
 *
 * Ornek:
 *   php sql-converter.php ../sql/old.sql ../sql/converted.sql
 *   php sql-converter.php ../sql/old.sql  (stdout'a yazar)
 */

if (php_sapi_name() !== 'cli') {
    die("Bu script sadece CLI'dan calistirilabilir.\n");
}

if ($argc < 2) {
    echo "Kullanim: php sql-converter.php <input.sql> [output.sql]\n";
    echo "  input.sql  : phpMyAdmin dump dosyasi\n";
    echo "  output.sql : Cikti dosyasi (opsiyonel, verilmezse stdout)\n";
    exit(1);
}

$inputFile = $argv[1];
$outputFile = $argv[2] ?? null;

if (!file_exists($inputFile)) {
    die("Hata: '$inputFile' dosyasi bulunamadi.\n");
}

$fileSize = filesize($inputFile);
$fileSizeMB = round($fileSize / 1024 / 1024, 2);
fwrite(STDERR, "Dosya okunuyor: $inputFile ({$fileSizeMB} MB)\n");

$content = file_get_contents($inputFile);
if ($content === false) {
    die("Hata: Dosya okunamadi.\n");
}

fwrite(STDERR, "SQL ayristiriliyor (parse)...\n");

// ============================================================
// 1) CREATE TABLE ifadelerini ayikla
// ============================================================
$tables = [];
$tableOrder = [];

preg_match_all('/CREATE TABLE [`"]?(\w+)[`"]?\s*\((.*?)\)\s*ENGINE=(\w+)[^;]*;/s', $content, $createMatches, PREG_SET_ORDER);

foreach ($createMatches as $m) {
    $tableName = $m[1];
    $columnsBlock = $m[2];
    $engine = $m[3];

    $tables[$tableName] = [
        'name' => $tableName,
        'columns' => trim($columnsBlock),
        'engine' => $engine,
        'indexes' => [],
        'primaryKey' => null,
        'uniqueKeys' => [],
        'foreignKeys' => [],
        'autoIncrement' => null,
        'inserts' => [],
    ];
    $tableOrder[] = $tableName;
}

fwrite(STDERR, "  " . count($tables) . " tablo bulundu\n");

// ============================================================
// 2) ALTER TABLE ... ADD PRIMARY KEY / INDEX / UNIQUE KEY
// ============================================================
preg_match_all('/ALTER TABLE [`"]?(\w+)[`"]?\s*((?:\s*ADD\s+(?:PRIMARY KEY|UNIQUE KEY|KEY|INDEX|CONSTRAINT)[^;]*[,;]?\s*)+);/si', $content, $alterMatches, PREG_SET_ORDER);

foreach ($alterMatches as $m) {
    $tableName = $m[1];
    $body = $m[0];

    if (!isset($tables[$tableName])) continue;

    // PRIMARY KEY
    if (preg_match('/ADD PRIMARY KEY\s*\(([^)]+)\)/i', $body, $pk)) {
        $tables[$tableName]['primaryKey'] = trim($pk[1]);
    }

    // UNIQUE KEY
    preg_match_all('/ADD UNIQUE KEY [`"]?(\w+)[`"]?\s*\(/i', $body, $ukStarts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($ukStarts as $ukStart) {
        $cols = extractBalancedParens($body, $ukStart[0][1] + strlen($ukStart[0][0]) - 1);
        $tables[$tableName]['uniqueKeys'][] = [
            'name' => $ukStart[1][0],
            'columns' => $cols,
        ];
    }

    // Regular KEY/INDEX (handles prefix length like `col`(768))
    preg_match_all('/ADD (?:KEY|INDEX) [`"]?(\w+)[`"]?\s*\(/i', $body, $idxStarts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($idxStarts as $idxStart) {
        $cols = extractBalancedParens($body, $idxStart[0][1] + strlen($idxStart[0][0]) - 1);
        $tables[$tableName]['indexes'][] = [
            'name' => $idxStart[1][0],
            'columns' => $cols,
        ];
    }

    // CONSTRAINT (Foreign Keys)
    preg_match_all('/ADD CONSTRAINT [`"]?(\w+)[`"]?\s+FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)([^,;]*)/i', $body, $fks, PREG_SET_ORDER);
    foreach ($fks as $fk) {
        $tables[$tableName]['foreignKeys'][] = [
            'name' => $fk[1],
            'column' => trim($fk[2]),
            'refTable' => $fk[3],
            'refColumn' => trim($fk[4]),
            'actions' => trim($fk[5]),
        ];
    }
}

// ============================================================
// 3) ALTER TABLE ... MODIFY ... AUTO_INCREMENT
// ============================================================
preg_match_all('/ALTER TABLE [`"]?(\w+)[`"]?\s+MODIFY [`"]?(\w+)[`"]?\s+([^,;]+)AUTO_INCREMENT[^,;]*/i', $content, $aiMatches, PREG_SET_ORDER);

foreach ($aiMatches as $m) {
    $tableName = $m[1];
    $colName = $m[2];
    if (isset($tables[$tableName])) {
        $tables[$tableName]['autoIncrement'] = $colName;
    }
}

// ============================================================
// 4) INSERT ifadelerini ayikla
// ============================================================
// Buyuk INSERT'leri satir satir islemek icin regex
$offset = 0;
while (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+[`"]?(\w+)[`"]?\s*\([^)]+\)\s*VALUES/i', $content, $insMatch, PREG_OFFSET_CAPTURE, $offset)) {
    $tableName = $insMatch[1][0];
    $startPos = $insMatch[0][1];

    // INSERT ifadesinin sonunu bul (;)
    $endPos = findStatementEnd($content, $startPos);
    $insertStmt = substr($content, $startPos, $endPos - $startPos + 1);

    if (isset($tables[$tableName])) {
        $tables[$tableName]['inserts'][] = $insertStmt;
    }

    $offset = $endPos + 1;
}

$insertCount = 0;
foreach ($tables as $t) {
    $insertCount += count($t['inserts']);
}
fwrite(STDERR, "  $insertCount INSERT ifadesi bulundu\n");

// ============================================================
// 5) Temiz SQL olustur
// ============================================================
fwrite(STDERR, "Temiz SQL olusturuluyor...\n");

$output = [];
$output[] = "-- =============================================";
$output[] = "-- Sigorta CRM - Converted Migration";
$output[] = "-- Olusturulma: " . date('Y-m-d H:i:s');
$output[] = "-- Kaynak: " . basename($inputFile);
$output[] = "-- =============================================";
$output[] = "";
$output[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";
$output[] = "SET NAMES utf8mb4;";
$output[] = "SET FOREIGN_KEY_CHECKS = 0;";
$output[] = "";

// Tablo olusturma sirasi (foreign key bağimliliklarina gore)
$sortedTables = sortTablesByDependency($tables, $tableOrder);

foreach ($sortedTables as $tableName) {
    $table = $tables[$tableName];

    $output[] = "-- --------------------------------------------------------";
    $output[] = "-- Tablo: `$tableName`";
    $output[] = "-- --------------------------------------------------------";
    $output[] = "";
    $output[] = "DROP TABLE IF EXISTS `$tableName`;";
    $output[] = "";

    // CREATE TABLE
    $createLines = buildCreateTable($table);
    $output[] = $createLines;
    $output[] = "";

    // INSERT
    foreach ($table['inserts'] as $insert) {
        $output[] = $insert;
        $output[] = "";
    }
}

// Foreign key constraints (sonra ekle)
$hasForeignKeys = false;
foreach ($sortedTables as $tableName) {
    $table = $tables[$tableName];
    if (!empty($table['foreignKeys'])) {
        if (!$hasForeignKeys) {
            $output[] = "-- --------------------------------------------------------";
            $output[] = "-- Foreign Key Constraints";
            $output[] = "-- --------------------------------------------------------";
            $output[] = "";
            $hasForeignKeys = true;
        }

        foreach ($table['foreignKeys'] as $fk) {
            $actions = trim($fk['actions']);
            $output[] = "ALTER TABLE `$tableName` ADD CONSTRAINT `{$fk['name']}` FOREIGN KEY ({$fk['column']}) REFERENCES `{$fk['refTable']}` ({$fk['refColumn']})" . ($actions ? " $actions" : "") . ";";
        }
        $output[] = "";
    }
}

$output[] = "SET FOREIGN_KEY_CHECKS = 1;";
$output[] = "";

$result = implode("\n", $output);

// ============================================================
// 6) Ciktiya yaz
// ============================================================
if ($outputFile) {
    file_put_contents($outputFile, $result);
    $outSize = round(strlen($result) / 1024, 1);
    fwrite(STDERR, "\nBasarili! Cikti: $outputFile ({$outSize} KB)\n");
    fwrite(STDERR, "Tablo sayisi: " . count($tables) . "\n");

    // Tablo ozeti
    foreach ($sortedTables as $tn) {
        $t = $tables[$tn];
        $ic = count($t['inserts']);
        $fkc = count($t['foreignKeys']);
        $insertInfo = $ic > 0 ? ", $ic INSERT" : "";
        $fkInfo = $fkc > 0 ? ", $fkc FK" : "";
        fwrite(STDERR, "  - $tn" . $insertInfo . $fkInfo . "\n");
    }
} else {
    echo $result;
}

// ============================================================
// YARDIMCI FONKSIYONLAR
// ============================================================

/**
 * Balanced parentheses ile icerideki string'i cikarir.
 * $pos, acilan '(' karakterinin pozisyonunu gostermelidir.
 * Dondurulen deger parantez ici icerik (dis parantezler haric).
 */
function extractBalancedParens(string $str, int $pos): string {
    $depth = 0;
    $start = $pos;
    $len = strlen($str);
    for ($i = $pos; $i < $len; $i++) {
        if ($str[$i] === '(') {
            $depth++;
        } elseif ($str[$i] === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($str, $start + 1, $i - $start - 1);
            }
        }
    }
    // Fallback: parantez kapanmadiysa en iyi tahmini don
    return substr($str, $start + 1);
}

function findStatementEnd(string $content, int $startPos): int {
    $len = strlen($content);
    $inString = false;
    $stringChar = '';
    $escaped = false;

    for ($i = $startPos; $i < $len; $i++) {
        $ch = $content[$i];

        if ($escaped) {
            $escaped = false;
            continue;
        }

        if ($ch === '\\') {
            $escaped = true;
            continue;
        }

        if ($inString) {
            if ($ch === $stringChar) {
                // Check for doubled quote escape ('')
                if ($i + 1 < $len && $content[$i + 1] === $stringChar) {
                    $i++;
                    continue;
                }
                $inString = false;
            }
            continue;
        }

        if ($ch === '\'' || $ch === '"') {
            $inString = true;
            $stringChar = $ch;
            continue;
        }

        if ($ch === ';') {
            return $i;
        }
    }

    return $len - 1;
}

function buildCreateTable(array $table): string {
    $lines = [];
    $lines[] = "CREATE TABLE IF NOT EXISTS `{$table['name']}` (";

    // Kolonlari isle
    $columnLines = parseColumns($table['columns']);
    $additions = [];

    // AUTO_INCREMENT ekle
    if ($table['autoIncrement']) {
        $aiCol = $table['autoIncrement'];
        foreach ($columnLines as &$colLine) {
            // Kolon satirinda auto_increment kolonu varsa NOT NULL'dan sonra AUTO_INCREMENT ekle
            if (preg_match('/^\s*[`"]?' . preg_quote($aiCol, '/') . '[`"]?\s/i', $colLine)) {
                // Zaten AUTO_INCREMENT varsa ekleme
                if (stripos($colLine, 'AUTO_INCREMENT') === false) {
                    $colLine = preg_replace('/(NOT NULL)/i', '$1 AUTO_INCREMENT', $colLine);
                }
            }
        }
        unset($colLine);
    }

    // PRIMARY KEY
    if ($table['primaryKey']) {
        $additions[] = "  PRIMARY KEY ({$table['primaryKey']})";
    }

    // UNIQUE KEYS
    foreach ($table['uniqueKeys'] as $uk) {
        $additions[] = "  UNIQUE KEY `{$uk['name']}` ({$uk['columns']})";
    }

    // INDEXES
    foreach ($table['indexes'] as $idx) {
        // Unique key ile ayni kolonu gosteriyorsa atla
        $skip = false;
        foreach ($table['uniqueKeys'] as $uk) {
            if ($uk['columns'] === $idx['columns']) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $additions[] = "  KEY `{$idx['name']}` ({$idx['columns']})";
        }
    }

    // Butun satirlari birlestir
    $allLines = [];
    foreach ($columnLines as $cl) {
        $allLines[] = '  ' . trim($cl);
    }
    foreach ($additions as $a) {
        $allLines[] = $a;
    }

    // Son satirdan virgulu kaldir, digerlerine ekle
    for ($i = 0; $i < count($allLines) - 1; $i++) {
        $allLines[$i] = rtrim($allLines[$i], ',') . ',';
    }
    $allLines[count($allLines) - 1] = rtrim($allLines[count($allLines) - 1], ',');

    $lines[] = implode("\n", $allLines);

    $charset = "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $lines[] = ") ENGINE={$table['engine']} $charset;";

    return implode("\n", $lines);
}

function parseColumns(string $columnsBlock): array {
    $lines = [];
    $current = '';
    $depth = 0;
    $inString = false;
    $stringChar = '';
    $escaped = false;

    for ($i = 0; $i < strlen($columnsBlock); $i++) {
        $ch = $columnsBlock[$i];

        if ($escaped) {
            $current .= $ch;
            $escaped = false;
            continue;
        }

        if ($ch === '\\') {
            $current .= $ch;
            $escaped = true;
            continue;
        }

        if ($inString) {
            $current .= $ch;
            if ($ch === $stringChar) {
                $inString = false;
            }
            continue;
        }

        if ($ch === '\'' || $ch === '"') {
            $current .= $ch;
            $inString = true;
            $stringChar = $ch;
            continue;
        }

        if ($ch === '(') {
            $depth++;
            $current .= $ch;
            continue;
        }

        if ($ch === ')') {
            $depth--;
            $current .= $ch;
            continue;
        }

        if ($ch === ',' && $depth === 0) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                // Inline PRIMARY KEY / KEY / INDEX satirlarini atla (ALTER TABLE'dan gelecek)
                if (!preg_match('/^(PRIMARY KEY|KEY|INDEX|UNIQUE KEY|CONSTRAINT)\s/i', $trimmed)) {
                    $lines[] = cleanColumnDef($trimmed);
                }
            }
            $current = '';
            continue;
        }

        if ($ch === "\n") {
            $current .= ' ';
            continue;
        }

        $current .= $ch;
    }

    $trimmed = trim($current);
    if ($trimmed !== '' && !preg_match('/^(PRIMARY KEY|KEY|INDEX|UNIQUE KEY|CONSTRAINT)\s/i', $trimmed)) {
        $lines[] = cleanColumnDef($trimmed);
    }

    return $lines;
}

function cleanColumnDef(string $def): string {
    // CHARACTER SET ... COLLATE ... ifadesini kaldir (tablo seviyesinde tanimlayacagiz)
    $def = preg_replace('/\s*CHARACTER SET\s+\S+\s+COLLATE\s+\S+/i', '', $def);
    // Gereksiz bosluklar
    $def = preg_replace('/\s+/', ' ', $def);
    return trim($def);
}

function sortTablesByDependency(array $tables, array $tableOrder): array {
    $sorted = [];
    $visited = [];

    foreach ($tableOrder as $name) {
        visitTable($name, $tables, $sorted, $visited);
    }

    return $sorted;
}

function visitTable(string $name, array $tables, array &$sorted, array &$visited): void {
    if (isset($visited[$name])) return;
    $visited[$name] = true;

    if (isset($tables[$name])) {
        // Once bagimliliklari isle
        foreach ($tables[$name]['foreignKeys'] as $fk) {
            if ($fk['refTable'] !== $name && isset($tables[$fk['refTable']])) {
                visitTable($fk['refTable'], $tables, $sorted, $visited);
            }
        }
    }

    $sorted[] = $name;
}
