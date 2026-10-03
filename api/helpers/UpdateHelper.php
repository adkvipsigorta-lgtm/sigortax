<?php

require_once __DIR__ . '/../config.php';

/**
 * Uzaktan guncelleme islemleri.
 *
 * Terminoloji:
 *   - ROOT: api/ klasorunun UST klasoru (projenin kok dizini)
 *   - PROTECTED: asla uzerine yazilmayan / silinmeyen yollar
 *   - MANIFEST: uzak sunucudaki versions.json
 */
class UpdateHelper
{
    // Uzerine yazilmasi yasak yollar (ROOT'a gore)
    private const PROTECTED_PATHS = [
        'api/config.php',
        'api/uploads',
        'api/VERSION.json',  // versiyon dosyasi sadece controller tarafindan yazilir
        'backups',           // kendi backup klasorumuzu silmeyelim
        'node_modules',
        '.output',
        '.git',
        '.env',
    ];

    public static function rootDir(): string
    {
        return realpath(__DIR__ . '/..' . '/..');
    }

    public static function backupsDir(): string
    {
        return self::rootDir() . DIRECTORY_SEPARATOR . 'backups';
    }

    public static function versionFile(): string
    {
        return self::rootDir() . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'VERSION.json';
    }

    public static function currentVersion(): string
    {
        $f = self::versionFile();
        if (!is_file($f)) return '0.0.0';
        $data = json_decode(file_get_contents($f), true);
        return $data['version'] ?? '0.0.0';
    }

    public static function writeVersion(string $version): void
    {
        file_put_contents(self::versionFile(), json_encode([
            'version' => $version,
            'installed_at' => date('c'),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Semver karsilastirma: a > b ise >0, esitse 0, kucukse <0
     */
    public static function compareVersion(string $a, string $b): int
    {
        return version_compare($a, $b);
    }

    /**
     * GitHub Releases API'sinden release listesini cek ve normalize et.
     * Cikti format: ['latest' => '1.2.0', 'releases' => [{version, zip_url, released_at, changelog, sha256}, ...]]
     */
    public static function fetchManifest(): array
    {
        if (!defined('UPDATE_GITHUB_REPO') || UPDATE_GITHUB_REPO === 'OWNER/REPO') {
            throw new RuntimeException('Güncelleme yapılandırılmamış (UPDATE_GITHUB_REPO).');
        }

        $url = 'https://api.github.com/repos/' . UPDATE_GITHUB_REPO . '/releases?per_page=20';
        $headers = [
            'User-Agent: SigortaApp-Updater/1.0',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if (defined('UPDATE_GITHUB_TOKEN') && UPDATE_GITHUB_TOKEN) {
            $headers[] = 'Authorization: Bearer ' . UPDATE_GITHUB_TOKEN;
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('GitHub API\'sine ulaşılamadı.');
        }
        $releases = json_decode($raw, true);
        if (!is_array($releases)) {
            throw new RuntimeException('GitHub yanıtı geçersiz.');
        }
        if (isset($releases['message'])) {
            throw new RuntimeException('GitHub hatası: ' . $releases['message']);
        }

        $assetName = defined('UPDATE_GITHUB_ASSET') ? UPDATE_GITHUB_ASSET : 'build.zip';
        $normalized = ['releases' => []];
        foreach ($releases as $r) {
            if (!empty($r['draft'])) continue;
            if (!empty($r['prerelease'])) continue; // prerelease'leri atla
            $tag = $r['tag_name'] ?? '';
            if ($tag === '') continue;
            $version = ltrim($tag, 'vV');

            // Asset'i bul: once tam isimle, bulamazsa ilk .zip ile biten asset
            $asset = null;
            foreach ($r['assets'] ?? [] as $a) {
                if (($a['name'] ?? '') === $assetName) { $asset = $a; break; }
            }
            if (!$asset) {
                foreach ($r['assets'] ?? [] as $a) {
                    if (str_ends_with(strtolower($a['name'] ?? ''), '.zip')) { $asset = $a; break; }
                }
            }
            if (!$asset) continue; // hic ZIP yok

            // GitHub 2023+ digest alani (sha256:xxx)
            $sha = null;
            if (!empty($asset['digest']) && str_starts_with($asset['digest'], 'sha256:')) {
                $sha = substr($asset['digest'], 7);
            }

            $normalized['releases'][] = [
                'version' => $version,
                'tag' => $tag,
                'title' => trim($r['name'] ?? '') ?: $tag, // release basligi (bos ise tag)
                'zip_url' => $asset['browser_download_url'] ?? '',
                'asset_api_url' => $asset['url'] ?? '', // private repo icin bu gerekir
                'size' => (int) ($asset['size'] ?? 0),
                'released_at' => $r['published_at'] ?? null,
                'changelog' => $r['body'] ?? '',
                'sha256' => $sha,
            ];
        }

        if (!empty($normalized['releases'])) {
            usort($normalized['releases'], fn($a, $b) => version_compare($b['version'], $a['version']));
            $normalized['latest'] = $normalized['releases'][0]['version'];
        }
        return $normalized;
    }

    /**
     * Bir release bilgisi bul (version matching).
     */
    public static function findRelease(array $manifest, string $version): ?array
    {
        foreach ($manifest['releases'] ?? [] as $r) {
            if (($r['version'] ?? '') === $version) return $r;
        }
        return null;
    }

    /**
     * ZIP dosyasini indir ve SHA256 dogrula.
     * Donen: temp file path.
     *
     * GitHub private repo ise $apiUrl (assets endpoint'i) + token ile inilir.
     */
    public static function downloadZip(string $url, ?string $expectedSha256 = null, ?string $apiUrl = null): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upd_') . '.zip';

        // Private repo mi? token varsa asset'in API URL'sine Accept:octet-stream ile git
        $useApi = $apiUrl && defined('UPDATE_GITHUB_TOKEN') && UPDATE_GITHUB_TOKEN;
        $downloadUrl = $useApi ? $apiUrl : $url;

        $headers = ['User-Agent: SigortaApp-Updater/1.0'];
        if ($useApi) {
            $headers[] = 'Accept: application/octet-stream';
            $headers[] = 'Authorization: Bearer ' . UPDATE_GITHUB_TOKEN;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'follow_location' => 1,
                'timeout' => 120,
            ],
        ]);

        $src = @fopen($downloadUrl, 'rb', false, $ctx);
        if (!$src) throw new RuntimeException('ZIP dosyasi indirilemedi: ' . $downloadUrl);
        $dst = @fopen($tmp, 'wb');
        if (!$dst) {
            fclose($src);
            throw new RuntimeException('Gecici dosya olusturulamadi.');
        }
        $bytes = 0;
        while (!feof($src)) {
            $chunk = fread($src, 8192);
            if ($chunk === false) break;
            fwrite($dst, $chunk);
            $bytes += strlen($chunk);
            if ($bytes > 500 * 1024 * 1024) { // 500 MB guvenlik limiti
                fclose($src); fclose($dst); @unlink($tmp);
                throw new RuntimeException('ZIP dosyasi cok buyuk (>500MB).');
            }
        }
        fclose($src);
        fclose($dst);
        if ($bytes < 1024) {
            @unlink($tmp);
            throw new RuntimeException('ZIP cok kucuk / bos.');
        }
        if ($expectedSha256) {
            $actual = hash_file('sha256', $tmp);
            if (!hash_equals(strtolower($expectedSha256), strtolower($actual))) {
                @unlink($tmp);
                throw new RuntimeException('ZIP bütünlük kontrolü başarısız (SHA256 eslesmiyor).');
            }
        }
        return $tmp;
    }

    /**
     * Yol korunuyor mu? (protected list icinde veya path traversal riski var mi)
     */
    public static function isProtected(string $relPath): bool
    {
        $relPath = str_replace('\\', '/', $relPath);
        $relPath = ltrim($relPath, '/');
        if ($relPath === '' || $relPath === '.') return true;
        // Path traversal guvenligi
        if (str_contains($relPath, '..')) return true;
        foreach (self::PROTECTED_PATHS as $p) {
            if ($relPath === $p) return true;
            if (str_starts_with($relPath, $p . '/')) return true;
        }
        return false;
    }

    /**
     * Guncellemeden ONCE backup al. ZIP icinde degisecek olan dosyalarin
     * mevcut kopyasi + api/VERSION.json.
     *
     * Return: backup dizin yolu
     */
    public static function createBackup(array $zipEntries): string
    {
        $root = self::rootDir();
        $backupDir = self::backupsDir() . DIRECTORY_SEPARATOR . date('Y-m-d_His');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backedUp = [self::versionFile()]; // version dosyasi her zaman backup
        $copyFile = function ($src, $relPath) use ($backupDir) {
            $dst = $backupDir . DIRECTORY_SEPARATOR . $relPath;
            $dstDir = dirname($dst);
            if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
            @copy($src, $dst);
        };

        // VERSION dosyasini backup
        if (is_file(self::versionFile())) {
            $copyFile(self::versionFile(), 'api/VERSION.json');
        }

        // ZIP icindeki her entry icin mevcut karsiligi backup al
        foreach ($zipEntries as $rel) {
            if (self::isProtected($rel)) continue;
            $current = $root . DIRECTORY_SEPARATOR . $rel;
            if (is_file($current)) {
                $copyFile($current, $rel);
            }
        }

        self::pruneBackups();
        return $backupDir;
    }

    /**
     * Eski backup'lari temizle (sadece en son UPDATE_BACKUP_KEEP tane kalsin)
     */
    public static function pruneBackups(): void
    {
        $dir = self::backupsDir();
        if (!is_dir($dir)) return;
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        $dirs = [];
        foreach ($items as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path)) $dirs[] = ['name' => $name, 'path' => $path, 'mtime' => filemtime($path)];
        }
        usort($dirs, fn($a, $b) => $b['mtime'] - $a['mtime']);
        $keep = defined('UPDATE_BACKUP_KEEP') ? UPDATE_BACKUP_KEEP : 3;
        $toDelete = array_slice($dirs, $keep);
        foreach ($toDelete as $d) {
            self::deleteDir($d['path']);
        }
    }

    public static function listBackups(): array
    {
        $dir = self::backupsDir();
        if (!is_dir($dir)) return [];
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        $out = [];
        foreach ($items as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($path)) continue;
            $verFile = $path . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'VERSION.json';
            $version = '?';
            if (is_file($verFile)) {
                $vd = json_decode(file_get_contents($verFile), true);
                $version = $vd['version'] ?? '?';
            }
            $out[] = [
                'name' => $name,
                'version' => $version,
                'created_at' => date('c', filemtime($path)),
                'size_bytes' => self::dirSize($path),
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /**
     * ZIP icindeki dosya listesini don (header on-read; extract'tan once).
     */
    public static function listZipEntries(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP acilamadi.');
        }
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;
            // Klasor ise atla
            if (str_ends_with($name, '/')) continue;
            $entries[] = str_replace('\\', '/', $name);
        }
        $zip->close();
        return $entries;
    }

    /**
     * ZIP'i ROOT dizinine extract et. Korunan yollari atla.
     * Donen: extract edilen dosya sayisi.
     */
    public static function extractZip(string $zipPath): int
    {
        $root = self::rootDir();
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP acilamadi.');
        }

        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;
            $rel = str_replace('\\', '/', $name);
            if (str_ends_with($rel, '/')) continue; // klasor

            if (self::isProtected($rel)) continue;

            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            // Path traversal son koruma
            $targetReal = realpath(dirname($target));
            if ($targetReal !== false && !str_starts_with($targetReal, $root)) {
                continue; // root disinda
            }

            $dir = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $stream = $zip->getStream($name);
            if (!$stream) continue;
            $out = fopen($target, 'wb');
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) break;
                fwrite($out, $chunk);
            }
            fclose($stream);
            fclose($out);
            $count++;
        }
        $zip->close();
        return $count;
    }

    /**
     * Migration calistirici.
     * Sadece api/sql/UPDATES/*.php dosyalarini destekler.
     * Her dosya SchemaHelper kullanir, idempotenttir.
     * Basariyla uygulanan dosyalar `migrations` tablosuna kaydedilir;
     * sonraki calistirmalarda atlanir. Dosyalar silinmez.
     */
    public static function runMigrations(): array
    {
        $root = self::rootDir();
        $executed = [];

        require_once $root . '/api/helpers/Database.php';
        require_once $root . '/api/helpers/SchemaHelper.php';
        $pdo = Database::getInstance();

        // 1) migrations tablosunu garantile (yoksa olustur)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `migrations` (
                `filename` VARCHAR(255) NOT NULL PRIMARY KEY,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 2) Daha once uygulanmis migration'lari oku
        $appliedRows = $pdo->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_flip($appliedRows ?: []);

        // 3) UPDATES/ klasorundeki .php migration dosyalari (alfabetik sira)
        $updatesDir = $root . '/api/sql/UPDATES';
        $items = is_dir($updatesDir) ? (glob($updatesDir . '/*.php') ?: []) : [];
        sort($items);

        foreach ($items as $file) {
            $filename = basename($file);
            if (isset($applied[$filename])) continue;

            try {
                // PHP migration: SchemaHelper kullanarak idempotent islem yapar
                require $file;

                $ins = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
                $ins->execute([$filename]);
                $executed[] = $filename;
            } catch (\Throwable $e) {
                throw new RuntimeException('Migration hatası: ' . $filename . ' — ' . $e->getMessage());
            }
        }

        return $executed;
    }

    /**
     * Backup'tan geri al (rollback).
     */
    public static function restoreBackup(string $backupName): int
    {
        $root = self::rootDir();
        $backupDir = self::backupsDir() . DIRECTORY_SEPARATOR . basename($backupName);
        if (!is_dir($backupDir)) {
            throw new RuntimeException('Backup bulunamadi: ' . $backupName);
        }

        $count = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $rel = substr($file->getPathname(), strlen($backupDir) + 1);
            $rel = str_replace('\\', '/', $rel);
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $dir = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            copy($file->getPathname(), $target);
            $count++;
        }
        return $count;
    }

    private static function deleteDir(string $path): void
    {
        if (!is_dir($path)) return;
        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $name) {
            $full = $path . DIRECTORY_SEPARATOR . $name;
            if (is_dir($full)) self::deleteDir($full);
            else @unlink($full);
        }
        @rmdir($path);
    }

    private static function dirSize(string $path): int
    {
        if (!is_dir($path)) return 0;
        $total = 0;
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile()) $total += $f->getSize();
        }
        return $total;
    }
}
