<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/Database.php';

$pdo = Database::getInstance();

// tasks: id=4268 duplicate — orijinali ve eski id=0'ı bul
$dupes = $pdo->query("SELECT id, type, title, created_at FROM tasks WHERE id = 4268")->fetchAll(PDO::FETCH_ASSOC);
echo "tasks id=4268 kayıt sayısı: " . count($dupes) . "\n";
foreach ($dupes as $d) echo "  type={$d['type']} title={$d['title']} created={$d['created_at']}\n";

// Gerçek max id
$max = $pdo->query("SELECT MAX(id) as m FROM tasks WHERE id > 0")->fetch();
echo "tasks max id: {$max['m']}\n";

// id=0 olan eski kaydı daha yüksek bir id'ye taşı
$zero = $pdo->query("SELECT COUNT(*) as cnt FROM tasks WHERE id = 0")->fetch();
echo "tasks id=0 var mı: {$zero['cnt']}\n";

if ((int)$zero['cnt'] > 0) {
    $newId = (int)$max['m'] + 1;
    $pdo->exec("UPDATE tasks SET id = $newId WHERE id = 0 LIMIT 1");
    echo "tasks id=0 → id=$newId\n";
}

// task_logs aynı işlem
$max2 = $pdo->query("SELECT MAX(id) as m FROM task_logs WHERE id > 0")->fetch();
echo "\ntask_logs max id: {$max2['m']}\n";
$zero2 = $pdo->query("SELECT COUNT(*) as cnt FROM task_logs WHERE id = 0")->fetch();
echo "task_logs id=0 var mı: {$zero2['cnt']}\n";

if ((int)$zero2['cnt'] > 0) {
    $newId2 = (int)$max2['m'] + 1;
    $pdo->exec("UPDATE task_logs SET id = $newId2 WHERE id = 0 LIMIT 1");
    echo "task_logs id=0 → id=$newId2\n";
}

// Şimdi PK + AI ekle
foreach (['tasks', 'task_logs'] as $table) {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM `$table` WHERE Field = 'id'")->fetch();
        $pdo->exec("ALTER TABLE `$table` ADD PRIMARY KEY (`id`)");
        $pdo->exec("ALTER TABLE `$table` MODIFY `id` {$col['Type']} NOT NULL AUTO_INCREMENT");
        $max = $pdo->query("SELECT COALESCE(MAX(id), 0) as m FROM `$table`")->fetch();
        $nextId = (int)$max['m'] + 1;
        $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = $nextId");
        echo "$table OK — AUTO_INCREMENT = $nextId\n";
    } catch (Exception $e) {
        echo "$table ERROR — {$e->getMessage()}\n";
    }
}

// Final kontrol
$remaining = $pdo->query("
    SELECT TABLE_NAME FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = 'crm' AND AUTO_INCREMENT IS NULL AND TABLE_TYPE = 'BASE TABLE'
")->fetchAll(PDO::FETCH_COLUMN);
echo "\nKalan eksik: " . count($remaining) . " (" . implode(', ', $remaining) . ")\n";
