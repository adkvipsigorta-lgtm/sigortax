<?php

require_once __DIR__ . '/../helpers/UpdateHelper.php';

/**
 * Uzaktan guncelleme controller.
 *
 * Endpointler:
 *   GET  /api/updates/check    → manifest + current + updateAvailable
 *   POST /api/updates/apply    → download + backup + extract + migrate
 *   GET  /api/updates/history  → backup listesi
 *   POST /api/updates/rollback → belirtilen backup'a geri don
 */
class UpdateController
{
    public function check(array $user): void
    {
        AuthMiddleware::requireAdmin($user);

        try {
            $current = UpdateHelper::currentVersion();
            $manifest = UpdateHelper::fetchManifest();
            $latest = $manifest['latest'] ?? null;
            $release = $latest ? UpdateHelper::findRelease($manifest, $latest) : null;

            $updateAvailable = $latest
                ? UpdateHelper::compareVersion($latest, $current) > 0
                : false;

            Response::success([
                'current' => $current,
                'latest' => $latest,
                'updateAvailable' => $updateAvailable,
                'release' => $release, // {version, tag, zip_url, released_at, changelog, sha256}
                'githubRepo' => UPDATE_GITHUB_REPO,
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 502);
        }
    }

    public function apply(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        try {
            $manifest = UpdateHelper::fetchManifest();
            $targetVersion = $input['version'] ?? ($manifest['latest'] ?? null);
            if (!$targetVersion) {
                Response::error('Hedef sürüm belirtilmedi.', 400);
            }

            $release = UpdateHelper::findRelease($manifest, $targetVersion);
            if (!$release) {
                Response::error('Belirtilen sürüm manifest\'te bulunamadı.', 404);
            }

            $current = UpdateHelper::currentVersion();
            if (UpdateHelper::compareVersion($targetVersion, $current) <= 0 && empty($input['force'])) {
                Response::error('Zaten güncel sürüm yüklü (' . $current . '). Force güncelleme için force:true gönder.', 400);
            }

            $zipUrl = $release['zip_url'] ?? '';
            if (!$zipUrl) {
                Response::error('Release\'de zip_url yok.', 400);
            }

            // 1) ZIP indir + SHA256 dogrula (private repo icin asset_api_url gerekir)
            $zipFile = UpdateHelper::downloadZip(
                $zipUrl,
                $release['sha256'] ?? null,
                $release['asset_api_url'] ?? null
            );

            // 2) ZIP entry listesi
            $entries = UpdateHelper::listZipEntries($zipFile);
            if (empty($entries)) {
                @unlink($zipFile);
                Response::error('ZIP boş.', 400);
            }

            // 3) Backup
            $backupDir = UpdateHelper::createBackup($entries);

            // 4) Extract
            try {
                $extracted = UpdateHelper::extractZip($zipFile);
            } catch (\Throwable $e) {
                @unlink($zipFile);
                // rollback
                UpdateHelper::restoreBackup(basename($backupDir));
                Response::error('Extract hatasi — geri alindi: ' . $e->getMessage(), 500);
            }

            // 5) Migration
            $migrations = [];
            try {
                $migrations = UpdateHelper::runMigrations();
            } catch (\Throwable $e) {
                @unlink($zipFile);
                UpdateHelper::restoreBackup(basename($backupDir));
                Response::error('Migration hatasi — geri alindi: ' . $e->getMessage(), 500);
            }

            // 6) VERSION yaz (zip icinde VERSION.json geldiyse bile bizim yazdığımız sürer)
            UpdateHelper::writeVersion($targetVersion);

            // 7) ZIP sil
            @unlink($zipFile);

            Response::success([
                'from' => $current,
                'to' => $targetVersion,
                'filesExtracted' => $extracted,
                'migrationsRun' => $migrations,
                'backup' => basename($backupDir),
            ], 'Güncelleme tamamlandı. Sayfayı yenileyin.');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function history(array $user): void
    {
        AuthMiddleware::requireAdmin($user);
        Response::success([
            'current' => UpdateHelper::currentVersion(),
            'backups' => UpdateHelper::listBackups(),
        ]);
    }

    public function rollback(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $name = $input['backup'] ?? '';
        if (!$name) {
            Response::error('Backup adı belirtilmedi.', 400);
        }

        try {
            $count = UpdateHelper::restoreBackup($name);
            // Backup icindeki VERSION.json'u aktif yap (otomatik olarak zaten restore edildi)
            Response::success([
                'restored' => $count,
                'current' => UpdateHelper::currentVersion(),
            ], 'Geri alma tamamlandi. Sayfayı yenileyin.');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
