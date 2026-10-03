<?php
/**
 * Migration: 1.4.1-fix-dask-branch-group
 *
 * 1. DASK branch_group düzeltmesi:
 *    DASK ve KONUT aynı branch_group ('KONUT') değerine sahipti.
 *    DASK yenilendiğinde KONUT görevi de "RENEWED" olarak kapanıyordu.
 *    ÇÖZÜM: DASK'ın branch_group değeri 'DASK' olarak ayrıldı.
 *
 * 2. Otomatik RENEWED kapatma devre dışı:
 *    Ana sayfada yenileme görevleri otomatik olarak RENEWED yapılıp
 *    listeden kaldırılıyordu. Bu özellik tamamen devre dışı bırakıldı.
 *    Görevler ana sayfada kalır, kullanıcı manuel yönetir.
 *
 * 3. Yanlış RENEWED kapanan görevleri geri açma:
 *    Otomatik güncelleme notu ile RENEWED yapılmış TÜM görevler
 *    (sadece KONUT değil, tüm türler) PENDING'e geri alınır.
 *
 * DEĞİŞEN DOSYALAR:
 *   - api/controllers/DashboardController.php (otomatik RENEWED bloğu kaldırıldı)
 */

$db = Database::getInstance();


 
$db->exec("UPDATE insurance_types SET branch_group = 'DASK' WHERE code = 'DASK' AND branch_group = 'KONUT'");
 
 
$wronglyRenewed = $db->query(
    "SELECT t.id, p.policy_no, i.name as insurance_name
     FROM tasks t
     LEFT JOIN policies p ON t.policy_id = p.id
     LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
     WHERE t.type = 'RENEWAL'
       AND t.status = 'COMPLETED'
       AND t.result = 'RENEWED'
       AND t.result_note = 'Yeni poliçe tespit edildi — otomatik güncellendi'
       AND t.deleted_at IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("UPDATE tasks SET status=?,result=?,result_note=?,completed_at=?,updated_at=? WHERE id=?");
 
foreach ($wronglyRenewed as $task) {
	$stmt->execute(['PENDING',null,null,null,date('Y-m-d H:i:s'),$task['id']]);
}
 
