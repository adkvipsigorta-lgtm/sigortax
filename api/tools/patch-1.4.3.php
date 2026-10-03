<?php
/**
 * Patch v1.4.3 — Sunucu güncelleme scripti
 *
 * Değişiklikler:
 * 1. PolicyController.php → checkDuplicate: expiresAt alanı eklendi (zeyil auto-fill)
 * 2. PolicyController.php → store: zeyilde negatif prim kabul
 *
 * Kullanım: php patch-1.4.3.php
 * NOT: Frontend değişiklikleri için pnpm build + upload gerekli
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$basePath = realpath(__DIR__ . '/..');
echo "=== Patch v1.4.3 ===\n";
echo "Base: $basePath\n\n";

// ─── 1. PolicyController.php ─────────────────────────────────────────────
$file = $basePath . '/controllers/PolicyController.php';
if (!file_exists($file)) {
    echo "[HATA] PolicyController.php bulunamadi: $file\n";
    exit(1);
}

$content = file_get_contents($file);
$original = $content;
$changes = 0;

// 1a. checkDuplicate: expiresAt ekle
$search1 = "'soldBy'           => \$latest['sold_by'] ? (int) \$latest['sold_by'] : null,\n                'maxEndorsementNo' => (int) \$latest['max_endorsement_no'],";
$replace1 = "'soldBy'           => \$latest['sold_by'] ? (int) \$latest['sold_by'] : null,\n                'expiresAt'        => \$latest['expires_at'] ?? null,\n                'maxEndorsementNo' => (int) \$latest['max_endorsement_no'],";

if (strpos($content, "'expiresAt'        => \$latest['expires_at']") !== false) {
    echo "[ATLA] checkDuplicate expiresAt zaten mevcut\n";
} elseif (strpos($content, $search1) !== false) {
    $content = str_replace($search1, $replace1, $content);
    echo "[OK] checkDuplicate: expiresAt eklendi\n";
    $changes++;
} else {
    echo "[UYARI] checkDuplicate: hedef blok bulunamadi\n";
}

// 1b. store: zeyilde negatif prim
$search2 = "'grossPremium' => 'required|numeric|min_value:0.01',\n            'netPremium'   => 'required|numeric|min_value:0',";
if (strpos($content, "\$isZeyil ? 'required|numeric' : 'required|numeric|min_value:0.01'") !== false) {
    echo "[ATLA] store negatif prim zaten mevcut\n";
} elseif (strpos($content, $search2) !== false) {
    $replace2 = "\$isZeyil ? 'required|numeric' : 'required|numeric|min_value:0.01',\n            'netPremium'   => \$isZeyil ? 'required|numeric' : 'required|numeric|min_value:0',";
    // isZeyil değişkeni store başında lazım
    $isZeyilLine = "\$isZeyil = isset(\$input['endorsementNo']) && (int) \$input['endorsementNo'] > 1;";
    if (strpos($content, $isZeyilLine) === false) {
        // isZeyil satırını ekle (store fonksiyon başı)
        $content = str_replace(
            "public function store(array \$user, array \$input): void\n    {\n        Permission::require(\$user, 'policies.manage');",
            "public function store(array \$user, array \$input): void\n    {\n        Permission::require(\$user, 'policies.manage');\n        \$isZeyil = isset(\$input['endorsementNo']) && (int) \$input['endorsementNo'] > 1;",
            $content
        );
        echo "[OK] store: \$isZeyil degiskeni eklendi\n";
    }
    $content = str_replace($search2, "'grossPremium' => " . $replace2, $content);
    echo "[OK] store: negatif prim destegi eklendi\n";
    $changes++;
} else {
    echo "[UYARI] store: hedef blok bulunamadi (zaten guncel olabilir)\n";
}

// Kaydet
if ($changes > 0) {
    // Yedek al
    $backup = $file . '.bak-' . date('YmdHis');
    copy($file, $backup);
    echo "[YEDEK] $backup\n";

    file_put_contents($file, $content);
    echo "\n[BASARILI] PolicyController.php guncellendi ($changes degisiklik)\n";
} else {
    echo "\n[BILGI] PolicyController.php zaten guncel, degisiklik yok\n";
}

// ─── 2. VERSION.json ─────────────────────────────────────────────────────
$versionFile = $basePath . '/VERSION.json';
if (file_exists($versionFile)) {
    $vJson = json_decode(file_get_contents($versionFile), true);
    $vJson['version'] = '1.4.3';
    $vJson['installed_at'] = date('c');
    file_put_contents($versionFile, json_encode($vJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "[OK] VERSION.json -> 1.4.3\n";
}

echo "\n=== Patch tamamlandi ===\n";
echo "\nFRONTEND GUNCELLEME:\n";
echo "  Frontend degisiklikleri icin lokal makinede:\n";
echo "  1. pnpm build\n";
echo "  2. .output/public/ klasorunu sunucuya yukleyin\n";
