<?php
/**
 * Sigorta CRM - Eski DB -> Yeni DB Donusum Araci
 *
 * Kullanim:
 *   php convert.php                   # Kuru calistirma (analiz)
 *   php convert.php --execute         # Gercek donusum
 */

$config = [
    'host' => '127.0.0.1', 'port' => 3306,
    'oldDb' => 'sigorta_app',
    'newDb' => 'sigorta_app_v2',
    'username' => 'root', 'password' => '06010255',
];

$dryRun = !in_array('--execute', $argv ?? []);

echo "╔══════════════════════════════════════════════════╗\n";
echo "║  Sigorta CRM - Eski -> Yeni Donusum             ║\n";
echo "║  Kaynak: {$config['oldDb']}                     ║\n";
echo "║  Hedef:  {$config['newDb']}                     ║\n";
echo "║  Mod: " . ($dryRun ? "KURU CALISTIRMA" : "GERCEK DONUSUM ") . "                        ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";
if ($dryRun) echo "⚠  php convert.php --execute ile calistirin\n\n";

// --- Helpers ---
function convertDate(?string $d): ?string {
    if (!$d || !trim($d)) return null;
    $d = trim($d);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $d, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('#^(\d{2})-(\d{2})-(\d{4})$#', $d, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $d)) return $d;
    return null;
}
function convertAmount(?string $a): float {
    if ($a === null || trim($a) === '') return 0.00;
    $c = str_replace([' ', ','], ['', '.'], trim($a));
    return is_numeric($c) ? (float) $c : 0.00;
}
function toBool($v): int { return ($v === '1' || $v === 1) ? 1 : 0; }
function ts(?string $t): ?string {
    if (!$t || !trim($t)) return null;
    if (preg_match('#^\d{4}-\d{2}-\d{2}T#', $t)) return str_replace('T', ' ', substr($t, 0, 19));
    return $t;
}
function convertProd(?string $p): string {
    return match ($p) { 'FROM_OUT' => 'INCOMING', 'TO_OUT' => 'OUTGOING', default => 'SELF' };
}

// --- DB ---
$OLD = $config['oldDb'];
$NEW = $config['newDb'];
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // Verify both databases exist
    $dbs = array_column($pdo->query("SHOW DATABASES")->fetchAll(), 'Database');
    if (!in_array($OLD, $dbs)) die("✗ Kaynak DB '$OLD' bulunamadi\n");
    if (!in_array($NEW, $dbs)) die("✗ Hedef DB '$NEW' bulunamadi\n");
    echo "✓ Kaynak: $OLD, Hedef: $NEW\n\n";
} catch (PDOException $e) { die("✗ " . $e->getMessage() . "\n"); }

function existsIn(PDO $p, string $db, string $t): bool {
    return $p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db' AND table_name='$t'")->fetchColumn() > 0;
}
function cntIn(PDO $p, string $db, string $t): int {
    return existsIn($p, $db, $t) ? (int) $p->query("SELECT COUNT(*) FROM `$db`.`$t`")->fetchColumn() : 0;
}

$errors = [];

// =============================================
// ANALIZ
// =============================================

echo "── KAYNAK TABLOLAR ($OLD) ──\n";
foreach (['branch','city','district','country','company','insurance','customers','policy','users','options','tasks','task_logs','login_sessions'] as $t) {
    echo "  " . (existsIn($pdo, $OLD, $t) ? "✓ $t (" . cntIn($pdo, $OLD, $t) . ")" : "✗ $t") . "\n";
}

echo "\n── HEDEF TABLOLAR ($NEW) ──\n";
foreach (['branches','cities','districts','countries','companies','insurance_types','customers','policies','users','settings','tasks','task_logs','sessions'] as $t) {
    echo "  " . (existsIn($pdo, $NEW, $t) ? "✓ $t (" . cntIn($pdo, $NEW, $t) . ")" : "✗ $t") . "\n";
}

if (existsIn($pdo, $OLD, 'policy')) {
    echo "\n── POLICE ANALIZI ──\n";
    $total = cntIn($pdo, $OLD, 'policy');
    $offers = (int) $pdo->query("SELECT COUNT(*) FROM `$OLD`.policy WHERE is_offer='1' AND deleted_at IS NULL")->fetchColumn();
    $policies = (int) $pdo->query("SELECT COUNT(*) FROM `$OLD`.policy WHERE is_offer='0' AND deleted_at IS NULL")->fetchColumn();
    $uniquePn = (int) $pdo->query("SELECT COUNT(DISTINCT policy_number) FROM `$OLD`.policy WHERE is_offer='0' AND deleted_at IS NULL")->fetchColumn();
    echo "  Toplam: $total, Police: $policies, Teklif: $offers (ATLANACAK)\n";
    echo "  Unique policy_number: $uniquePn\n";
    echo "  Zeyil/yenileme: " . ($policies - $uniquePn) . " kayit parent_id ile baglanacak\n";
    echo "  Teklif -> Task: $offers kayit OFFER gorevi olarak aktarilacak\n";

    // Zeyil dagilimi
    $zeyils = $pdo->query("SELECT zeyil_number, COUNT(*) as c FROM `$OLD`.policy WHERE is_offer='0' AND deleted_at IS NULL GROUP BY zeyil_number ORDER BY zeyil_number")->fetchAll();
    echo "  Zeyil dagilimi: ";
    $parts = [];
    foreach ($zeyils as $z) $parts[] = "z{$z['zeyil_number']}={$z['c']}";
    echo implode(', ', $parts) . "\n";

    // Ornek
    echo "\n  Donusum ornegi:\n";
    $sample = $pdo->query("SELECT policy_number FROM `$OLD`.policy WHERE is_offer='0' AND deleted_at IS NULL GROUP BY policy_number HAVING COUNT(*) > 2 LIMIT 1")->fetchColumn();
    if ($sample) {
        $rows = $pdo->query("SELECT id, zeyil_number, is_cancel, gross_amount FROM `$OLD`.policy WHERE policy_number='$sample' AND is_offer='0' AND deleted_at IS NULL ORDER BY zeyil_number")->fetchAll();
        foreach ($rows as $i => $r) {
            $isParent = $i === 0;
            echo "    id={$r['id']} zeyil={$r['zeyil_number']} -> policy_no='$sample' endorsement_no={$r['zeyil_number']} parent_id=" . ($isParent ? 'NULL' : $rows[0]['id']) . "\n";
        }
    }
}

if ($dryRun) {
    echo "\n══════════════════════════════════════\n  KURU CALISTIRMA TAMAMLANDI\n══════════════════════════════════════\n";
    exit(0);
}

// =============================================
// DONUSUM
// =============================================

echo "\n── ADIM 1: Hedef tablolari temizle ──\n";
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// Hedef tablolari truncate et (temiz aktarim icin)
$targetTables = ['cities','districts','countries','branches','companies','insurance_types','users','customers','policies','settings','sessions','audit_logs','customer_categories','documents','messages','message_templates','notifications','tasks','task_logs'];
foreach ($targetTables as $t) {
    if (existsIn($pdo, $NEW, $t)) {
        $pdo->exec("TRUNCATE TABLE `$NEW`.`$t`");
    }
}
echo "  ✓ Hedef tablolar temizlendi\n";

// =============================================
// ADIM 3: VERI AKTARIMI
// =============================================

echo "\n── ADIM 3: Veri aktarimi ──\n";

// --- cities ---
if (existsIn($pdo, $OLD, 'city')) {
    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$OLD`.city")->fetchAll() as $r) {
        try {
            $pdo->prepare("INSERT IGNORE INTO `$NEW`.cities (id, name, country_id, created_at, updated_at) VALUES (?,?,?,?,?)")
                ->execute([$r['id'], $r['name'], $r['country'] ?? null, ts($r['created_at']), ts($r['updated_at'])]);
            $n++;
        } catch (PDOException $e) { $errors[] = "cities: " . $e->getMessage(); }
    }
    echo "  ✓ cities: $n\n";
}

// --- districts ---
if (existsIn($pdo, $OLD, 'district')) {
    $total = cntIn($pdo, $OLD, 'district');
    $n = 0; $off = 0;
    while ($off < $total) {
        foreach ($pdo->query("SELECT * FROM `$OLD`.district LIMIT 5000 OFFSET $off")->fetchAll() as $r) {
            try {
                $pdo->prepare("INSERT IGNORE INTO `$NEW`.districts (id, city_id, name, created_at, updated_at) VALUES (?,?,?,?,?)")
                    ->execute([$r['id'], $r['city'] ?? null, $r['name'], ts($r['created_at']), ts($r['updated_at'])]);
                $n++;
            } catch (PDOException $e) { $errors[] = "districts: " . $e->getMessage(); }
        }
        $off += 5000;
    }
    echo "  ✓ districts: $n\n";
}

// --- countries ---
if (existsIn($pdo, $OLD, 'country')) {
    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$OLD`.country")->fetchAll() as $r) {
        try {
            $pdo->prepare("INSERT IGNORE INTO `$NEW`.countries (id, name, created_at, updated_at) VALUES (?,?,?,?)")
                ->execute([$r['id'], $r['name'], ts($r['created_at']), ts($r['updated_at'])]);
            $n++;
        } catch (PDOException $e) { $errors[] = "countries: " . $e->getMessage(); }
    }
    echo "  ✓ countries: $n\n";
}

// --- branches ---
if (existsIn($pdo, $OLD, 'branch')) {
    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$OLD`.branch")->fetchAll() as $r) {
        $iban = trim($r['iban'] ?? ''); if ($iban === '') $iban = null;
        try {
            $pdo->prepare("INSERT IGNORE INTO `$NEW`.branches (id, name, phone, commission_rate, iban, created_at, updated_at, deleted_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$r['id'], $r['name'], $r['phone_number'] ?? null, (int)($r['commission'] ?? 0), $iban, ts($r['created_at']), ts($r['updated_at']), ts($r['deleted_at'] ?? null)]);
            $n++;
        } catch (PDOException $e) { $errors[] = "branches: " . $e->getMessage(); }
    }
    echo "  ✓ branches: $n\n";
}

// --- companies ---
if (existsIn($pdo, $OLD, 'company')) {
    // Theme name -> hex renk donusumu
    $companyThemeToHex = [
        'primary' => '#3B82F6', 'error' => '#EF4444', 'success' => '#22C55E',
        'warning' => '#F59E0B', 'info' => '#8B5CF6', 'neutral' => '#6B7280',
    ];
    // Her sirket icin farkli guzel renkler
    $companyColorPalette = [
        '#3B82F6', '#EF4444', '#22C55E', '#F59E0B', '#8B5CF6',
        '#06B6D4', '#EC4899', '#F97316', '#14B8A6', '#6366F1',
        '#84CC16', '#E11D48', '#0EA5E9', '#A855F7', '#10B981',
        '#D946EF', '#F43F5E', '#0284C7', '#7C3AED', '#059669',
    ];
    $compColorIdx = 0;

    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$OLD`.company")->fetchAll() as $r) {
        try {
            $oldColor = $r['color'] ?? 'primary';
            // Eger zaten hex ise koru, theme name ise convert et, yoksa palette'ten ata
            if (str_starts_with($oldColor, '#')) {
                $hex = $oldColor;
            } elseif (isset($companyThemeToHex[$oldColor])) {
                $hex = $companyThemeToHex[$oldColor];
            } else {
                $hex = $companyColorPalette[$compColorIdx % count($companyColorPalette)];
                $compColorIdx++;
            }

            $pdo->prepare("INSERT IGNORE INTO `$NEW`.companies (id, name, color, logo_url, created_at, updated_at, deleted_at) VALUES (?,?,?,?,?,?,?)")
                ->execute([$r['id'], $r['name'], $hex, $r['logo'] ?? null, ts($r['created_at']), ts($r['updated_at']), ts($r['deleted_at'] ?? null)]);
            $n++;
        } catch (PDOException $e) { $errors[] = "companies: " . $e->getMessage(); }
    }
    echo "  ✓ companies: $n\n";

    // Logo eslestirmesi: sirket id -> dosya adi
    $logoMap = [
        1 => 'anadolu-sigorta.png', 2 => 'turkiye-sigorta.png', 3 => 'quick.png',
        4 => 'aksigorta.png', 5 => 'hepiyi.png', 6 => 'axa.png',
        7 => 'hdi.png', 8 => 'allianz.png', 9 => 'koru.png',
        10 => 'corpus.png', 11 => 'ray.png', 12 => 'unico.png',
        13 => 'sompo.png', 14 => 'bupa-acibadem.png', 15 => 'acnturk.png',
        17 => 'ana-sigorta.png', 18 => 'ankara-sigorta.png', 19 => 'atlas.png',
        22 => 'bereket.png', 24 => 'doga.png', 26 => 'ethica.png',
        27 => 'eureko.png', 33 => 'magdeburger.png', 34 => 'mapfre.png',
        35 => 'neova.png', 36 => 'orient.png', 38 => 'turk-nippon.png',
        40 => 'zurich.png', 41 => 'seker.png', 42 => 'arex.png',
        43 => 'emaa.png', 44 => 'prive.png', 45 => 'turk-nippon.png',
        46 => 'ana-sigorta.png', 47 => 'referans.png',
    ];
    $logoUpdated = 0;
    foreach ($logoMap as $cid => $logo) {
        $pdo->prepare("UPDATE `$NEW`.companies SET logo_url = ? WHERE id = ?")->execute(['/api/uploads/companies/' . $logo, $cid]);
        $logoUpdated++;
    }
    echo "  ✓ company logos: $logoUpdated\n";
}

// --- insurance_types ---
if (existsIn($pdo, $OLD, 'insurance')) {
    // Eski tema renklerini hex'e cevir
    $colorPalette = [
        '#3B82F6', '#EF4444', '#22C55E', '#F59E0B', '#8B5CF6',
        '#06B6D4', '#EC4899', '#F97316', '#14B8A6', '#6366F1',
        '#84CC16', '#E11D48', '#0EA5E9', '#A855F7', '#10B981',
        '#D946EF', '#F43F5E', '#0284C7', '#7C3AED', '#059669',
    ];
    $colorIndex = 0;
    $assignedColors = []; // id -> hex

    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$OLD`.insurance")->fetchAll() as $r) {
        try {
            // Her subcategory'ye benzersiz guzel renk ata
            $level = $r['type'] ?? null;
            if ($level === 'subcategory') {
                $hex = $colorPalette[$colorIndex % count($colorPalette)];
                $colorIndex++;
            } elseif ($level === 'category') {
                $hex = '#64748B'; // slate gray
            } else {
                $hex = '#475569'; // dark slate
            }

            $pdo->prepare("INSERT IGNORE INTO `$NEW`.insurance_types (id, name, code, color, default_comm_rate, extra_comm_rate, level, parent_id, is_active, show_in_charts, branch_group, external_code, renewal_days, created_at, updated_at, deleted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $r['id'], $r['name'], $r['key'] ?? 'OTHER', $hex,
                    (int)($r['commission'] ?? 0), (int)($r['commission2'] ?? 0),
                    $level, $r['parent'] ?? null,
                    toBool($r['status'] ?? '0'), toBool($r['chart_status'] ?? '0'),
                    $r['group'] ?? 'DIGER', $r['allianz_id'] ?? null,
                    (int)($r['reminder_days'] ?? 15),
                    ts($r['created_at']), ts($r['updated_at']), ts($r['deleted_at'] ?? null),
                ]);
            $n++;
        } catch (PDOException $e) { $errors[] = "insurance_types: " . $e->getMessage(); }
    }
    echo "  ✓ insurance_types: $n\n";

    // Code duzeltmeleri: branch_group'a gore dogru code ata
    $codeFixMap = [
        'KONUT' => ['HOUSING', 'DASK'], // KONUT -> HOUSING, DASK ise DASK
        'SAĞLIK' => 'HEALTH',
    ];
    $pdo->exec("UPDATE `$NEW`.insurance_types SET code = 'HOUSING' WHERE level = 'subcategory' AND branch_group = 'KONUT' AND name NOT LIKE '%DASK%'");
    $pdo->exec("UPDATE `$NEW`.insurance_types SET code = 'DASK' WHERE level = 'subcategory' AND branch_group = 'KONUT' AND name LIKE '%DASK%'");
    $pdo->exec("UPDATE `$NEW`.insurance_types SET code = 'HEALTH' WHERE level = 'subcategory' AND branch_group = 'SAĞLIK'");
    echo "  ✓ insurance_types code fixes applied\n";
}

// --- users ---
if (existsIn($pdo, $OLD, 'users')) {
    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$OLD`.users")->fetchAll() as $r) {
        try {
            $pdo->prepare("INSERT IGNORE INTO `$NEW`.users (id, name, email, password, is_active, role, branch_id, two_factor_secret, two_factor_enabled, two_factor_recovery_codes, created_at, updated_at, deleted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $r['id'], $r['full_name'], $r['email'], $r['password'],
                    toBool($r['status'] ?? '1'), (int)($r['type'] ?? 0), $r['branch'] ?? null,
                    $r['two_factor_secret'] ?? null,
                    isset($r['two_factor_enabled']) ? toBool($r['two_factor_enabled']) : 0,
                    $r['two_factor_recovery_codes'] ?? null,
                    ts($r['created_at']), ts($r['updated_at']), ts($r['deleted_at'] ?? null),
                ]);
            $n++;
        } catch (PDOException $e) { $errors[] = "users: " . $e->getMessage(); }
    }
    echo "  ✓ users: $n\n";
}

// --- customers ---
if (existsIn($pdo, $OLD, 'customers')) {
    $total = cntIn($pdo, $OLD, 'customers');
    $n = 0; $off = 0;
    while ($off < $total) {
        foreach ($pdo->query("SELECT * FROM `$OLD`.customers LIMIT 500 OFFSET $off")->fetchAll() as $r) {
            try {
                $pdo->prepare("INSERT IGNORE INTO `$NEW`.customers (id, customer_type, name, identity_no, tax_office, birth_date, phone, phone_alt, email, contact_person, marital_status, job, dependents_count, sector, city_id, district_id, country_id, address, note, created_at, updated_at, deleted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $r['id'], $r['type'] ?? 'INDIVIDUAL', $r['name'],
                        $r['identity_number'] ?? null, $r['tax_office'] ?? null,
                        convertDate($r['birth_date'] ?? null),
                        $r['primary_phone'] ?? null, $r['secondary_phone'] ?? null,
                        $r['email'] ?? null, $r['authorized_full_name'] ?? null,
                        $r['martial_status'] ?? null, $r['job'] ?? null,
                        $r['number_of_children_employees'] ?? null, $r['company_name_or_sector'] ?? null,
                        $r['city'] ?? null, $r['district'] ?? null,
                        $r['country'] ?? null,
                        $r['address'] ?? null, $r['note'] ?? null,
                        ts($r['created_at']), ts($r['updated_at']), ts($r['deleted_at'] ?? null),
                    ]);
                $n++;
            } catch (PDOException $e) { $errors[] = "customers #{$r['id']}: " . $e->getMessage(); }
        }
        $off += 500;
    }
    echo "  ✓ customers: $n / $total\n";
}

// =============================================
// MUSTERI KATEGORILERI - Varsayilan kategorileri olustur ve tum musterilere ata
// =============================================

echo "\n  ── MUSTERI KATEGORILERI ──\n";
$pdo->exec("INSERT IGNORE INTO `$NEW`.customer_categories (id, name, color, description, min_amount, max_amount) VALUES
    (1, 'Standart', '#3B82F6', '0 - 1.000 TL arasi prim', 0, 1000),
    (2, 'Gumus', '#06B6D4', '1.000 - 10.000 TL arasi prim', 1000, 10000),
    (3, 'Altin', '#22C55E', '10.000 TL ve uzeri prim', 10000, NULL)
");
echo "  ✓ musteri kategorileri olusturuldu (prim araligina gore otomatik gruplama)\n";

// =============================================
// POLICIES - EN KRITIK DONUSUM
// is_offer=1 -> tasks tablosuna OFFER olarak aktarilir (asagida)
// Zeyil: parent_id ile ana policeye baglanir
// policy_no: Hepsi ayni base numara, endorsement_no ile ayrisir
// =============================================

if (existsIn($pdo, $OLD, 'policy')) {
    echo "\n  ── POLICIES (buyuk donusum) ──\n";

    $total = (int) $pdo->query("SELECT COUNT(*) FROM `$OLD`.policy WHERE is_offer='0'")->fetchColumn();
    $skippedOffers = (int) $pdo->query("SELECT COUNT(*) FROM `$OLD`.policy WHERE is_offer='1'")->fetchColumn();
    echo "  Police: $total, Teklif atlanan: $skippedOffers\n";

    $parentMap = [];
    echo "  Pass 1: Ana policeleri belirleniyor...\n";
    $groups = $pdo->query("
        SELECT policy_number, MIN(id) as parent_id, COUNT(*) as cnt
        FROM `$OLD`.policy
        WHERE is_offer='0'
        GROUP BY policy_number
    ")->fetchAll();

    foreach ($groups as $g) {
        $parentMap[$g['policy_number']] = (int) $g['parent_id'];
    }

    $multiCount = count(array_filter($groups, fn($g) => $g['cnt'] > 1));
    echo "  Tekli police: " . (count($groups) - $multiCount) . ", Zeyilli grup: $multiCount\n";

    echo "  Pass 2: Veri aktariliyor...\n";
    $n = 0; $off = 0;
    while ($off < $total) {
        $rows = $pdo->query("SELECT * FROM `$OLD`.policy WHERE is_offer='0' ORDER BY id LIMIT 500 OFFSET $off")->fetchAll();
        if (empty($rows)) break;

        foreach ($rows as $r) {
            try {
                $policyNumber = $r['policy_number'];
                $zeyilNo = (int) ($r['zeyil_number'] ?? 1);
                $thisId = (int) $r['id'];
                $parentId = $parentMap[$policyNumber] ?? null;
                $isParent = ($parentId === $thisId);

                // policy_no her zaman ayni base numara, zeyil endorsement_no ile ayrisir
                $policyNo = $policyNumber;
                $actualParentId = $isParent ? null : $parentId;

                $pdo->prepare("INSERT IGNORE INTO `$NEW`.policies (
                    id, parent_id, production_type, customer_id, insurance_type_id, company_id, branch_id,
                    policy_no, insured_name, issued_at, starts_at, expires_at,
                    gross_premium, net_premium, company_comm_rate, branch_comm_rate,
                    is_cancelled, is_approved, endorsement_no,
                    plate_no, chassis_no, engine_no, registration_no,
                    vehicle_brand, vehicle_model, vehicle_year,
                    uavt_code, dask_no, network, additional_insureds, reference_source,
                    created_at, updated_at, deleted_at
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $thisId, $actualParentId,
                        convertProd($r['prod'] ?? 'SELF'),
                        $r['customer'], $r['insurance'], $r['company'] ?? null, $r['branch'] ?? null,
                        $policyNo, $r['insured'] ?? null,
                        convertDate($r['issue_date'] ?? null),
                        convertDate($r['start_date'] ?? null),
                        convertDate($r['finish_date'] ?? null),
                        convertAmount($r['gross_amount'] ?? null),
                        convertAmount($r['net_amount'] ?? null),
                        (int)($r['company_commission'] ?? 0),
                        (int)($r['branch_commission'] ?? 0),
                        toBool($r['is_cancel'] ?? '0'),
                        toBool($r['consensus'] ?? '0'),
                        $zeyilNo,
                        $r['plate'] ?? null, $r['chassis_number'] ?? null,
                        $r['engine_number'] ?? null, $r['license_serial_number'] ?? null,
                        $r['brand'] ?? null, $r['model'] ?? null, $r['model_year'] ?? null,
                        $r['uavt'] ?? null, $r['dask_policy_number'] ?? null,
                        $r['network'] ?? null, $r['insureds'] ?? null,
                        $r['reference_source'] ?? null,
                        ts($r['created_at']), ts($r['updated_at']), ts($r['deleted_at'] ?? null),
                    ]);
                $n++;
            } catch (PDOException $e) { $errors[] = "policies #{$r['id']}: " . $e->getMessage(); }
        }
        $off += 500;
        if ($off % 2000 === 0) echo "    ... $n / $total\n";
    }

    echo "  ✓ policies: $n / $total (teklif atlanan: $skippedOffers)\n";

    $withParent = (int) $pdo->query("SELECT COUNT(*) FROM `$NEW`.policies WHERE parent_id IS NOT NULL")->fetchColumn();
    $withoutParent = (int) $pdo->query("SELECT COUNT(*) FROM `$NEW`.policies WHERE parent_id IS NULL")->fetchColumn();
    echo "  Ana police: $withoutParent, Zeyil/yenileme: $withParent\n";

    $samplePn = $pdo->query("SELECT policy_no FROM `$NEW`.policies WHERE parent_id IS NULL AND id IN (SELECT parent_id FROM `$NEW`.policies WHERE parent_id IS NOT NULL) LIMIT 1")->fetchColumn();
    if ($samplePn) {
        echo "  Ornek '$samplePn':\n";
        $ex = $pdo->query("SELECT id, parent_id, policy_no, endorsement_no, is_cancelled, gross_premium FROM `$NEW`.policies WHERE policy_no = '$samplePn' ORDER BY endorsement_no")->fetchAll();
        foreach ($ex as $e) {
            echo "    id={$e['id']} parent={$e['parent_id']} no='{$e['policy_no']}' endo={$e['endorsement_no']} cancel={$e['is_cancelled']} gross={$e['gross_premium']}\n";
        }
    }
}

// --- settings ---
if (existsIn($pdo, $OLD, 'options')) {
    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$OLD`.options")->fetchAll() as $r) {
        try {
            $pdo->prepare("INSERT IGNORE INTO `$NEW`.settings (id, `key`, `value`, created_at, updated_at) VALUES (?,?,?,?,?)")
                ->execute([$r['id'], $r['meta_key'], $r['meta_value'] ?? null, ts($r['created_at'] ?? null), ts($r['updated_at'] ?? null)]);
            $n++;
        } catch (PDOException $e) { $errors[] = "settings: " . $e->getMessage(); }
    }
    echo "  ✓ settings: $n\n";
}

// Varsayilan field ayarlarini ekle (yoksa)
$defaultFieldSettings = [
    'individual_phone_2' => '1', 'individual_marital_status' => '1', 'individual_job' => '1',
    'individual_number_of_children' => '1', 'individual_address' => '1', 'individual_city' => '1', 'individual_note' => '1',
    'company_phone_2' => '1', 'company_sector' => '1', 'company_number_of_employees' => '1',
    'company_address' => '1', 'company_city' => '1', 'company_note' => '1',
    'chassis_no' => '1', 'engine_no' => '1', 'policy_brand' => '1',
    'policy_model' => '1', 'vehicle_year' => '1', 'policy_uavt' => '1',
    'dask_no' => '1', 'policy_network' => '1',
];
foreach ($defaultFieldSettings as $fk => $fv) {
    $exists = $pdo->prepare("SELECT id FROM `$NEW`.settings WHERE `key` = ?");
    $exists->execute([$fk]);
    if (!$exists->fetch()) {
        $pdo->prepare("INSERT INTO `$NEW`.settings (`key`, `value`) VALUES (?, ?)")->execute([$fk, $fv]);
    }
}
echo "  ✓ varsayilan field ayarlari eklendi\n";

// --- sessions ---
if (existsIn($pdo, $OLD, 'login_sessions')) {
    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$OLD`.login_sessions")->fetchAll() as $r) {
        try {
            $pdo->prepare("INSERT IGNORE INTO `$NEW`.sessions (id, user_id, token_hash, ip_address, user_agent, last_active_at, created_at) VALUES (?,?,?,?,?,?,?)")
                ->execute([$r['id'], $r['user_id'], $r['token_hash'], $r['ip_address'] ?? null, $r['user_agent'] ?? null, $r['last_active_at'] ?? null, $r['created_at']]);
            $n++;
        } catch (PDOException $e) { $errors[] = "sessions: " . $e->getMessage(); }
    }
    echo "  ✓ sessions: $n\n";
}

// --- audit_logs ---
if (existsIn($pdo, $OLD, 'audit_log') && cntIn($pdo, $OLD, 'audit_log') > 0) {
    $pdo->exec("INSERT IGNORE INTO `$NEW`.audit_logs SELECT * FROM `$OLD`.audit_log");
    echo "  ✓ audit_logs: " . cntIn($pdo, $OLD, 'audit_log') . "\n";
}

// =============================================
// TEKLIFLER -> TASKS (OFFER) DONUSUMU
// is_offer=1 olan policeler tasks tablosuna OFFER tipi gorev olarak aktarilir
// deadline = finish_date'e gore hesaplanir
// =============================================

if (existsIn($pdo, $OLD, 'policy')) {
    echo "\n  ── TEKLIFLER -> TASKS ──\n";
    $offers = $pdo->query("
        SELECT p.*, c.name as customer_name, i.name as ins_name, co.name as comp_name
        FROM `$OLD`.policy p
        LEFT JOIN `$OLD`.customers c ON p.customer = c.id
        LEFT JOIN `$OLD`.insurance i ON p.insurance = i.id
        LEFT JOIN `$OLD`.company co ON p.company = co.id
        WHERE p.is_offer = '1' AND p.deleted_at IS NULL
        ORDER BY p.id
    ")->fetchAll();

    $offerCount = 0;
    foreach ($offers as $o) {
        try {
            $customerName = $o['customer_name'] ?? '';
            $insName = $o['ins_name'] ?? '';
            $compName = $o['comp_name'] ?? '';
            $offerNo = $o['policy_number'] ?? '';
            $title = 'Teklif: ' . $customerName . ($offerNo ? ' - ' . $offerNo : '') . ' (' . $insName . ')';

            $finishDate = convertDate($o['finish_date'] ?? null);
            $deadline = $finishDate ? $finishDate . ' 00:00:00' : null;

            $offerData = json_encode([
                'customerId' => $o['customer'] ? (int) $o['customer'] : null,
                'customerName' => $customerName,
                'insuranceId' => $o['insurance'] ? (int) $o['insurance'] : null,
                'insuranceName' => $insName,
                'companyId' => $o['company'] ? (int) $o['company'] : null,
                'companyName' => $compName,
                'offerNumber' => $offerNo,
                'expiresAt' => $finishDate,
                'plateNo' => $o['plate'] ?? null,
                'registrationNo' => $o['license_serial_number'] ?? null,
                'vehicleBrand' => $o['brand'] ?? null,
                'vehicleModel' => $o['model'] ?? null,
                'vehicleYear' => $o['model_year'] ?? null,
                'uavtCode' => $o['uavt'] ?? null,
                'network' => $o['network'] ?? null,
                'additionalInsureds' => $o['insureds'] ?? null,
            ], JSON_UNESCAPED_UNICODE);

            $pdo->prepare("INSERT INTO `$NEW`.tasks (
                type, title, description, customer_id, status, priority, deadline, offer_data, created_by, created_at, updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
                'OFFER',
                $title,
                null,
                $o['customer'] ? (int) $o['customer'] : null,
                'PENDING',
                'MEDIUM',
                $deadline,
                $offerData,
                1, // admin
                ts($o['created_at']),
                ts($o['updated_at']),
            ]);
            $offerCount++;
        } catch (PDOException $e) { $errors[] = "offer->task #{$o['id']}: " . $e->getMessage(); }
    }
    echo "  ✓ teklifler -> tasks (OFFER): $offerCount\n";
}

// --- calendar_tasks & reminders -> tasks (OTHER) ---
if (existsIn($pdo, $OLD, 'calendar_tasks') && cntIn($pdo, $OLD, 'calendar_tasks') > 0) {
    $n = 0;
    $calTasks = $pdo->query("SELECT * FROM `$OLD`.calendar_tasks WHERE deleted_at IS NULL")->fetchAll();
    foreach ($calTasks as $ct) {
        try {
            $deadline = null;
            if (!empty($ct['date'])) {
                $deadline = $ct['date'] . ' ' . ($ct['time'] ?? '00:00:00');
            }
            $pdo->prepare("INSERT INTO `$NEW`.tasks (
                type, title, description, customer_id, policy_id, assigned_to, created_by, status, priority, deadline, created_at, updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                'OTHER',
                $ct['title'],
                $ct['description'] ?? null,
                $ct['customer_id'] ?? null,
                $ct['policy_id'] ?? null,
                $ct['user_id'] ?? null,
                $ct['user_id'] ?? 1, // created_by = assigned user or admin
                $ct['status'] === 'completed' ? 'COMPLETED' : 'PENDING',
                strtoupper($ct['priority'] ?? 'MEDIUM'),
                $deadline,
                ts($ct['created_at']),
                ts($ct['updated_at']),
            ]);
            $n++;
        } catch (PDOException $e) { $errors[] = "calendar_task #{$ct['id']}: " . $e->getMessage(); }
    }
    echo "  ✓ calendar_tasks -> tasks (OTHER): $n\n";
}

if (existsIn($pdo, $OLD, 'reminders') && cntIn($pdo, $OLD, 'reminders') > 0) {
    $n = 0;
    $reminders = $pdo->query("SELECT * FROM `$OLD`.reminders WHERE deleted_at IS NULL")->fetchAll();
    foreach ($reminders as $rm) {
        try {
            $deadline = null;
            if (!empty($rm['remind_date'])) {
                $deadline = $rm['remind_date'] . ' ' . ($rm['remind_time'] ?? '00:00:00');
            }
            $status = (!empty($rm['is_dismissed']) && $rm['is_dismissed'] == '1') ? 'COMPLETED' : 'PENDING';
            $pdo->prepare("INSERT INTO `$NEW`.tasks (
                type, title, description, customer_id, policy_id, assigned_to, created_by, status, priority, deadline, created_at, updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                'OTHER',
                $rm['title'],
                $rm['description'] ?? null,
                $rm['customer_id'] ?? null,
                $rm['policy_id'] ?? null,
                $rm['user_id'] ?? null,
                $rm['user_id'] ?? 1, // created_by = assigned user or admin
                $status,
                'MEDIUM',
                $deadline,
                ts($rm['created_at']),
                ts($rm['updated_at']),
            ]);
            $n++;
        } catch (PDOException $e) { $errors[] = "reminder #{$rm['id']}: " . $e->getMessage(); }
    }
    echo "  ✓ reminders -> tasks (OTHER): $n\n";
}

// --- Eski tasks & task_logs (dogrudan kopyala) ---
if (existsIn($pdo, $OLD, 'tasks') && cntIn($pdo, $OLD, 'tasks') > 0) {
    try {
        $srcCols = array_map(fn($r) => $r['Field'], $pdo->query("SHOW COLUMNS FROM `$OLD`.tasks")->fetchAll());
        $tgtCols = array_map(fn($r) => $r['Field'], $pdo->query("SHOW COLUMNS FROM `$NEW`.tasks")->fetchAll());
        $common = array_intersect($srcCols, $tgtCols);
        $colList = implode(', ', array_map(fn($c) => "`$c`", $common));
        $pdo->exec("INSERT IGNORE INTO `$NEW`.tasks ($colList) SELECT $colList FROM `$OLD`.tasks");
        echo "  ✓ tasks (eski): " . cntIn($pdo, $OLD, 'tasks') . "\n";
    } catch (PDOException $e) { echo "  ⚠ tasks: " . substr($e->getMessage(), 0, 80) . "\n"; }
}

if (existsIn($pdo, $OLD, 'task_logs') && cntIn($pdo, $OLD, 'task_logs') > 0) {
    try {
        $srcCols = array_map(fn($r) => $r['Field'], $pdo->query("SHOW COLUMNS FROM `$OLD`.task_logs")->fetchAll());
        $tgtCols = array_map(fn($r) => $r['Field'], $pdo->query("SHOW COLUMNS FROM `$NEW`.task_logs")->fetchAll());
        $common = array_intersect($srcCols, $tgtCols);
        $colList = implode(', ', array_map(fn($c) => "`$c`", $common));
        $pdo->exec("INSERT IGNORE INTO `$NEW`.task_logs ($colList) SELECT $colList FROM `$OLD`.task_logs");
        echo "  ✓ task_logs: " . cntIn($pdo, $OLD, 'task_logs') . "\n";
    } catch (PDOException $e) { echo "  ⚠ task_logs: " . substr($e->getMessage(), 0, 80) . "\n"; }
}

// --- Dogrudan kopyalananlar ---
$direct = ['documents','messages','message_templates','notifications'];
foreach ($direct as $t) {
    if (existsIn($pdo, $OLD, $t) && cntIn($pdo, $OLD, $t) > 0) {
        try {
            $srcCols = array_map(fn($r) => $r['Field'], $pdo->query("SHOW COLUMNS FROM `$OLD`.`$t`")->fetchAll());
            $tgtCols = array_map(fn($r) => $r['Field'], $pdo->query("SHOW COLUMNS FROM `$NEW`.`$t`")->fetchAll());
            $common = array_intersect($srcCols, $tgtCols);
            $colList = implode(', ', array_map(fn($c) => "`$c`", $common));
            $pdo->exec("INSERT IGNORE INTO `$NEW`.`$t` ($colList) SELECT $colList FROM `$OLD`.`$t`");
            echo "  ✓ $t: " . cntIn($pdo, $OLD, $t) . "\n";
        } catch (PDOException $e) { echo "  ⚠ $t: " . substr($e->getMessage(), 0, 80) . "\n"; }
    }
}

// Auto increment
foreach ($targetTables as $t) {
    if (existsIn($pdo, $NEW, $t)) {
        try { $max = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM `$NEW`.`$t`")->fetchColumn();
            if ($max > 0) $pdo->exec("ALTER TABLE `$NEW`.`$t` AUTO_INCREMENT = " . ($max + 1));
        } catch (PDOException $e) {}
    }
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// =============================================
// DOGRULAMA
// =============================================

echo "\n── DOGRULAMA ──\n";
$checks = [
    ['city','cities'], ['district','districts'], ['branch','branches'],
    ['country','countries'], ['company','companies'], ['insurance','insurance_types'], ['users','users'],
    ['customers','customers'], ['options','settings'],
];
foreach ($checks as [$o,$n]) {
    if (existsIn($pdo, $OLD, $o)) {
        $oc = cntIn($pdo, $OLD, $o); $nc = cntIn($pdo, $NEW, $n);
        echo "  " . ($oc === $nc ? '✓' : '⚠') . " $OLD.$o ($oc) -> $NEW.$n ($nc)\n";
    }
}
// Policy ozel kontrol
if (existsIn($pdo, $OLD, 'policy')) {
    $oldPolicies = (int) $pdo->query("SELECT COUNT(*) FROM `$OLD`.policy WHERE is_offer='0'")->fetchColumn();
    $newPolicies = cntIn($pdo, $NEW, 'policies');
    echo "  " . ($oldPolicies === $newPolicies ? '✓' : '⚠') . " policy is_offer=0 ($oldPolicies) -> policies ($newPolicies)\n";
    $oldOffers = (int) $pdo->query("SELECT COUNT(*) FROM `$OLD`.policy WHERE is_offer='1'")->fetchColumn();
    echo "  ○ policy is_offer=1 ($oldOffers) -> tasks (OFFER) olarak aktarildi\n";
}

echo "\n  Hata: " . count($errors) . "\n";
if (!empty($errors)) foreach (array_slice($errors, -5) as $e) echo "    ✗ " . substr($e, 0, 120) . "\n";

// =============================================
// REFERANS
// =============================================

echo "\n══ KOLON ESLESTIRME ══

POLICIES (policy -> policies):
  YENI:    parent_id (ana policeye baglanti)
  prod -> production_type (SELF/INCOMING/OUTGOING)
  customer -> customer_id
  insurance -> insurance_type_id
  company -> company_id
  branch -> branch_id
  policy_number -> policy_no (base numara, zeyil endorsement_no ile ayrisir)
  insured -> insured_name
  issue_date -> issued_at (VARCHAR->DATE)
  start_date -> starts_at (VARCHAR->DATE)
  finish_date -> expires_at (VARCHAR->DATE)
  gross_amount -> gross_premium (VARCHAR->DECIMAL)
  net_amount -> net_premium (VARCHAR->DECIMAL)
  company_commission -> company_comm_rate
  branch_commission -> branch_comm_rate
  consensus -> is_approved
  is_cancel -> is_cancelled
  zeyil_number -> endorsement_no
  last_status -> (removed, now handled via tasks)
  plate -> plate_no
  chassis_number -> chassis_no
  engine_number -> engine_no
  license_serial_number -> registration_no
  brand -> vehicle_brand
  model -> vehicle_model
  model_year -> vehicle_year
  uavt -> uavt_code
  dask_policy_number -> dask_no
  insureds -> additional_insureds
  is_offer=1 -> tasks (type=OFFER, offer_data JSON)
  KALDIRILDI: offer_number, ref

CUSTOMERS:
  type -> customer_type
  identity_number -> identity_no
  primary_phone -> phone
  secondary_phone -> phone_alt
  authorized_full_name -> contact_person
  martial_status -> marital_status
  number_of_children_employees -> dependents_count
  company_name_or_sector -> sector
  city -> city_id, district -> district_id
  country -> country_id
  customer_category_id -> KALDIRILDI (prim araligina gore otomatik)

USERS: full_name->name, status->is_active, type->role, branch->branch_id
BRANCHES: phone_number->phone, commission->commission_rate
INSURANCE_TYPES: key->code, commission->default_comm_rate, commission2->extra_comm_rate
  type->level, parent->parent_id, status->is_active, chart_status->show_in_charts
  group->branch_group, allianz_id->external_code, reminder_days->renewal_days
SETTINGS (options->settings): meta_key->key, meta_value->value
SESSIONS (login_sessions->sessions)
AUDIT_LOGS (audit_log->audit_logs)
KALDIRILDI: calendar_tasks, reminders, activity_log
";
