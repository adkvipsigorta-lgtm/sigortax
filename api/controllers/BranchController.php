<?php

class BranchController
{
    public function index(array $user, array $query): void
    {
        AuthMiddleware::requireAdmin($user);

        $where = ["deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $where[] = "(name LIKE ? OR phone LIKE ?)";
            $s = '%' . $query['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = implode(' AND ', $where);

        // Full list for dropdowns (varsayılan: sadece aktif)
        if (!empty($query['all'])) {
            if (empty($query['includeInactive'])) {
                $where[] = "is_active = 1";
            }
            $whereSql = implode(' AND ', $where);
            $branches = Database::fetchAll(
                "SELECT * FROM branches WHERE $whereSql ORDER BY name ASC",
                $params
            );
            Response::success(array_map([$this, 'format'], $branches));
            return;
        }

        $allowedSorts = ['name', 'commission_rate', 'phone', 'created_at'];
        $sort = in_array($query['sort'] ?? '', $allowedSorts) ? $query['sort'] : 'name';
        $order = ($query['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = min(100, max(1, (int)($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT * FROM branches WHERE $whereSql ORDER BY is_active DESC, $sort $order";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    private function format(array $b): array
    {
        $aliases = [];
        if (!empty($b['aliases'])) {
            $decoded = json_decode($b['aliases'], true);
            if (is_array($decoded)) $aliases = $decoded;
        }
        return [
            'id' => (int) $b['id'],
            'name' => $b['name'],
            'phone' => $b['phone'],
            'commissionRate' => (float) $b['commission_rate'],
            'iban' => $b['iban'],
            'isActive' => (bool) ($b['is_active'] ?? 1),
            'aliases' => $aliases,
            'createdAt' => $b['created_at'] ?? null,
        ];
    }

    public function show(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);
        $branch = Database::fetch(
            "SELECT * FROM branches WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$branch) {
            Response::error('Acente bulunamadi', 404);
        }

        $aliases = [];
        if (!empty($branch['aliases'])) {
            $decoded = json_decode($branch['aliases'], true);
            if (is_array($decoded)) $aliases = $decoded;
        }

        // Genel istatistikler
        $stats = Database::fetch(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_cancelled = 0 AND expires_at >= CURDATE() THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_cancelled = 1 THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN is_cancelled = 0 AND expires_at < CURDATE() THEN 1 ELSE 0 END) as expired,
                COALESCE(SUM(CASE WHEN is_cancelled = 0 THEN gross_premium ELSE 0 END), 0) as total_gross,
                COALESCE(SUM(CASE WHEN is_cancelled = 0 THEN net_premium ELSE 0 END), 0) as total_net
             FROM policies WHERE branch_id = ? AND deleted_at IS NULL",
            [$id]
        );

        // Aktif prim
        $activePremium = Database::fetch(
            "SELECT COALESCE(SUM(gross_premium), 0) as gross
             FROM policies WHERE branch_id = ? AND deleted_at IS NULL AND is_cancelled = 0 AND expires_at >= CURDATE()",
            [$id]
        );

        // İlk poliçe tarihi (çalışmaya başlama)
        $firstPolicy = Database::fetch(
            "SELECT MIN(issued_at) as first_date FROM policies WHERE branch_id = ? AND deleted_at IS NULL",
            [$id]
        );

        // Ürün kırılımı (komisyon ve hak ediş dahil)
        $byProductRaw = Database::fetchAll(
            "SELECT i.name, i.color, COUNT(*) as cnt,
                    SUM(p.gross_premium) as gross, SUM(p.net_premium) as net,
                    SUM(COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100)) as commission,
                    SUM(COALESCE(p.branch_comm_amount, COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100) * p.branch_comm_rate / 100)) as branch_comm
             FROM policies p
             INNER JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE p.branch_id = ? AND p.deleted_at IS NULL
             GROUP BY i.id, i.name, i.color
             ORDER BY gross DESC",
            [$id]
        );

        $branchCommRate = (float) $branch['commission_rate'];

        // Tali Giden/Gelen ayrımı için production_type bazlı komisyon
        $byProductDetailed = Database::fetchAll(
            "SELECT i.name, i.color, p.production_type,
                    COUNT(*) as cnt,
                    SUM(p.gross_premium) as gross, SUM(p.net_premium) as net,
                    SUM(COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100)) as commission,
                    SUM(p.branch_comm_rate) as sum_branch_rate,
                    COUNT(p.branch_comm_rate) as cnt_branch_rate
             FROM policies p
             INNER JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE p.branch_id = ? AND p.deleted_at IS NULL
             GROUP BY i.id, i.name, i.color, p.production_type
             ORDER BY gross DESC",
            [$id]
        );

        // Ürün bazlı birleştir ve hak ediş hesapla
        $productMap = [];
        foreach ($byProductDetailed as $row) {
            $name = $row['name'];
            if (!isset($productMap[$name])) {
                $productMap[$name] = ['name' => $name, 'color' => $row['color'], 'count' => 0, 'gross' => 0, 'net' => 0, 'commission' => 0, 'earning' => 0];
            }
            $cnt = (int) $row['cnt'];
            $commission = (float) $row['commission'];
            $productMap[$name]['count'] += $cnt;
            $productMap[$name]['gross'] += (float) $row['gross'];
            $productMap[$name]['net'] += (float) $row['net'];
            $productMap[$name]['commission'] += $commission;

            // Hak ediş hesabı
            if ($row['production_type'] === 'OUTGOING') {
                // Tali Giden: komisyonun %oran'ı senin
                $productMap[$name]['earning'] += $commission * $branchCommRate / 100;
            } else {
                // Tali Gelen: komisyonun (100 - %oran)'ı senin
                $avgRate = $cnt > 0 ? (float) $row['sum_branch_rate'] / $cnt : $branchCommRate;
                $productMap[$name]['earning'] += $commission * (100 - $avgRate) / 100;
            }
        }

        $byProduct = array_values(array_map(function ($p) {
            return [
                'name' => $p['name'],
                'color' => $p['color'],
                'count' => $p['count'],
                'gross' => round($p['gross'], 2),
                'net' => round($p['net'], 2),
                'commission' => round($p['commission'], 2),
                'earning' => round($p['earning'], 2),
            ];
        }, $productMap));

        // Brüt prime göre sırala
        usort($byProduct, fn($a, $b) => $b['gross'] <=> $a['gross']);

        // Aylık üretim (son 12 ay, komisyon + hak ediş dahil)
        $monthly = Database::fetchAll(
            "SELECT DATE_FORMAT(p.issued_at, '%Y-%m') as month,
                    COUNT(*) as cnt, SUM(p.gross_premium) as gross, SUM(p.net_premium) as net,
                    SUM(COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100)) as commission,
                    SUM(CASE
                        WHEN p.production_type = 'OUTGOING' THEN COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100) * ? / 100
                        ELSE COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100) * (100 - COALESCE(p.branch_comm_rate, ?)) / 100
                    END) as earning
             FROM policies p
             WHERE p.branch_id = ? AND p.deleted_at IS NULL
               AND p.issued_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
             GROUP BY month ORDER BY month ASC",
            [$branchCommRate, $branchCommRate, $id]
        );

        // Yıllık üretim (son 5 yıl, komisyon + hak ediş dahil)
        $yearly = Database::fetchAll(
            "SELECT YEAR(p.issued_at) as year,
                    COUNT(*) as cnt, SUM(p.gross_premium) as gross, SUM(p.net_premium) as net,
                    SUM(COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100)) as commission,
                    SUM(CASE
                        WHEN p.production_type = 'OUTGOING' THEN COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100) * ? / 100
                        ELSE COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100) * (100 - COALESCE(p.branch_comm_rate, ?)) / 100
                    END) as earning
             FROM policies p
             WHERE p.branch_id = ? AND p.deleted_at IS NULL
               AND YEAR(p.issued_at) >= YEAR(CURDATE()) - 4
             GROUP BY year ORDER BY year DESC",
            [$branchCommRate, $branchCommRate, $id]
        );

        // recentPolicies kaldırıldı — ayrı endpoint: GET /branches/{id}/policies?month=X&year=Y&dateType=issued_at

        $aylar = ['', 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

        Response::success([
            'id' => (int) $branch['id'],
            'name' => $branch['name'],
            'phone' => $branch['phone'],
            'commissionRate' => (float) $branch['commission_rate'],
            'iban' => $branch['iban'],
            'isActive' => (bool) ($branch['is_active'] ?? 1),
            'aliases' => $aliases,
            'firstPolicyDate' => $firstPolicy['first_date'] ?? null,
            'stats' => [
                'total' => (int) $stats['total'],
                'active' => (int) $stats['active'],
                'cancelled' => (int) $stats['cancelled'],
                'expired' => (int) $stats['expired'],
                'totalGross' => round((float) $stats['total_gross'], 2),
                'totalNet' => round((float) $stats['total_net'], 2),
                'activeGross' => round((float) $activePremium['gross'], 2),
            ],
            'byProduct' => $byProduct,
            'monthly' => array_map(fn($m) => [
                'month' => $m['month'],
                'label' => $aylar[(int) substr($m['month'], 5, 2)] . ' ' . substr($m['month'], 0, 4),
                'count' => (int) $m['cnt'],
                'gross' => round((float) $m['gross'], 2),
                'net' => round((float) $m['net'], 2),
                'commission' => round((float) $m['commission'], 2),
                'earning' => round((float) $m['earning'], 2),
            ], $monthly),
            'yearly' => array_map(fn($y) => [
                'year' => (int) $y['year'],
                'count' => (int) $y['cnt'],
                'gross' => round((float) $y['gross'], 2),
                'net' => round((float) $y['net'], 2),
                'commission' => round((float) $y['commission'], 2),
                'earning' => round((float) $y['earning'], 2),
            ], $yearly),
        ]);
    }

    /**
     * Acente aylık poliçe listesi.
     * GET /branches/{id}/policies?month=9&year=2026&dateType=issued_at
     */
    public function monthlyPolicies(array $user, int $id, array $query): void
    {
        AuthMiddleware::requireAdmin($user);

        $year = (int) ($query['year'] ?? date('Y'));
        $month = (int) ($query['month'] ?? date('n'));
        $dateType = ($query['dateType'] ?? 'issued_at') === 'starts_at' ? 'starts_at' : 'issued_at';

        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $policies = Database::fetchAll(
            "SELECT p.id, p.policy_no, p.endorsement_no, p.gross_premium, p.net_premium,
                    p.is_cancelled, p.issued_at, p.starts_at, p.expires_at, p.plate_no,
                    p.production_type, p.company_comm_rate, p.company_comm_amount,
                    p.branch_comm_rate, p.branch_comm_amount,
                    cu.name as customer_name, i.name as insurance_name, i.color as insurance_color,
                    co.name as company_name
             FROM policies p
             LEFT JOIN customers cu ON p.customer_id = cu.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN companies co ON p.company_id = co.id
             WHERE p.branch_id = ? AND p.deleted_at IS NULL
               AND p.$dateType BETWEEN ? AND ?
             ORDER BY p.$dateType DESC, p.id DESC",
            [$id, $firstDay, $lastDay]
        );

        $branch = Database::fetch("SELECT commission_rate FROM branches WHERE id = ? AND deleted_at IS NULL", [$id]);
        $branchCommRate = $branch ? (float) $branch['commission_rate'] : 0;

        $totalGross = 0;
        $totalNet = 0;
        $totalCommission = 0;
        $totalEarning = 0;

        $result = [];
        foreach ($policies as $p) {
            $commission = (isset($p['company_comm_amount']) && $p['company_comm_amount'] !== null)
                ? (float) $p['company_comm_amount']
                : (float) $p['net_premium'] * (float) $p['company_comm_rate'] / 100;

            if ($p['production_type'] === 'OUTGOING') {
                $earning = $commission * $branchCommRate / 100;
            } else {
                $rate = (float) ($p['branch_comm_rate'] ?: $branchCommRate);
                $earning = $commission * (100 - $rate) / 100;
            }

            $totalGross += (float) $p['gross_premium'];
            $totalNet += (float) $p['net_premium'];
            $totalCommission += $commission;
            $totalEarning += $earning;

            $result[] = [
                'id' => (int) $p['id'],
                'policyNo' => $p['policy_no'],
                'endorsementNo' => (int) $p['endorsement_no'],
                'customerName' => $p['customer_name'] ?? '',
                'insuranceName' => $p['insurance_name'] ?? '',
                'insuranceColor' => $p['insurance_color'] ?? null,
                'companyName' => $p['company_name'] ?? '',
                'grossPremium' => (float) $p['gross_premium'],
                'netPremium' => (float) $p['net_premium'],
                'commission' => round($commission, 2),
                'earning' => round($earning, 2),
                'isCancelled' => (bool) $p['is_cancelled'],
                'issuedAt' => $p['issued_at'],
                'startsAt' => $p['starts_at'],
                'expiresAt' => $p['expires_at'],
                'plateNo' => $p['plate_no'] ?? '',
                'productionType' => $p['production_type'],
            ];
        }

        Response::success([
            'policies' => $result,
            'totals' => [
                'count' => count($result),
                'gross' => round($totalGross, 2),
                'net' => round($totalNet, 2),
                'commission' => round($totalCommission, 2),
                'earning' => round($totalEarning, 2),
            ],
        ]);
    }

    /**
     * Performans Analiz Raporu (şablon bazlı, API gerektirmez).
     * GET /branches/{id}/ai-analysis
     */
    public function aiAnalysis(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);

        $branch = Database::fetch("SELECT * FROM branches WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$branch) Response::error('Acente bulunamadı', 404);

        $commRate = (float) $branch['commission_rate'];
        $fmt = fn($v) => number_format(round((float) $v, 2), 2, ',', '.');

        // Genel istatistikler
        $stats = Database::fetch(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN is_cancelled = 0 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_cancelled = 1 THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN is_cancelled = 0 THEN gross_premium ELSE 0 END) as total_gross,
                    SUM(COALESCE(company_comm_amount, net_premium * company_comm_rate / 100)) as total_commission
             FROM policies WHERE branch_id = ? AND deleted_at IS NULL", [$id]
        );

        $total = (int) $stats['total'];
        $active = (int) $stats['active'];
        $cancelled = (int) $stats['cancelled'];
        $totalCommission = (float) $stats['total_commission'];
        $cancelRate = $total > 0 ? round($cancelled / $total * 100, 1) : 0;

        // Tali Giden
        $outgoing = Database::fetch(
            "SELECT COUNT(*) as cnt,
                    SUM(COALESCE(company_comm_amount, net_premium * company_comm_rate / 100)) as commission
             FROM policies WHERE branch_id = ? AND deleted_at IS NULL AND production_type = 'OUTGOING'", [$id]
        );
        $outCommission = (float) ($outgoing['commission'] ?? 0);
        $outEarning = $outCommission * $commRate / 100;
        $outLost = $outCommission - $outEarning;

        // Şirket bazlı kayıp (Tali Giden)
        $byCompany = Database::fetchAll(
            "SELECT co.name, COUNT(*) as cnt,
                    SUM(COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100)) as commission
             FROM policies p
             LEFT JOIN companies co ON p.company_id = co.id
             WHERE p.branch_id = ? AND p.deleted_at IS NULL AND p.production_type = 'OUTGOING'
             GROUP BY co.id, co.name ORDER BY commission DESC LIMIT 5", [$id]
        );

        // İptal kayıp
        $cancelLoss = Database::fetch(
            "SELECT COUNT(*) as cnt,
                    SUM(ABS(COALESCE(company_comm_amount, net_premium * company_comm_rate / 100))) as commission
             FROM policies WHERE branch_id = ? AND deleted_at IS NULL AND is_cancelled = 1", [$id]
        );
        $cancelLossAmount = (float) ($cancelLoss['commission'] ?? 0);

        // Aylık trend (son 3 ay)
        $trend = Database::fetchAll(
            "SELECT DATE_FORMAT(issued_at, '%Y-%m') as month, COUNT(*) as cnt, SUM(gross_premium) as gross
             FROM policies WHERE branch_id = ? AND deleted_at IS NULL
               AND issued_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
             GROUP BY month ORDER BY month ASC", [$id]
        );

        // İlk poliçe tarihi
        $firstDate = Database::fetch("SELECT MIN(issued_at) as d FROM policies WHERE branch_id = ? AND deleted_at IS NULL", [$id]);
        $startDate = $firstDate['d'] ?? null;
        $startLabel = $startDate ? date('d.m.Y', strtotime($startDate)) : '?';

        // En büyük şirket bağımlılığı
        $topCompany = $byCompany[0] ?? null;
        $topCompanyPct = ($topCompany && $total > 0) ? round((int) $topCompany['cnt'] / $total * 100, 1) : 0;

        // Trend analizi
        $trendDirection = '';
        if (count($trend) >= 2) {
            $last = (int) $trend[count($trend) - 1]['cnt'];
            $prev = (int) $trend[count($trend) - 2]['cnt'];
            if ($last > $prev) $trendDirection = 'artış';
            elseif ($last < $prev) $trendDirection = 'düşüş';
            else $trendDirection = 'stabil';
        }

        // --- Rapor oluştur ---
        $lines = [];

        // 1. Özet
        $lines[] = "**Özet**";
        $lines[] = "{$branch['name']} ile " . ($startDate ? $startLabel . " tarihinden bu yana" : "")
            . " toplam {$total} poliçe üzerinden çalışılmaktadır."
            . " Komisyon oranı %{$commRate} olup, toplam {$fmt($totalCommission)} TL komisyon üretilmiştir."
            . " Bunun {$fmt($outEarning)} TL'si hak edişiniz, {$fmt($outLost)} TL'si acenteye bırakılan tutardır.";
        $lines[] = "";

        // 2. Maliyet Analizi
        $lines[] = "**Tali Acente Maliyet Analizi**";
        $lines[] = "Bu acenteye toplam {$fmt($outLost)} TL bırakılmıştır."
            . " Kendi acenteliğinizden kesmiş olsaydınız komisyonun tamamı ({$fmt($outCommission)} TL) size kalacaktı."
            . " Aradaki fark: {$fmt($outLost)} TL.";
        $lines[] = "";

        if (!empty($byCompany)) {
            $lines[] = "**Şirket bazlı bırakılan tutarlar:**";
            foreach ($byCompany as $c) {
                $comm = (float) $c['commission'];
                $lost = $comm * (100 - $commRate) / 100;
                $lines[] = "• {$c['name']}: {$c['cnt']} poliçe — {$fmt($lost)} TL bırakıldı";
            }
            $lines[] = "";
        }

        // 3. Acentelik Değerlendirmesi
        $lines[] = "**Acentelik Değerlendirmesi**";
        if ($topCompany) {
            $topLost = (float) $topCompany['commission'] * (100 - $commRate) / 100;
            $lines[] = "En çok poliçe kesilen şirket {$topCompany['name']} ({$topCompany['cnt']} poliçe)."
                . " Bu şirkete yıllık {$fmt($topLost)} TL bırakıyorsunuz."
                . " Doğrudan acentelik almanız durumunda bu tutar kârınıza yansır.";
            $lines[] = "Ancak acentelik maliyetlerini (teminat mektubu, ek personel, yıllık üretim hedefi, sistem altyapısı) göz önünde bulundurunuz."
                . " Yıllık bırakılan tutar bu maliyetleri karşılıyorsa acentelik almak mantıklı olabilir.";
        }
        $lines[] = "";

        // 4. Risk
        $lines[] = "**Risk ve Dikkat Edilmesi Gerekenler**";
        $risks = [];
        if ($cancelRate > 10) {
            $risks[] = "İptal oranı %{$cancelRate} — sektör ortalamasının üzerinde. {$cancelled} iptal poliçeden {$fmt($cancelLossAmount)} TL komisyon kaybı oluşmuştur.";
        } elseif ($cancelRate > 5) {
            $risks[] = "İptal oranı %{$cancelRate} — kabul edilebilir seviyede ancak takip edilmeli.";
        } else {
            $risks[] = "İptal oranı %{$cancelRate} — düşük seviyede, olumlu.";
        }
        if ($topCompanyPct > 60) {
            $risks[] = "Üretimin %{$topCompanyPct}'i tek şirketten ({$topCompany['name']}) geliyor. Şirket bağımlılığı riski mevcut.";
        }
        if ($trendDirection === 'düşüş') {
            $risks[] = "Son aylarda üretim trendi düşüş yönünde.";
        } elseif ($trendDirection === 'artış') {
            $risks[] = "Son aylarda üretim trendi artış yönünde — olumlu.";
        }
        if (empty($risks)) $risks[] = "Belirgin bir risk tespit edilmedi.";
        foreach ($risks as $r) $lines[] = "• " . $r;
        $lines[] = "";

        // 5. Öneri
        $lines[] = "**Öneriler**";
        if ($topCompany && $topLost > 50000) {
            $lines[] = "• {$topCompany['name']} için doğrudan acentelik başvurusu değerlendirilmeli — yıllık {$fmt($topLost)} TL tasarruf potansiyeli.";
        }
        if ($cancelRate > 10) {
            $lines[] = "• İptal oranını düşürmek için müşteri memnuniyeti ve yenileme takibi güçlendirilmeli.";
        }
        if ($topCompanyPct > 60) {
            $lines[] = "• Tek şirkete bağımlılığı azaltmak için diğer şirketlerle üretim çeşitlendirilmeli.";
        }
        if ($trendDirection === 'düşüş') {
            $lines[] = "• Üretim düşüşünün nedenleri araştırılmalı ve bu acente ile iletişim güçlendirilmeli.";
        }
        $lines[] = "• Komisyon oranı (%{$commRate}) periyodik olarak gözden geçirilmeli.";

        $analysis = implode("\n", $lines);

        Response::success([
            'analysis' => $analysis,
            'summary' => [
                'totalPolicies' => $total,
                'totalCommission' => round($totalCommission, 2),
                'earning' => round($outEarning, 2),
                'lostToAgent' => round($outLost, 2),
                'cancelLoss' => round($cancelLossAmount, 2),
            ],
        ]);
    }

    public function store(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $validator = new Validator();
        if (!$validator->validate($input, [
            'name' => 'required|min:2',
            'commissionRate' => 'required|numeric',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $aliases = isset($input['aliases']) && is_array($input['aliases'])
            ? array_values(array_filter(array_map('trim', $input['aliases']), fn($v) => $v !== ''))
            : [];

        $id = Database::insert('branches', [
            'name' => $input['name'],
            'phone' => $input['phone'] ?? null,
            'commission_rate' => $input['commissionRate'],
            'iban' => $input['iban'] ?? null,
            'aliases' => !empty($aliases) ? json_encode($aliases, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Acente olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $existing = Database::fetch("SELECT id FROM branches WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Acente bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($input['name'])) $data['name'] = $input['name'];
        if (isset($input['phone'])) $data['phone'] = $input['phone'];
        if (isset($input['commissionRate'])) $data['commission_rate'] = $input['commissionRate'];
        if (isset($input['iban'])) $data['iban'] = $input['iban'];
        if (isset($input['isActive'])) $data['is_active'] = $input['isActive'] ? 1 : 0;
        if (array_key_exists('aliases', $input)) {
            $aliases = is_array($input['aliases']) ? array_values(array_filter(array_map('trim', $input['aliases']), fn($v) => $v !== '')) : [];
            $data['aliases'] = !empty($aliases) ? json_encode($aliases, JSON_UNESCAPED_UNICODE) : null;
        }

        Database::update('branches', $data, 'id = ?', [$id]);
        Response::success(null, 'Acente guncellendi');
    }

    /**
     * Mutabakat: GET /branches/reconciliation?branchId=X&year=2026&month=1&dateType=issued_at
     */
    public function reconciliation(array $user, array $query): void
    {
        AuthMiddleware::denyAgent($user);
        $branchId = (int) ($query['branchId'] ?? 0);
        $year = (int) ($query['year'] ?? date('Y'));
        $month = (int) ($query['month'] ?? date('n'));
        $dateType = ($query['dateType'] ?? 'issued_at') === 'starts_at' ? 'starts_at' : 'issued_at';

        if (!$branchId) {
            $this->allBranchesReconciliation($user, $year, $month, $dateType);
            return;
        }

        $branch = Database::fetch("SELECT * FROM branches WHERE id = ? AND deleted_at IS NULL", [$branchId]);
        if (!$branch) {
            Response::error('Acente bulunamadi', 404);
        }

        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $dateFilter = "p.$dateType BETWEEN '$firstDay' AND '$lastDay'";

        $sql = "SELECT p.*,
                    cu.name as customer_name,
                    i.name as insurance_name,
                    co.name as company_name
                FROM policies p
                LEFT JOIN customers cu ON p.customer_id = cu.id
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN companies co ON p.company_id = co.id
                WHERE p.branch_id = ?
                  AND p.deleted_at IS NULL
                  AND $dateFilter
                  AND p.production_type IN ('INCOMING','OUTGOING')
                ORDER BY p.$dateType DESC";

        $policies = Database::fetchAll($sql, [$branchId]);

        $totalGross = 0;
        $totalNet = 0;
        $totalCommission = 0;
        $totalPolicies = 0;
        $totalCancelled = 0;

        $result = [];
        foreach ($policies as $p) {
            $netAmount = (float) $p['net_premium'];
            $grossAmount = (float) $p['gross_premium'];
            $companyCommRate = (float) ($p['company_comm_rate'] ?? 0);
            $branchCommRate = (float) ($p['branch_comm_rate'] ?? 0);

            // Kayitli komisyon tutari varsa onu kullan, yoksa orandan hesapla
            $companyCommAmount = (isset($p['company_comm_amount']) && $p['company_comm_amount'] !== null)
                ? (float) $p['company_comm_amount']
                : $netAmount * $companyCommRate / 100;
            $branchCommAmount = (isset($p['branch_comm_amount']) && $p['branch_comm_amount'] !== null)
                ? (float) $p['branch_comm_amount']
                : $companyCommAmount * $branchCommRate / 100;

            // Acente/tali acenteye odenicek tutar: her iki uretim turunde de branchCommAmount
            $commission = $branchCommAmount;

            $totalGross += $grossAmount;
            $totalNet += $netAmount;
            $totalCommission += $commission;
            $totalPolicies++;
            if ($p['is_cancelled'] == '1') $totalCancelled++;

            $result[] = [
                'id' => (int) $p['id'],
                'policyNo' => $p['policy_no'],
                'endorsementNo' => (int) $p['endorsement_no'],
                'isCancelled' => (bool) $p['is_cancelled'],
                'productionType' => $p['production_type'],
                'customerName' => $p['customer_name'] ?? '',
                'insuranceName' => $p['insurance_name'] ?? '',
                'companyName' => $p['company_name'] ?? '',
                'issuedAt' => $p['issued_at'],
                'startsAt' => $p['starts_at'],
                'expiresAt' => $p['expires_at'],
                'grossPremium' => $grossAmount,
                'netPremium' => $netAmount,
                'companyCommRate' => $companyCommRate,
                'branchCommRate' => $branchCommRate,
                'commission' => round($commission, 2),
                'plateNo' => $p['plate_no'] ?? null,
                'reconciliationStatus' => $p['reconciliation_status'] ?? 'PENDING',
            ];
        }

        // Donem kilit durumu: tum policeler RECONCILED mi?
        $isLocked = !empty($result) && count(array_filter($result, fn($r) => ($r['reconciliationStatus'] ?? 'PENDING') === 'RECONCILED')) === count($result);

        Response::success([
            'branch' => [
                'id' => (int) $branch['id'],
                'name' => $branch['name'],
                'commissionRate' => (float) $branch['commission_rate'],
            ],
            'isLocked' => $isLocked,
            'stats' => [
                'totalPolicies' => $totalPolicies,
                'totalCancelled' => $totalCancelled,
                'totalGross' => round($totalGross, 2),
                'totalNet' => round($totalNet, 2),
                'totalCommission' => round($totalCommission, 2),
                'commissionRate' => (float) $branch['commission_rate'],
            ],
            'policies' => $result,
        ]);
    }

    /**
     * Excel/CSV export: GET /branches/reconciliation-export?branchId=X&year=2026&month=1&dateType=issued_at
     */
    public function reconciliationExport(array $user, array $query): void
    {
        AuthMiddleware::denyAgent($user);
        $branchId = (int) ($query['branchId'] ?? 0);
        $year = (int) ($query['year'] ?? date('Y'));
        $month = (int) ($query['month'] ?? date('n'));
        $dateType = ($query['dateType'] ?? 'issued_at') === 'starts_at' ? 'starts_at' : 'issued_at';

        if (!$branchId) {
            Response::error('Acente secilmedi', 400);
        }

        $branch = Database::fetch("SELECT * FROM branches WHERE id = ? AND deleted_at IS NULL", [$branchId]);
        if (!$branch) {
            Response::error('Acente bulunamadi', 404);
        }

        $months = ['', 'Ocak', 'Subat', 'Mart', 'Nisan', 'Mayis', 'Haziran', 'Temmuz', 'Agustos', 'Eylul', 'Ekim', 'Kasim', 'Aralik'];

        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $dateFilter = "p.$dateType BETWEEN '$firstDay' AND '$lastDay'";

        $sql = "SELECT p.*,
                    cu.name as customer_name,
                    i.name as insurance_name,
                    co.name as company_name
                FROM policies p
                LEFT JOIN customers cu ON p.customer_id = cu.id
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN companies co ON p.company_id = co.id
                WHERE p.branch_id = ?
                  AND p.deleted_at IS NULL
                  AND $dateFilter
                  AND p.production_type IN ('INCOMING','OUTGOING')
                ORDER BY p.$dateType DESC";

        $policies = Database::fetchAll($sql, [$branchId]);

        $totalGross = 0;
        $totalNet = 0;
        $totalCommission = 0;

        $rows = [];
        foreach ($policies as $p) {
            $netAmount = (float) $p['net_premium'];
            $grossAmount = (float) $p['gross_premium'];
            $companyCommRate = (float) ($p['company_comm_rate'] ?? 0);
            $branchCommRate = (float) ($p['branch_comm_rate'] ?? 0);

            // Kayitli komisyon tutari varsa onu kullan, yoksa orandan hesapla
            $companyCommAmount = (isset($p['company_comm_amount']) && $p['company_comm_amount'] !== null)
                ? (float) $p['company_comm_amount']
                : $netAmount * $companyCommRate / 100;
            $branchCommAmount = (isset($p['branch_comm_amount']) && $p['branch_comm_amount'] !== null)
                ? (float) $p['branch_comm_amount']
                : $companyCommAmount * $branchCommRate / 100;

            // Acente/tali acenteye odenicek tutar: her iki uretim turunde de branchCommAmount
            $commission = $branchCommAmount;

            $totalGross += $grossAmount;
            $totalNet += $netAmount;
            $totalCommission += $commission;

            $status = 'Aktif';
            if ($p['is_cancelled'] == '1') $status = 'Iptal';
            elseif ((int) $p['endorsement_no'] > 1) $status = 'Zeyil';

            $prod = $p['production_type'] === 'INCOMING' ? 'Gelen' : 'Giden';

            $rows[] = [
                'policy_no' => $p['policy_no'] . '/' . $p['endorsement_no'],
                'company_name' => $p['company_name'] ?? '',
                'insurance_name' => $p['insurance_name'] ?? '',
                'customer_name' => $p['customer_name'] ?? '',
                'issued_at' => $p['issued_at'],
                'starts_at' => $p['starts_at'],
                'gross_premium' => $grossAmount,
                'net_premium' => $netAmount,
                'commission' => round($commission, 2),
                'prod' => $prod,
                'status' => $status,
            ];
        }

        // Ozet satiri ekle
        $rows[] = [
            'policy_no' => '',
            'company_name' => '',
            'insurance_name' => '',
            'customer_name' => 'TOPLAM',
            'issued_at' => '',
            'starts_at' => '',
            'gross_premium' => round($totalGross, 2),
            'net_premium' => round($totalNet, 2),
            'commission' => round($totalCommission, 2),
            'prod' => '',
            'status' => '',
        ];

        $columns = [
            ['key' => 'policy_no', 'label' => 'Police No', 'type' => Response::COL_IDENTIFIER],
            ['key' => 'company_name', 'label' => 'Sirket'],
            ['key' => 'insurance_name', 'label' => 'Alt Urun'],
            ['key' => 'customer_name', 'label' => 'Musteri'],
            ['key' => 'issued_at', 'label' => 'Tanzim Tarihi', 'type' => Response::COL_DATE],
            ['key' => 'starts_at', 'label' => 'Baslangic Tarihi', 'type' => Response::COL_DATE],
            ['key' => 'gross_premium', 'label' => 'Brut Prim', 'type' => Response::COL_CURRENCY],
            ['key' => 'net_premium', 'label' => 'Net Prim', 'type' => Response::COL_CURRENCY],
            ['key' => 'commission', 'label' => 'Komisyon', 'type' => Response::COL_CURRENCY],
            ['key' => 'prod', 'label' => 'Tur'],
            ['key' => 'status', 'label' => 'Durum'],
        ];

        $filename = 'mutabakat_' . $branch['name'] . '_' . $months[$month] . '_' . $year . '.xlsx';
        $filename = str_replace(' ', '_', $filename);

        Response::xlsx($rows, $columns, $filename);
    }

    private function allBranchesReconciliation(array $user, int $year, int $month, string $dateType): void
    {
        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $sql = "SELECT
                    b.id as branch_id,
                    b.name as branch_name,
                    COUNT(p.id) as total_policies,
                    SUM(CASE WHEN p.is_cancelled = 1 THEN 1 ELSE 0 END) as total_cancelled,
                    SUM(p.gross_premium) as total_gross,
                    SUM(p.net_premium) as total_net
                FROM branches b
                INNER JOIN policies p ON p.branch_id = b.id
                    AND p.deleted_at IS NULL
                    AND p.$dateType BETWEEN ? AND ?
                    AND p.production_type IN ('INCOMING','OUTGOING')
                WHERE b.deleted_at IS NULL
                GROUP BY b.id, b.name
                ORDER BY b.name ASC";

        $rows = Database::fetchAll($sql, [$firstDay, $lastDay]);

        $result = [];
        $grandTotalPolicies = 0;
        $grandTotalGross = 0;
        $grandTotalNet = 0;
        $grandTotalCommission = 0;

        foreach ($rows as $row) {
            $bid = (int) $row['branch_id'];

            // Komisyon ve kilit durumu hesapla
            $policies = Database::fetchAll(
                "SELECT net_premium, company_comm_rate, company_comm_amount, branch_comm_rate, branch_comm_amount, reconciliation_status
                 FROM policies
                 WHERE branch_id = ? AND deleted_at IS NULL
                   AND $dateType BETWEEN ? AND ?
                   AND production_type IN ('INCOMING','OUTGOING')",
                [$bid, $firstDay, $lastDay]
            );

            $totalCommission = 0;
            $allReconciled = !empty($policies);
            foreach ($policies as $p) {
                $netAmount = (float) $p['net_premium'];
                $companyCommAmount = (isset($p['company_comm_amount']) && $p['company_comm_amount'] !== null)
                    ? (float) $p['company_comm_amount']
                    : $netAmount * (float) $p['company_comm_rate'] / 100;
                $branchCommAmount = (isset($p['branch_comm_amount']) && $p['branch_comm_amount'] !== null)
                    ? (float) $p['branch_comm_amount']
                    : $companyCommAmount * (float) $p['branch_comm_rate'] / 100;
                $totalCommission += $branchCommAmount;
                if (($p['reconciliation_status'] ?? 'PENDING') !== 'RECONCILED') {
                    $allReconciled = false;
                }
            }

            $result[] = [
                'branchId' => $bid,
                'branchName' => $row['branch_name'],
                'totalPolicies' => (int) $row['total_policies'],
                'totalCancelled' => (int) $row['total_cancelled'],
                'totalGross' => round((float) $row['total_gross'], 2),
                'totalNet' => round((float) $row['total_net'], 2),
                'totalCommission' => round($totalCommission, 2),
                'isLocked' => $allReconciled,
            ];

            $grandTotalPolicies += (int) $row['total_policies'];
            $grandTotalGross += (float) $row['total_gross'];
            $grandTotalNet += (float) $row['total_net'];
            $grandTotalCommission += $totalCommission;
        }

        Response::success([
            'mode' => 'summary',
            'branches' => $result,
            'totals' => [
                'totalPolicies' => $grandTotalPolicies,
                'totalGross' => round($grandTotalGross, 2),
                'totalNet' => round($grandTotalNet, 2),
                'totalCommission' => round($grandTotalCommission, 2),
            ],
        ]);
    }

    public function reconciliationLock(array $user, array $input): void
    {
        AuthMiddleware::denyAgent($user);

        $branchId = (int) ($input['branchId'] ?? 0);
        $year = (int) ($input['year'] ?? 0);
        $month = (int) ($input['month'] ?? 0);
        $dateType = ($input['dateType'] ?? 'issued_at') === 'starts_at' ? 'starts_at' : 'issued_at';

        if (!$branchId || !$year || !$month) {
            Response::error('Eksik parametreler', 400);
        }

        $branch = Database::fetch("SELECT id FROM branches WHERE id = ? AND deleted_at IS NULL", [$branchId]);
        if (!$branch) {
            Response::error('Acente bulunamadi', 404);
        }

        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $sql = "UPDATE policies
                SET reconciliation_status = 'RECONCILED', updated_at = NOW()
                WHERE branch_id = ?
                  AND deleted_at IS NULL
                  AND $dateType BETWEEN ? AND ?
                  AND production_type IN ('INCOMING','OUTGOING')";

        Database::query($sql, [$branchId, $firstDay, $lastDay]);

        Response::success(null, 'Mutabakat kilitlendi');
    }

    public function destroy(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);
        Database::softDelete('branches', $id);
        Response::success(null, 'Acente silindi');
    }
}
