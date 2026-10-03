<?php
$pdo = new PDO('mysql:host=localhost;dbname=crm', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "=== lost_policy_actions ===\n";
$res = $pdo->query('DESCRIBE lost_policy_actions');
foreach ($res as $r) echo $r['Field'] . ' | ' . $r['Type'] . ' | NULL:' . $r['Null'] . ' | Default:' . $r['Default'] . "\n";

echo "\n=== permissions ===\n";
$res = $pdo->query('DESCRIBE permissions');
foreach ($res as $r) echo $r['Field'] . ' | ' . $r['Type'] . "\n";

echo "\n=== user_permissions ===\n";
$res = $pdo->query('DESCRIBE user_permissions');
foreach ($res as $r) echo $r['Field'] . ' | ' . $r['Type'] . "\n";
