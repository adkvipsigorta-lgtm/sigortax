<?php
/**
 * Fix v1.4.3 — DASK/KONUT branch_group + Otomatik RENEWED kapatma
 *
 * 1. DASK branch_group 'KONUT' → 'DASK' (DASK yenilemesi KONUT görevini kapatmasın)
 * 2. DashboardController.php'den otomatik RENEWED bloğunu kaldırır
 * 3. Yanlış RENEWED yapılmış görevleri PENDING'e geri alır
 *
 * Kullanım: Sunucuda api/ klasöründe çalıştırın
 *   php tools/fix-1.4.3-dask-auto-renewed.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$basePath = realpath(__DIR__ . '/..');
echo "=== Fix v1.4.3 — DASK + Otomatik RENEWED ===\n";
echo "Base: $basePath\n\n";

// ─── DB Bağlantısı ──────────────────────────────────────────────────────
require_once $basePath . '/config.php';
require_once $basePath . '/helpers/Database.php';
Database::connect();

// ─── 1. DASK branch_group düzelt ────────────────────────────────────────
$stmt = Database::execute(
    "UPDATE insurance_types SET branch_group = 'DASK' WHERE code = 'DASK' AND branch_group = 'KONUT'"
);
$affected = $stmt->rowCount();
if ($affected > 0) {
    echo "[OK] DASK branch_group -> 'DASK' ($affected kayit)\n";
} else {
    echo "[ATLA] DASK branch_group zaten 'DASK'\n";
}

// ─── 2. DashboardController.php — otomatik RENEWED bloğunu kaldır ───────
$file = $basePath . '/controllers/DashboardController.php';
if (!file_exists($file)) {
    echo "[HATA] DashboardController.php bulunamadi\n";
    exit(1);
}

$content = file_get_contents($file);

// Kaldırılacak blok
$searchBlock = <<<'BLOCK'
        // 1. Otomatik yenileme duzeltmesi (dedup'tan ONCE calis — dogru priority ile dedup yapilsin)
        // Aktif yeni police bulunmuşsa (new_gross_premium), tum statu ve tum branslarda RENEWED'e guncelle
        // Istisna: zaten basarili kapatilmis gorevler (COMPLETED + success result)
        $successResults2 = ['RENEWED', 'OFFER_APPROVED', 'DONE'];
        $autoUpdated = [];
        $now = date('Y-m-d H:i:s');
        foreach ($allDetails as &$r) {
            if (!$r['new_gross_premium']) continue;

            $alreadySuccess = $r['status'] === 'COMPLETED' && in_array($r['result'] ?? '', $successResults2);
            if ($alreadySuccess) continue;

            // Gercekte yenilenmiş — task'i COMPLETED+RENEWED olarak guncelle
            Database::update('tasks', [
                'status'        => 'COMPLETED',
                'result'        => 'RENEWED',
                'result_note'   => 'Yeni poliçe tespit edildi — otomatik güncellendi',
                'completed_at'  => $now,
                'updated_at'    => $now,
            ], 'id = ?', [$r['id']]);
            $r['status']       = 'COMPLETED';
            $r['result']       = 'RENEWED';
            $r['completed_at'] = $now;
            $r['auto_updated'] = true;
            $autoUpdated[]     = $r['id'];
        }
        unset($r);
BLOCK;

$replaceBlock = <<<'BLOCK'
        // Otomatik yenileme kapatma DEVRE DISI — görevler ana sayfada kalır,
        // kullanıcı manuel olarak yönetir. (v1.4.4)
        $now = date('Y-m-d H:i:s');
BLOCK;

if (strpos($content, 'Otomatik yenileme kapatma DEVRE DISI') !== false) {
    echo "[ATLA] DashboardController zaten guncel\n";
} elseif (strpos($content, $searchBlock) !== false) {
    // Yedek al
    $backup = $file . '.bak-' . date('YmdHis');
    copy($file, $backup);
    echo "[YEDEK] $backup\n";

    $content = str_replace($searchBlock, $replaceBlock, $content);
    file_put_contents($file, $content);
    echo "[OK] DashboardController: otomatik RENEWED blogu kaldirildi\n";
} else {
    echo "[UYARI] DashboardController: hedef blok bulunamadi — manuel kontrol edin\n";
}

// ─── 3. Yanlış RENEWED görevleri geri aç ────────────────────────────────
$wrongly = Database::fetchAll(
    "SELECT t.id, p.policy_no, i.name as insurance_name
     FROM tasks t
     LEFT JOIN policies p ON t.policy_id = p.id
     LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
     WHERE t.type = 'RENEWAL'
       AND t.status = 'COMPLETED'
       AND t.result = 'RENEWED'
       AND t.result_note = 'Yeni poliçe tespit edildi — otomatik güncellendi'
       AND t.deleted_at IS NULL"
);

$fixed = 0;
foreach ($wrongly as $task) {
    Database::update('tasks', [
        'status'       => 'PENDING',
        'result'       => null,
        'result_note'  => null,
        'completed_at' => null,
        'updated_at'   => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task['id']]);
    $fixed++;
    echo "[DUZELT] Gorev #{$task['id']} ({$task['insurance_name']} - {$task['policy_no']}) -> PENDING\n";
}

echo "\n=== Sonuc ===\n";
echo "DASK branch_group: duzeltildi\n";
echo "Otomatik RENEWED: devre disi\n";
echo "Geri alinan gorev: $fixed adet\n";
echo "=== Fix tamamlandi ===\n";
