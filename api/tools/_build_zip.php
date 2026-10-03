<?php
/**
 * build.zip oluştur — GitHub Release / cPanel deploy için
 * .output/public/ içindeki dosyalar kök dizine yazılır (sunucuda .output'a gerek kalmaz)
 * Kullanım: php _build_zip.php
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$root = realpath(__DIR__ . '/../../');
$zipPath = $root . '/../build.zip';
$outputPublic = $root . '/.output/public';

$skip = ['node_modules', '.nuxt', '.output', 'dist', '.git', 'backups', 'scripts',
         'api/config.php', 'api/.storage_key', 'api/composer.phar', 'api/uploads', 'api/error_log',
         'son.zip', '.claude'];

$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$count = 0;

// 1) .output/public/ içindeki dosyaları KÖK DİZİNE yaz
if (is_dir($outputPublic)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputPublic, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($outputPublic) + 1));
            $zip->addFile($file->getPathname(), $rel);
            $count++;
        }
    }
    echo "Frontend build: " . $count . " dosya eklendi\n";
}

// 2) Diğer dosyalar (api, nuxt.config vb.) — .output hariç
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
$apiCount = 0;
foreach ($it2 as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $shouldSkip = false;
    foreach ($skip as $s) {
        if ($rel === $s || strpos($rel, $s . '/') === 0) { $shouldSkip = true; break; }
    }
    if ($shouldSkip) continue;
    if ($file->isFile()) {
        $zip->addFile($file->getPathname(), $rel);
        $apiCount++;
    }
}
echo "Backend + kaynak: " . $apiCount . " dosya eklendi\n";

$count += $apiCount;
$zip->close();

$size = round(filesize($zipPath) / 1024 / 1024, 1);
echo "build.zip oluşturuldu: {$count} dosya, {$size} MB\n";
echo "Konum: {$zipPath}\n";
