<?php
/**
 * 1.1.13 - Takip Araması Geçmiş Poliçe Backfill
 *
 * Canlıya geçiş sırasında çalışır (tek seferlik).
 * follow_up_call_config ayarındaki kurallara göre, daha önce
 * takip araması görevi oluşturulmamış aktif poliçeler için
 * eksik stage görevlerini oluşturur.
 *
 * Koşullar:
 * - Poliçe aktif (is_cancelled=0, expires_at >= CURDATE())
 * - Sigorta türü aktif (is_active=1)
 * - İlgili stage günü geçmiş (DATEDIFF >= stageDays)
 * - Bu stage için henüz FOLLOW_UP_CALL görevi yok (NOT EXISTS)
 */

$pdo = Database::getInstance();
$now = date('Y-m-d H:i:s');

$stages = [
    [
        'key'          => '2ND_MONTH',
        'defaultDays'  => 60,
        'label'        => '2. Ay',
        'priority'     => 'MEDIUM',
        'deadlineDays' => 7,
        'title'        => '[{branchGroup} 2. Ay] Memnuniyet Araması - {customerName}',
        'description'  => "Poliçe No: {policyNo}\nBranş: {branchGroup}\nBaşlangıç: {startsAt}\n\nREHBER: Müşterinin poliçeyi kullanıp kullanmadığını, mobil uygulamayı indirip indirmediğini sorun. Varsa bir şikayet veya ihtiyacını dinleyin.",
    ],
    [
        'key'          => '6TH_MONTH',
        'defaultDays'  => 180,
        'label'        => '6. Ay',
        'priority'     => 'MEDIUM',
        'deadlineDays' => 7,
        'title'        => '[{branchGroup} 6. Ay] Yarı Yıl Kontrolü - {customerName}',
        'description'  => "Poliçe No: {policyNo}\nBranş: {branchGroup}\nBaşlangıç: {startsAt}\n\nREHBER: 6 aylık süreci değerlendirin. Anlaşmalı kurumlardan memnun mu, bir hasar/provizyon sıkıntısı yaşadı mı kontrol edin.",
    ],
    [
        'key'          => '10TH_MONTH',
        'defaultDays'  => 300,
        'label'        => '10. Ay',
        'priority'     => 'HIGH',
        'deadlineDays' => 5,
        'title'        => '[{branchGroup} 10. Ay] Yenileme Öncesi Isıtma - {customerName}',
        'description'  => "Poliçe No: {policyNo}\nBranş: {branchGroup}\nBaşlangıç: {startsAt}\n\nKRİTİK GÖREV: 2 ay sonra yenileme var. Güncel sağlık durumunu yoklayın, hasar/prim oranını kontrol edin ve müşteriyi yeni dönem fiyat artışlarına psikolojik olarak hazırlayın.",
    ],
];

// Ayarlardan follow_up_call_config oku
$configRow = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'follow_up_call_config'")->fetch(PDO::FETCH_ASSOC);
if (!$configRow) return;

$config = json_decode($configRow['value'], true);
if (!is_array($config) || empty($config['rules'])) return;

$created = 0;

foreach ($config['rules'] as $rule) {
    if (empty($rule['enabled']) || empty($rule['branchGroup'])) continue;
    $branchGroup = $rule['branchGroup'];
    $ruleStages  = $rule['stages'] ?? null;

    foreach ($stages as $stage) {
        $stageKey     = $stage['key'];
        $stageDays    = $stage['defaultDays'];
        $stageEnabled = true;

        // Ayarlardaki stage override'ını kontrol et
        if (is_array($ruleStages)) {
            $found = false;
            foreach ($ruleStages as $rs) {
                if (($rs['key'] ?? '') === $stageKey) {
                    $stageEnabled = !empty($rs['enabled']);
                    $stageDays    = (int) ($rs['days'] ?? $stage['defaultDays']);
                    $found        = true;
                    break;
                }
            }
            if (!$found) $stageEnabled = false;
        }
        if (!$stageEnabled) continue;

        // Stage günü geçmiş, aktif, bu stage için görevi olmayan poliçeler
        $stmt = $pdo->prepare(
            "SELECT p.id, p.policy_no, p.customer_id, p.starts_at,
                    cu.name as customer_name, i.name as insurance_name, i.branch_group
             FROM policies p
             INNER JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN customers cu ON p.customer_id = cu.id
             WHERE p.is_cancelled = '0'
               AND p.deleted_at IS NULL
               AND p.expires_at >= CURDATE()
               AND i.branch_group = ?
               AND i.is_active = 1
               AND p.id = (SELECT MAX(p2.id) FROM policies p2 WHERE p2.policy_no = p.policy_no AND p2.deleted_at IS NULL)
               AND DATEDIFF(CURDATE(), DATE(p.starts_at)) >= ?
               AND NOT EXISTS (
                   SELECT 1 FROM tasks t
                   WHERE t.type = 'FOLLOW_UP_CALL'
                     AND t.policy_id = p.id
                     AND t.deleted_at IS NULL
                     AND JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stage')) = ?
               )"
        );
        $stmt->execute([$branchGroup, $stageDays, $stageKey]);
        $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($policies as $policy) {
            $vars = [
                '{branchGroup}'  => $branchGroup,
                '{customerName}' => $policy['customer_name'] ?? '',
                '{policyNo}'     => $policy['policy_no'],
                '{startsAt}'     => $policy['starts_at'] ? date('d.m.Y', strtotime($policy['starts_at'])) : '',
                '{insuranceName}'=> $policy['insurance_name'] ?? '',
            ];
            $title       = str_replace(array_keys($vars), array_values($vars), $stage['title']);
            $description = str_replace(array_keys($vars), array_values($vars), $stage['description']);
            $deadline    = date('Y-m-d H:i:s', strtotime('+' . $stage['deadlineDays'] . ' days'));

            $ins = $pdo->prepare(
                "INSERT INTO tasks (type, title, description, policy_id, customer_id, assigned_to, status, priority, deadline, offer_data, created_at, updated_at)
                 VALUES ('FOLLOW_UP_CALL', ?, ?, ?, ?, NULL, 'PENDING', ?, ?, ?, ?, ?)"
            );
            $ins->execute([
                $title,
                $description,
                (int) $policy['id'],
                $policy['customer_id'] ? (int) $policy['customer_id'] : null,
                $stage['priority'],
                $deadline,
                json_encode([
                    'stage'       => $stageKey,
                    'stageLabel'  => $stage['label'],
                    'branchGroup' => $branchGroup,
                    'triggerDays' => $stageDays,
                ]),
                $now,
                $now,
            ]);

            $taskId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO task_logs (task_id, action, note, created_at) VALUES (?, 'CREATED', ?, ?)")
                ->execute([$taskId, 'Backfill: ' . $stage['label'] . ' takip araması (' . $branchGroup . ')', $now]);

            $created++;
        }
    }
}

// Sonucu logla
$pdo->prepare("INSERT INTO task_logs (task_id, action, note, created_at) VALUES (0, 'MIGRATION', ?, ?)")
    ->execute(['1.1.13 backfill tamamlandi: ' . $created . ' follow-up gorevi olusturuldu', $now]);
