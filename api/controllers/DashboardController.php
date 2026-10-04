<?php

class DashboardController
{
    public function stats(array $user, array $query): void
    {
        $branchFilter = '';
        $params = [];

        if ((int) $user['role'] === 2) {
            $branchFilter = " AND p.branch_id = ?";
            $params[] = $user['branchId'];
        }

        // Donem karsilastirmasi: Bu yil 1 Ocak - bugun vs Gecen yil 1 Ocak - ayni gun
        $thisYearStart = date('Y-01-01');
        $today = date('Y-m-d');
        $lastYearStart = date('Y-01-01', strtotime('-1 year'));
        $lastYearSameDay = date('Y-m-d', strtotime('-1 year'));

        // Seçili ay (varsayılan: bu ay)
        $selYear  = isset($query['year'])  ? (int) $query['year']  : (int) date('Y');
        $selMonth = isset($query['month']) ? (int) $query['month'] : (int) date('n');
        $selYear  = max(2020, min(2030, $selYear));
        $selMonth = max(1, min(12, $selMonth));
        $thisMonthStart = sprintf('%04d-%02d-01', $selYear, $selMonth);
        $thisMonthEnd   = date('Y-m-t', strtotime($thisMonthStart));
        $lastYearMonthStart = sprintf('%04d-%02d-01', $selYear - 1, $selMonth);
        $lastYearMonthEnd   = date('Y-m-t', strtotime($lastYearMonthStart));

        // Portföy karşılaştırması için bağımsız yıl parametresi
        $currentRealYear = (int) date('Y');
        $portfolioSelYear = isset($query['portfolioYear']) ? (int) $query['portfolioYear'] : $currentRealYear;
        $portfolioSelYear = max(2020, min($currentRealYear, $portfolioSelYear));
        $portfolioRefDate  = ($portfolioSelYear < $currentRealYear) ? sprintf('%04d-12-31', $portfolioSelYear)     : date('Y-m-d');
        $prevPortfolioDate = ($portfolioSelYear < $currentRealYear) ? sprintf('%04d-12-31', $portfolioSelYear - 1) : date('Y-m-d', strtotime('-1 year'));

        // Son zeyilin tanzim, bitis ve iptal durumu
        $issuedSub = "(SELECT z0.issued_at FROM policies z0 WHERE z0.policy_no = p.policy_no AND z0.deleted_at IS NULL ORDER BY z0.endorsement_no DESC LIMIT 1)";
        $expiresSub = "(SELECT z1.expires_at FROM policies z1 WHERE z1.policy_no = p.policy_no AND z1.deleted_at IS NULL ORDER BY z1.endorsement_no DESC LIMIT 1)";
        $cancelSub = "(SELECT z2.is_cancelled FROM policies z2 WHERE z2.policy_no = p.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1)";
        $totalGross = "(SELECT COALESCE(SUM(CASE WHEN z.is_cancelled = 1 AND z.gross_premium > 0 THEN -z.gross_premium ELSE z.gross_premium END), 0) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL)";
        $totalNet = "(SELECT COALESCE(SUM(CASE WHEN z.is_cancelled = 1 AND z.net_premium > 0 THEN -z.net_premium ELSE z.net_premium END), 0) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL)";

        // Ayni policy_no icin sadece en guncel ana kaydi al (yenileme tekrarini onle)
        $dedup = "p.id = (SELECT px.id FROM policies px WHERE px.policy_no = p.policy_no AND px.parent_id IS NULL AND px.deleted_at IS NULL ORDER BY px.endorsement_no DESC, px.id DESC LIMIT 1)";

        // Aktif filtre: iptal olmayan + suresi bitmemis + tekrar onleme
        $activeWhere = "p.deleted_at IS NULL AND p.parent_id IS NULL AND $dedup AND $cancelSub = 0 AND $expiresSub >= CURDATE()";

        // Bu yil: tanzim tarihi 2026 doneminde olan aktif policeler
        $thisYearParams = array_merge([$thisYearStart, $today], $params);
        $thisYear = Database::fetch(
            "SELECT COUNT(*) as total_policies
             FROM policies p
             WHERE $activeWhere
               AND p.issued_at >= ? AND p.issued_at <= ? $branchFilter",
            $thisYearParams
        );

        // Iptal sayisi — bu yil icinde tanzim edilip iptal edilen policeler
        $cancelledParams = $params;
        $cancelledRow = Database::fetch(
            "SELECT COUNT(*) as cnt
             FROM policies p
             WHERE p.deleted_at IS NULL AND p.parent_id IS NULL AND $dedup
               AND $cancelSub = 1
               AND YEAR(p.issued_at) = YEAR(CURDATE())
               $branchFilter",
            $cancelledParams
        );

        // Tum aktif policeler (yil farketmez, iptal olmayan + suresi bitmemis)
        $allActiveParams = $params;
        $allActive = Database::fetch(
            "SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN YEAR(p.issued_at) = YEAR(CURDATE()) THEN 1 END) as this_year,
                COUNT(CASE WHEN YEAR(p.issued_at) < YEAR(CURDATE()) THEN 1 END) as prev_years
             FROM policies p
             WHERE p.deleted_at IS NULL AND p.parent_id IS NULL AND $dedup
               AND p.is_cancelled = 0
               AND $cancelSub = 0
               AND $expiresSub >= CURDATE()
               $branchFilter",
            $allActiveParams
        );

        // Primler - tum aktif portfoy buyuklugu (yil farketmez)
        $premiumParams = $params;
        $premiums = Database::fetch(
            "SELECT COALESCE(SUM($totalGross), 0) as total_premium,
                    COALESCE(SUM($totalNet), 0) as total_net
             FROM policies p
             WHERE $activeWhere $branchFilter",
            $premiumParams
        );

        // Portfoy karsilastirmasi: selYear referans tarihi ile bir onceki yil referans tarihi
        $firstStartSub = "(SELECT MIN(z7.starts_at) FROM policies z7 WHERE z7.policy_no = p.policy_no AND z7.deleted_at IS NULL)";
        $lastYearPortfolioWhere = "p.deleted_at IS NULL AND p.parent_id IS NULL AND $dedup AND $cancelSub = 0 AND $expiresSub >= ? AND $firstStartSub <= ?";

        // selYear referans tarihi icin portfoy
        $portfolioCurrentParams = array_merge([$portfolioRefDate, $portfolioRefDate], $params);
        $portfolioCurrent = Database::fetch(
            "SELECT COALESCE(SUM($totalGross), 0) as total_premium
             FROM policies p
             WHERE $lastYearPortfolioWhere $branchFilter",
            $portfolioCurrentParams
        );

        // Bir onceki yil referans tarihi icin portfoy
        $lastYearPortfolioParams = array_merge([$prevPortfolioDate, $prevPortfolioDate], $params);
        $lastYearPortfolio = Database::fetch(
            "SELECT COUNT(*) as total_policies,
                    COALESCE(SUM($totalGross), 0) as total_premium,
                    COALESCE(SUM($totalNet), 0) as total_net
             FROM policies p
             WHERE $lastYearPortfolioWhere $branchFilter",
            $lastYearPortfolioParams
        );

        $customerCount = Database::fetch(
            "SELECT COUNT(*) as total FROM customers WHERE deleted_at IS NULL"
        );

        // Musteri basi prim: aktif portfoydeki benzersiz musteri sayisi
        $activeCustomerCount = Database::fetch(
            "SELECT COUNT(DISTINCT p.customer_id) as total
             FROM policies p
             WHERE $activeWhere $branchFilter",
            $params
        );

        // Gecen yil aktif portfoydeki benzersiz musteri sayisi
        $lastYearActiveCustomerCount = Database::fetch(
            "SELECT COUNT(DISTINCT p.customer_id) as total
             FROM policies p
             WHERE $lastYearPortfolioWhere $branchFilter",
            $lastYearPortfolioParams
        );

        // Monthly production - tanzim bazli, her zeyil kendi tarihiyle (portfoy sayfasiyla ayni mantik)
        $monthlyProdParams = array_merge([$thisMonthStart, $thisMonthEnd], $params);
        $monthlyProd = Database::fetch(
            "SELECT
                COUNT(*) as count,
                COALESCE(SUM(p.gross_premium), 0) as premium
             FROM policies p
             WHERE p.deleted_at IS NULL
               AND p.issued_at >= ? AND p.issued_at <= ?
               $branchFilter",
            $monthlyProdParams
        );

        $lastYearMonthlyParams = array_merge([$lastYearMonthStart, $lastYearMonthEnd], $params);
        $lastYearMonthlyProd = Database::fetch(
            "SELECT
                COUNT(*) as count,
                COALESCE(SUM(p.gross_premium), 0) as premium
             FROM policies p
             WHERE p.deleted_at IS NULL
               AND p.issued_at >= ? AND p.issued_at <= ?
               $branchFilter",
            $lastYearMonthlyParams
        );

        Response::success([
            'totalPolicies' => (int) $thisYear['total_policies'],
            'allActivePolicies' => (int) $allActive['total'],
            'activeThisYear' => (int) $allActive['this_year'],
            'activePrevYears' => (int) $allActive['prev_years'],
            'totalCustomers' => (int) $customerCount['total'],
            'totalPremium' => (float) $premiums['total_premium'],
            'portfolioPremium' => (float) $portfolioCurrent['total_premium'],
            'totalNet' => (float) $premiums['total_net'],
            'totalCancelled' => (int) $cancelledRow['cnt'],
            'monthlyProduction' => [
                'count' => (int) $monthlyProd['count'],
                'premium' => (float) $monthlyProd['premium'],
            ],
            'activeCustomerCount' => (int) $activeCustomerCount['total'],
            'perCustomerPremium' => (int) $activeCustomerCount['total'] > 0
                ? round((float) $premiums['total_premium'] / (int) $activeCustomerCount['total'], 2)
                : 0,
            'lastYear' => [
                'totalPolicies' => (int) $lastYearPortfolio['total_policies'],
                'totalPremium' => (float) $lastYearPortfolio['total_premium'],
                'totalNet' => (float) $lastYearPortfolio['total_net'],
                'monthlyPremium' => (float) $lastYearMonthlyProd['premium'],
                'monthlyCount' => (int) $lastYearMonthlyProd['count'],
                'activeCustomerCount' => (int) $lastYearActiveCustomerCount['total'],
                'perCustomerPremium' => (int) $lastYearActiveCustomerCount['total'] > 0
                    ? round((float) $lastYearPortfolio['total_premium'] / (int) $lastYearActiveCustomerCount['total'], 2)
                    : 0,
            ],
        ]);
    }

    public function charts(array $user, array $query): void
    {
        $branchFilter = '';
        $params = [];

        if ((int) $user['role'] === 2) {
            $branchFilter = " AND p.branch_id = ?";
            $params[] = $user['branchId'];
        }

        $productionFilter = '';
        if (!empty($query['view'])) {
            if ($query['view'] === 'self') $productionFilter = " AND p.production_type = 'SELF'";
            elseif ($query['view'] === 'outgoing') $productionFilter = " AND p.production_type = 'OUTGOING'";
        }

        $thisYearStart = date('Y-01-01');
        $thisYearMonthEnd = date('Y-m-t'); // Mevcut ayin son gunu (ileriki tarihli girisler dahil)
        $lastYearStart = date('Y-01-01', strtotime('-1 year'));
        $lastYearSameDay = date('Y-m-d', strtotime('-1 year'));

        $cancelSub = "(SELECT z2.is_cancelled FROM policies z2 WHERE z2.policy_no = p.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1)";
        $expiresSub = "(SELECT z1.expires_at FROM policies z1 WHERE z1.policy_no = p.policy_no AND z1.deleted_at IS NULL ORDER BY z1.endorsement_no DESC LIMIT 1)";
        $totalGross = "(SELECT COALESCE(SUM(CASE WHEN pz.is_cancelled = 1 AND pz.gross_premium > 0 THEN -pz.gross_premium ELSE pz.gross_premium END), 0) FROM policies pz WHERE pz.policy_no = p.policy_no AND pz.deleted_at IS NULL)";

        // Ayni policy_no icin sadece en guncel ana kaydi al
        $dedup = "p.id = (SELECT px.id FROM policies px WHERE px.policy_no = p.policy_no AND px.parent_id IS NULL AND px.deleted_at IS NULL ORDER BY px.endorsement_no DESC, px.id DESC LIMIT 1)";

        // Aktif filtre: iptal olmayan + suresi bitmemis + tekrar onleme
        $activeWhere = "p.deleted_at IS NULL AND p.parent_id IS NULL AND $dedup AND $cancelSub = 0 AND $expiresSub >= CURDATE()";

        // Monthly premium chart - tanzim bazli, her zeyil kendi tarihiyle (portfoy sayfasiyla ayni mantik)
        $monthlyParams = array_merge([$thisYearStart, $thisYearMonthEnd], $params);
        $monthly = Database::fetchAll(
            "SELECT
                DATE_FORMAT(p.issued_at, '%Y-%m') as month,
                MONTH(p.issued_at) as month_num,
                COALESCE(SUM(p.gross_premium), 0) as premium,
                COUNT(*) as count
             FROM policies p
             WHERE p.deleted_at IS NULL
               AND p.issued_at >= ? AND p.issued_at <= ?
               $productionFilter
               $branchFilter
             GROUP BY DATE_FORMAT(p.issued_at, '%Y-%m'), MONTH(p.issued_at)
             ORDER BY month",
            $monthlyParams
        );

        // Monthly premium chart - last year (tam yil)
        $lastYearEnd = date('Y-12-31', strtotime('-1 year'));
        $monthlyLYParams = array_merge([$lastYearStart, $lastYearEnd], $params);
        $monthlyLastYear = Database::fetchAll(
            "SELECT
                DATE_FORMAT(p.issued_at, '%Y-%m') as month,
                MONTH(p.issued_at) as month_num,
                COALESCE(SUM(p.gross_premium), 0) as premium,
                COUNT(*) as count
             FROM policies p
             WHERE p.deleted_at IS NULL
               AND p.issued_at >= ? AND p.issued_at <= ?
               $productionFilter
               $branchFilter
             GROUP BY DATE_FORMAT(p.issued_at, '%Y-%m'), MONTH(p.issued_at)
             ORDER BY month",
            $monthlyLYParams
        );

        // Insurance type distribution - aktif
        $byInsurance = Database::fetchAll(
            "SELECT i.name, i.branch_group as ins_group,
                    COUNT(*) as count,
                    COALESCE(SUM($totalGross), 0) as premium
             FROM policies p
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE $activeWhere
               $productionFilter
               $branchFilter
             GROUP BY p.insurance_type_id, i.name, i.branch_group
             ORDER BY premium DESC",
            $params
        );

        // Company distribution - aktif
        $byCompany = Database::fetchAll(
            "SELECT co.name,
                    COUNT(*) as count,
                    COALESCE(SUM($totalGross), 0) as premium
             FROM policies p
             LEFT JOIN companies co ON p.company_id = co.id
             WHERE $activeWhere
               $productionFilter
               $branchFilter
             GROUP BY p.company_id, co.name
             ORDER BY premium DESC",
            $params
        );

        // Production type distribution - aktif
        $byProd = Database::fetchAll(
            "SELECT p.production_type,
                    COUNT(*) as count,
                    COALESCE(SUM($totalGross), 0) as premium
             FROM policies p
             WHERE $activeWhere
               $productionFilter
               $branchFilter
             GROUP BY p.production_type",
            $params
        );

        // Insurance group distribution - aktif
        $byGroup = Database::fetchAll(
            "SELECT i.branch_group as ins_group,
                    COUNT(*) as count,
                    COALESCE(SUM($totalGross), 0) as premium
             FROM policies p
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE $activeWhere
               $productionFilter
               $branchFilter
             GROUP BY i.branch_group
             ORDER BY premium DESC",
            $params
        );

        // SAĞLIK alt dağılımı (TSS, ÖSS, vb.)
        $byGroupSaglik = Database::fetchAll(
            "SELECT i.name as ins_name,
                    COUNT(*) as count,
                    COALESCE(SUM($totalGross), 0) as premium
             FROM policies p
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE $activeWhere
               $productionFilter
               $branchFilter
               AND i.branch_group = 'SAĞLIK'
             GROUP BY i.name
             ORDER BY premium DESC",
            $params
        );

        Response::success([
            'monthly' => array_map(fn($m) => [
                'month' => $m['month'],
                'monthNum' => (int) $m['month_num'],
                'premium' => (float) $m['premium'],
                'count' => (int) $m['count'],
            ], $monthly),
            'monthlyLastYear' => array_map(fn($m) => [
                'month' => $m['month'],
                'monthNum' => (int) $m['month_num'],
                'premium' => (float) $m['premium'],
                'count' => (int) $m['count'],
            ], $monthlyLastYear),
            'byInsurance' => array_map(fn($i) => [
                'name' => $i['name'],
                'group' => $i['ins_group'] ?? 'DİĞER',
                'count' => (int) $i['count'],
                'premium' => (float) $i['premium'],
            ], $byInsurance),
            'byGroup' => array_map(fn($g) => [
                'group' => $g['ins_group'] ?? 'DİĞER',
                'count' => (int) $g['count'],
                'premium' => (float) $g['premium'],
            ], $byGroup),
            'byGroupSaglik' => array_map(fn($g) => [
                'name' => $g['ins_name'] ?? 'DİĞER',
                'count' => (int) $g['count'],
                'premium' => (float) $g['premium'],
            ], $byGroupSaglik),
            'byCompany' => array_map(fn($c) => [
                'name' => $c['name'],
                'count' => (int) $c['count'],
                'premium' => (float) $c['premium'],
            ], $byCompany),
            'byProd' => array_map(fn($p) => [
                'prod' => $p['production_type'],
                'count' => (int) $p['count'],
                'premium' => (float) $p['premium'],
            ], $byProd),
        ]);
    }

    public function renewals(array $user, array $query): void
    {
        // Pseudo-cron: Bugun hic calismadiys TaskController::cron() tetikle
        try {
            $lastRun = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'last_cron_run'");
            $today   = date('Y-m-d');
            if (!$lastRun || $lastRun['value'] !== $today) {
                require_once __DIR__ . '/TaskController.php';
                $tc = new TaskController();
                $tc->runCronJobs();
            }
        } catch (\Throwable $e) {
            // Cron hatasi ana islevi engellemesin
        }

        $params = [];
        $assignFilter = '';

        // Non-admin: only see tasks assigned to them
        if ((int) $user['role'] !== 1) {
            $assignFilter = " AND t.assigned_to = ?";
            $params[] = $user['userId'];
        }

        // Etkili gun sayisi: tum tipler t.offer_expires_at'i kullanir, yoksa deadline
        $effectiveDaysExpr = "CASE
            WHEN t.offer_expires_at IS NOT NULL THEN DATEDIFF(t.offer_expires_at, CURDATE())
            WHEN t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
            ELSE NULL
        END";

        // Status filtresi: dogrudan status eslestirme (PENDING/IN_PROGRESS/EXPIRED ne ise o)
        if ((int) $user['role'] !== 1) {
            $statusFilter = " AND t.status IN ('PENDING','IN_PROGRESS')";
        } else {
            $statusFilter = '';
            if (!empty($query['statuses'])) {
                $statuses = array_filter(explode(',', $query['statuses']));
                if (!empty($statuses)) {
                    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                    $statusFilter = " AND t.status IN ($placeholders)";
                    $params = array_merge($params, $statuses);
                }
            } else {
                $statusFilter = " AND t.status NOT IN ('CANCELLED')";
            }
        }

        // OFFER gorevlerinde policy_id yok; bilgiler offer_data JSON'da.
        // RENEWAL icin policy join'i, OFFER icin offer_data fallback kullanilir.
        $sql = "SELECT t.id, t.title, t.description, t.type, t.status, t.priority, t.deadline,
                    t.assigned_to, t.result, t.result_reason, t.completed_at,
                    t.created_at, t.policy_id, t.offer_data,
                    u.name as assigned_to_name,
                    cu.name as customer_name, cu.id as customer_id, cu.identity_no as customer_identity, cu.phone as customer_phone,
                    p.policy_no,
                    COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) as plate_no,
                    COALESCE(p.registration_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.registrationNo')), 'null')) as registration_no,
                    p.production_type,
                    COALESCE(co.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.companyName')), 'null')) as company_name,
                    COALESCE(i.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')) as insurance_name,
                    i.color as insurance_color,
                    br.name as branch_name,
                    -- Etkili bitis tarihi: tum tipler t.offer_expires_at, yoksa deadline
                    COALESCE(t.offer_expires_at, DATE(t.deadline)) as effective_expires_at,
                    -- daysRemaining ayni mantikla
                    CASE
                        WHEN t.offer_expires_at IS NOT NULL THEN DATEDIFF(t.offer_expires_at, CURDATE())
                        WHEN t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                        ELSE NULL
                    END as days_remaining
                FROM tasks t
                LEFT JOIN users u ON t.assigned_to = u.id AND u.is_active = 1 AND u.deleted_at IS NULL
                LEFT JOIN customers cu ON t.customer_id = cu.id
                LEFT JOIN policies p ON t.policy_id = p.id
                LEFT JOIN companies co ON p.company_id = co.id
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN branches br ON p.branch_id = br.id
                WHERE t.type IN ('RENEWAL', 'OFFER', 'REFERENCE', 'FOLLOW_UP_CALL') AND t.deleted_at IS NULL
                  $assignFilter $statusFilter
                  -- PENDING / IN_PROGRESS olanlar 15 gun veya daha az kalmis olmali
                  -- COMPLETED / EXPIRED olanlar son 30 gunde bitmis olmali
                  AND (
                    -- FOLLOW_UP_CALL: sadece deadline bugun veya gecmis olanlar (gelecektekiler gosterilmez)
                    (t.type = 'FOLLOW_UP_CALL' AND t.status IN ('PENDING','IN_PROGRESS') AND DATE(t.deadline) <= CURDATE())
                    -- Diger tipler: 15 gun icindekiler
                    OR (t.type != 'FOLLOW_UP_CALL' AND t.status IN ('PENDING','IN_PROGRESS') AND (COALESCE(DATEDIFF(t.offer_expires_at, CURDATE()), DATEDIFF(DATE(t.deadline), CURDATE())) <= 15 OR (t.offer_expires_at IS NULL AND t.deadline IS NULL)))
                    -- Tamamlanan/gecen gorevler (tum tipler)
                    OR (t.status = 'COMPLETED' AND t.completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
                    OR (t.status = 'EXPIRED' AND COALESCE(t.offer_expires_at, DATE(t.deadline)) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY))
                  )
                ORDER BY
                         -- 1: Aktif görevler önce, sonra EXPIRED, en sonda COMPLETED/CANCELLED
                         CASE WHEN t.status IN ('PENDING','IN_PROGRESS') THEN 0
                              WHEN t.status = 'EXPIRED' THEN 1
                              ELSE 2 END,
                         -- Aktif görevler: tarihe göre ASC (en yakın önce)
                         CASE WHEN t.status IN ('PENDING','IN_PROGRESS') THEN effective_expires_at END ASC,
                         -- EXPIRED görevler: tarihe göre DESC (en yeni önce)
                         CASE WHEN t.status = 'EXPIRED' THEN effective_expires_at END DESC,
                         -- COMPLETED görevler: tamamlanma tarihine göre DESC
                         CASE WHEN t.status = 'COMPLETED' THEN t.completed_at END DESC,
                         FIELD(t.priority, 'URGENT', 'HIGH', 'MEDIUM', 'LOW')
                LIMIT 500";

        $rows = Database::fetchAll($sql, $params);

        $data = array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'description' => $r['description'] ?? null,
                'type' => $r['type'],
                'status' => $r['status'],
                'priority' => $r['priority'],
                'deadline' => $r['deadline'],
                'assignedTo' => $r['assigned_to'] ? (int) $r['assigned_to'] : null,
                'assignedToName' => $r['assigned_to_name'],
                'result' => $r['result'],
                'resultReason' => $r['result_reason'],
                'completedAt' => $r['completed_at'],
                'createdAt' => $r['created_at'],
                'policyId' => $r['policy_id'] ? (int) $r['policy_id'] : null,
                'policyNo' => $r['policy_no'],
                'plateNo' => $r['plate_no'],
                'registrationNo' => $r['registration_no'],
                'productionType' => $r['production_type'],
                'expiresAt' => $r['effective_expires_at'],
                'customerName' => $r['customer_name'],
                'customerIdentity' => $r['customer_identity'],
                'customerId' => $r['customer_id'] ? (int) $r['customer_id'] : null,
                'customerPhone' => $r['customer_phone'] ?? null,
                'daysRemaining' => $r['days_remaining'] !== null ? (int) $r['days_remaining'] : null,
                'companyName' => $r['company_name'],
                'insuranceName' => $r['insurance_name'],
                'insuranceColor' => $r['insurance_color'] ?? null,
                'branchName' => $r['branch_name'],
                'offerData' => $r['offer_data'] ? json_decode($r['offer_data'], true) : null,
            ];
        }, $rows);

        Response::success($data);
    }

    public function crossSell(array $user, array $query): void
    {
        $hasType = $query['hasType'] ?? null;
        $notType = $query['notType'] ?? null;

        if (!$hasType || !$notType) {
            Response::error('Sigorta turleri secilmeli', 400);
            return;
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;
        $search = trim($query['search'] ?? '');

        // Siralama
        $allowedSorts = [
            'customer_name' => 'cu.name',
            'policy_no' => 'p.policy_no',
            'plate_no' => 'p.plate_no',
            'insurance_name' => 'i.name',
            'company_name' => 'co.name',
            'expires_at' => 'effective_expires_at',
        ];
        $sortCol = $allowedSorts[$query['sort'] ?? ''] ?? 'cu.name';
        $sortOrder = strtolower($query['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $branchFilter = '';
        $baseParams = [];

        if ((int) $user['role'] === 2) {
            $branchFilter = " AND p.branch_id = ?";
            $baseParams[] = $user['branchId'];
        }

        $cancelSub = "(SELECT z2.is_cancelled FROM policies z2 WHERE z2.policy_no = p.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1)";
        $expiresSub = "(SELECT z1.expires_at FROM policies z1 WHERE z1.policy_no = p.policy_no AND z1.deleted_at IS NULL ORDER BY z1.endorsement_no DESC LIMIT 1)";
        $dedup = "p.id = (SELECT px.id FROM policies px WHERE px.policy_no = p.policy_no AND px.parent_id IS NULL AND px.deleted_at IS NULL ORDER BY px.endorsement_no DESC, px.id DESC LIMIT 1)";
        $activeWhere = "p.deleted_at IS NULL AND p.parent_id IS NULL AND $dedup AND $cancelSub = 0 AND $expiresSub >= CURDATE()";

        $hasIds = $this->getSubcategoryIds($hasType);
        $notIds = $this->getSubcategoryIds($notType);

        if (empty($hasIds) || empty($notIds)) {
            Response::error('Gecersiz sigorta turu', 400);
            return;
        }

        $hasPlaceholders = implode(',', array_fill(0, count($hasIds), '?'));
        $notPlaceholders = implode(',', array_fill(0, count($notIds), '?'));

        $searchFilter = '';
        $searchParams = [];
        if ($search !== '') {
            $like = '%' . $search . '%';
            $searchFilter = " AND (cu.name LIKE ? OR cu.identity_no LIKE ? OR p.policy_no LIKE ? OR p.plate_no LIKE ?)";
            $searchParams = [$like, $like, $like, $like];
        }

        // Aktif capraz satis gorevi olan musterileri haric tut (ayni isimli tum not ids ile karsilastir)
        $notIdsStr = array_map('strval', $notIds);
        $notTaskPlaceholders = implode(',', array_fill(0, count($notIdsStr), '?'));
        $taskExclude = " AND cu.id NOT IN (
                      SELECT DISTINCT t.customer_id
                      FROM tasks t
                      WHERE t.type = 'CROSS_SELL' AND t.deleted_at IS NULL
                        AND t.status NOT IN ('COMPLETED','CANCELLED')
                        AND JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceId')) IN ($notTaskPlaceholders)
                  )";

        $whereClause = "$activeWhere
                  $branchFilter
                  AND p.insurance_type_id IN ($hasPlaceholders)
                  AND cu.id NOT IN (
                      SELECT DISTINCT p2.customer_id
                      FROM policies p2
                      WHERE p2.deleted_at IS NULL AND p2.parent_id IS NULL
                        AND (SELECT z3.is_cancelled FROM policies z3 WHERE z3.policy_no = p2.policy_no AND z3.deleted_at IS NULL ORDER BY z3.endorsement_no DESC LIMIT 1) = 0
                        AND (SELECT z4.expires_at FROM policies z4 WHERE z4.policy_no = p2.policy_no AND z4.deleted_at IS NULL ORDER BY z4.endorsement_no DESC LIMIT 1) >= CURDATE()
                        AND p2.insurance_type_id IN ($notPlaceholders)
                  )
                  $taskExclude
                  $searchFilter";

        $commonParams = array_merge($baseParams, $hasIds, $notIds, $notIdsStr, $searchParams);

        // Toplam kayit sayisi
        $countSql = "SELECT COUNT(DISTINCT CONCAT(cu.id, '-', p.id)) as total
                FROM policies p
                INNER JOIN customers cu ON p.customer_id = cu.id AND cu.deleted_at IS NULL
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN companies co ON p.company_id = co.id
                WHERE $whereClause";

        $countRow = Database::fetch($countSql, $commonParams);
        $total = (int) ($countRow['total'] ?? 0);

        // Veri cek
        $sql = "SELECT cu.id, cu.name, cu.phone, cu.identity_no, cu.customer_type,
                       p.policy_no, p.plate_no, p.registration_no,
                       p.insurance_type_id,
                       i.name as insurance_name,
                       i.color as insurance_color,
                       co.name as company_name,
                       $expiresSub as effective_expires_at,
                       DATEDIFF($expiresSub, CURDATE()) as days_remaining
                FROM policies p
                INNER JOIN customers cu ON p.customer_id = cu.id AND cu.deleted_at IS NULL
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN companies co ON p.company_id = co.id
                WHERE $whereClause
                ORDER BY $sortCol $sortOrder
                LIMIT $limit OFFSET $offset";

        $rows = Database::fetchAll($sql, $commonParams);

        $data = array_map(function ($r) {
            return [
                'customerId' => (int) $r['id'],
                'customerName' => $r['name'],
                'phone' => $r['phone'],
                'identityNo' => $r['identity_no'],
                'customerType' => $r['customer_type'],
                'policyNo' => $r['policy_no'],
                'plateNo' => $r['plate_no'],
                'registrationNo' => $r['registration_no'],
                'insuranceName' => $r['insurance_name'],
                'insuranceColor' => $r['insurance_color'] ?? null,
                'companyName' => $r['company_name'],
                'expiresAt' => $r['effective_expires_at'],
                'daysRemaining' => $r['days_remaining'] !== null ? (int) $r['days_remaining'] : null,
            ];
        }, $rows);

        Response::paginated([
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    private function getSubcategoryIds(string $value): array
    {
        // Eger numeric ise: verilen id'nin adini bul, AYNI ISIMDEKI tum subcategory'leri dondur
        // (Ornek: 'TSS' adi birden fazla kayitta olabilir; hepsini dahil et)
        if (is_numeric($value)) {
            $id = (int) $value;
            $row = Database::fetch(
                "SELECT name FROM insurance_types WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );
            if (!$row) {
                return [$id];
            }
            $rows = Database::fetchAll(
                "SELECT id FROM insurance_types WHERE name = ? AND level = 'subcategory' AND is_active = 1 AND deleted_at IS NULL",
                [$row['name']]
            );
            return array_map(fn($r) => (int) $r['id'], $rows) ?: [$id];
        }

        // Branch group ise tum subcategory id'lerini getir
        $rows = Database::fetchAll(
            "SELECT id FROM insurance_types WHERE branch_group = ? AND level = 'subcategory' AND is_active = 1 AND deleted_at IS NULL",
            [$value]
        );

        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    public function userReconciliation(array $user, array $query): void
    {
        AuthMiddleware::requireAdmin($user);

        $userId = (int) ($query['userId'] ?? 0);
        $year = (int) ($query['year'] ?? date('Y'));
        $month = (int) ($query['month'] ?? date('n'));
        $dateType = ($query['dateType'] ?? 'issued_at') === 'starts_at' ? 'starts_at' : 'issued_at';

        // userId yoksa tum temsilcilerin ozet hakedis tablosu
        if (!$userId) {
            // userId=-1 ve secret varsa atanmamis policelerin detayini goster
            $showUnassigned = ($query['unassigned'] ?? '') === '1';
            if ($showUnassigned) {
                $secret = $query['secret'] ?? '';
                $storedSecret = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'reconciliation_secret'");
                if (!$storedSecret || $secret !== $storedSecret['value']) {
                    Response::error('Şifre hatalı', 403);
                    return;
                }
                $this->unassignedReconciliation($user, $year, $month, $dateType);
                return;
            }
            $this->allUsersReconciliation($user, $year, $month, $dateType);
            return;
        }

        $targetUser = Database::fetch("SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
        if (!$targetUser) {
            Response::error('Kullanici bulunamadi', 404);
        }

        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $dateFilter = "p.$dateType BETWEEN '$firstDay' AND '$lastDay'";

        // Acente adi (SELF policeler icin branchName bossa kullanilir)
        $agencySetting = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'agency_name'");
        $agencyName = $agencySetting['value'] ?? '';

        $sql = "SELECT p.*,
                    cu.name as customer_name,
                    i.name as insurance_name,
                    co.name as company_name,
                    br.name as branch_name,
                    rs.name as ref_source_name,
                    rs.commission_rate as ref_commission_rate,
                    su.name as sold_by_name,
                    (SELECT COUNT(*) FROM policies pp
                       WHERE pp.customer_id = p.customer_id
                         AND pp.insurance_type_id = p.insurance_type_id
                         AND pp.deleted_at IS NULL
                         AND pp.parent_id IS NULL
                         AND pp.id <> p.id
                         AND pp.starts_at < p.starts_at) AS prev_count
                FROM policies p
                LEFT JOIN customers cu ON p.customer_id = cu.id
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN companies co ON p.company_id = co.id
                LEFT JOIN branches br ON p.branch_id = br.id
                LEFT JOIN reference_sources rs ON p.reference_source = rs.id
                LEFT JOIN users su ON p.sold_by = su.id
                WHERE p.sold_by = ?
                  AND p.deleted_at IS NULL
                  AND $dateFilter
                ORDER BY p.$dateType DESC";

        $policies = Database::fetchAll($sql, [$userId]);

        $totalGross = 0;
        $totalNet = 0;
        $totalCompanyComm = 0;
        $totalBranchComm = 0;
        $totalBizeKalan = 0;
        $totalHakedis = 0;
        $totalPolicies = 0;
        $totalCancelled = 0;
        $bySelf = 0;
        $byIncoming = 0;
        $byOutgoing = 0;

        $result = [];
        foreach ($policies as $p) {
            $grossAmount = (float) $p['gross_premium'];
            $netAmount = (float) $p['net_premium'];
            $companyCommRate = (float) ($p['company_comm_rate'] ?? 0);
            $branchCommRate = (float) ($p['branch_comm_rate'] ?? 0);
            $refCommRate = (float) ($p['ref_commission_rate'] ?? 0);

            // Sirket komisyonu: kayitli tutar varsa onu kullan (Allianz gercek tutari), yoksa orandan hesapla
            $companyComm = (isset($p['company_comm_amount']) && $p['company_comm_amount'] !== null)
                ? (float) $p['company_comm_amount']
                : $netAmount * $companyCommRate / 100;
            // Acente komisyonu: kayitli tutar varsa onu kullan
            $branchComm = (isset($p['branch_comm_amount']) && $p['branch_comm_amount'] !== null)
                ? (float) $p['branch_comm_amount']
                : $companyComm * $branchCommRate / 100;

            // Bize kalan: sirket komisyonu - acente komisyonu
			$bizeKalan = $companyComm;
            // Bize kalan: sirket komisyonu - acente komisyonu
            if($p['production_type'] === 'OUTGOING'){
				$bizeKalan =  $branchComm;
			} else if($p['production_type'] === 'INCOMING'){
				$bizeKalan =    $companyComm - $branchComm;
			}
            
            // Hakedis: bize kalan * ref%
            $hakedis = $bizeKalan * $refCommRate / 100;

            $totalGross += $grossAmount;
            $totalNet += $netAmount;
            $totalCompanyComm += $companyComm;
            $totalBranchComm += $branchComm;
            $totalBizeKalan += $bizeKalan;
            $totalHakedis += $hakedis;
            $totalPolicies++;
            if ($p['is_cancelled'] == '1') $totalCancelled++;

            if ($p['production_type'] === 'SELF') $bySelf++;
            elseif ($p['production_type'] === 'INCOMING') $byIncoming++;
            elseif ($p['production_type'] === 'OUTGOING') $byOutgoing++;

            $result[] = [
                'id' => (int) $p['id'],
                'customerId' => (int) $p['customer_id'],
                'policyNo' => $p['policy_no'],
                'endorsementNo' => (int) $p['endorsement_no'],
                'isCancelled' => (bool) $p['is_cancelled'],
                'productionType' => $p['production_type'],
                'customerName' => $p['customer_name'] ?? '',
                'insuranceName' => $p['insurance_name'] ?? '',
                'companyName' => $p['company_name'] ?? '',
                'branchName' => (!empty($p['branch_name']) ? $p['branch_name'] : ($p['production_type'] === 'SELF' ? ($agencyName ?? '') : '')),
                'refSourceName' => $p['ref_source_name'] ?? null,
                'soldByName' => $p['sold_by_name'] ?? null,
                'policyType' => ((int) ($p['prev_count'] ?? 0)) > 0 ? 'Yenileme' : 'Yeni İş',
                'companyCommRate' => $companyCommRate,
                'branchCommRate' => $branchCommRate,
                'refCommRate' => $refCommRate,
                'issuedAt' => $p['issued_at'],
                'startsAt' => $p['starts_at'],
                'expiresAt' => $p['expires_at'],
                'grossPremium' => $grossAmount,
                'netPremium' => $netAmount,
                'companyComm' => round($companyComm, 2),
                'branchComm' => round($branchComm, 2),
                'bizeKalan' => round($bizeKalan, 2),
                'hakedis' => round($hakedis, 2),
                'plateNo' => $p['plate_no'] ?? null,
                'reconciliationStatus' => $p['reconciliation_status'] ?? 'PENDING',
            ];
        }

        // Donem kilit durumu: tum policeler RECONCILED mi?
        $isLocked = !empty($result) && count(array_filter($result, fn($r) => $r['reconciliationStatus'] === 'RECONCILED')) === count($result);

        Response::success([
            'user' => [
                'id' => (int) $targetUser['id'],
                'name' => $targetUser['name'],
            ],
            'isLocked' => $isLocked,
            'stats' => [
                'totalPolicies' => $totalPolicies,
                'totalCancelled' => $totalCancelled,
                'totalGross' => round($totalGross, 2),
                'totalNet' => round($totalNet, 2),
                'totalCompanyComm' => round($totalCompanyComm, 2),
                'totalBranchComm' => round($totalBranchComm, 2),
                'totalBizeKalan' => round($totalBizeKalan, 2),
                'totalHakedis' => round($totalHakedis, 2),
                'bySelf' => $bySelf,
                'byIncoming' => $byIncoming,
                'byOutgoing' => $byOutgoing,
            ],
            'policies' => $result,
        ]);
    }

    private function allUsersReconciliation(array $user, int $year, int $month, string $dateType): void
    {
        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $sql = "SELECT
                    u.id as user_id,
                    u.name as user_name,
                    COUNT(p.id) as total_policies,
                    SUM(CASE WHEN p.is_cancelled = 1 THEN 1 ELSE 0 END) as total_cancelled,
                    SUM(p.gross_premium) as total_gross,
                    SUM(p.net_premium) as total_net,
                    p.reconciliation_status
                FROM users u
                INNER JOIN policies p ON p.sold_by = u.id AND p.deleted_at IS NULL AND p.$dateType BETWEEN ? AND ?
                WHERE u.deleted_at IS NULL
                GROUP BY u.id, u.name
                ORDER BY u.name ASC";

        $rows = Database::fetchAll($sql, [$firstDay, $lastDay]);

        // Atanmamis policeler (sold_by IS NULL)
        $unassignedSql = "SELECT
                            COUNT(p.id) as total_policies,
                            SUM(CASE WHEN p.is_cancelled = 1 THEN 1 ELSE 0 END) as total_cancelled,
                            SUM(p.gross_premium) as total_gross,
                            SUM(p.net_premium) as total_net
                        FROM policies p
                        WHERE p.sold_by IS NULL AND p.deleted_at IS NULL AND p.$dateType BETWEEN ? AND ?";
        $unassigned = Database::fetch($unassignedSql, [$firstDay, $lastDay]);

        // Hakedis hesaplama fonksiyonu
        $calcHakedis = function(string $whereClause, array $params) use ($dateType, $firstDay, $lastDay) {
            $policySql = "SELECT p.net_premium, p.production_type, p.is_cancelled, p.reconciliation_status,
                            COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100) as company_comm,
                            p.company_comm_rate, p.branch_comm_rate,
                            COALESCE(p.branch_comm_amount, COALESCE(p.company_comm_amount, p.net_premium * p.company_comm_rate / 100) * p.branch_comm_rate / 100) as branch_comm,
                            rs.commission_rate as ref_commission_rate
                        FROM policies p
                        LEFT JOIN reference_sources rs ON p.reference_source = rs.id
                        WHERE $whereClause AND p.deleted_at IS NULL AND p.$dateType BETWEEN ? AND ?";
            $allParams = array_merge($params, [$firstDay, $lastDay]);
            $userPolicies = Database::fetchAll($policySql, $allParams);

            $totalHakedis = 0;
            $totalCompanyComm = 0;
            $allReconciled = true;
            $hasAny = false;

            foreach ($userPolicies as $up) {
                $hasAny = true;
                $companyComm = (float) $up['company_comm'];
                $branchComm = (float) $up['branch_comm'];
                $refCommRate = (float) ($up['ref_commission_rate'] ?? 0);

                $bizeKalan = $companyComm;
                if ($up['production_type'] === 'OUTGOING') {
                    $bizeKalan = $branchComm;
                } elseif ($up['production_type'] === 'INCOMING') {
                    $bizeKalan = $companyComm - $branchComm;
                }

                $totalHakedis += $bizeKalan * $refCommRate / 100;
                $totalCompanyComm += $companyComm;

                if (($up['reconciliation_status'] ?? 'PENDING') !== 'RECONCILED') {
                    $allReconciled = false;
                }
            }

            return [
                'hakedis' => $totalHakedis,
                'companyComm' => $totalCompanyComm,
                'isLocked' => $hasAny && $allReconciled,
            ];
        };

        $result = [];
        $grandTotalHakedis = 0;
        $grandTotalPolicies = 0;
        $grandTotalGross = 0;
        $grandTotalNet = 0;

        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            $calc = $calcHakedis('p.sold_by = ?', [$uid]);

            $result[] = [
                'userId' => $uid,
                'userName' => $row['user_name'],
                'totalPolicies' => (int) $row['total_policies'],
                'totalCancelled' => (int) $row['total_cancelled'],
                'totalGross' => round((float) $row['total_gross'], 2),
                'totalNet' => round((float) $row['total_net'], 2),
                'totalCompanyComm' => round($calc['companyComm'], 2),
                'totalHakedis' => round($calc['hakedis'], 2),
                'isLocked' => $calc['isLocked'],
            ];

            $grandTotalHakedis += $calc['hakedis'];
            $grandTotalPolicies += (int) $row['total_policies'];
            $grandTotalGross += (float) $row['total_gross'];
            $grandTotalNet += (float) $row['total_net'];
        }

        // Atanmamis polceleri ekle
        $unassignedCount = (int) ($unassigned['total_policies'] ?? 0);
        if ($unassignedCount > 0) {
            $calc = $calcHakedis('p.sold_by IS NULL', []);

            $result[] = [
                'userId' => null,
                'userName' => 'Atanmamış',
                'totalPolicies' => $unassignedCount,
                'totalCancelled' => (int) ($unassigned['total_cancelled'] ?? 0),
                'totalGross' => round((float) ($unassigned['total_gross'] ?? 0), 2),
                'totalNet' => round((float) ($unassigned['total_net'] ?? 0), 2),
                'totalCompanyComm' => round($calc['companyComm'], 2),
                'totalHakedis' => round($calc['hakedis'], 2),
                'isLocked' => false,
            ];

            $grandTotalHakedis += $calc['hakedis'];
            $grandTotalPolicies += $unassignedCount;
            $grandTotalGross += (float) ($unassigned['total_gross'] ?? 0);
            $grandTotalNet += (float) ($unassigned['total_net'] ?? 0);
        }

        Response::success([
            'mode' => 'summary',
            'users' => $result,
            'totals' => [
                'totalPolicies' => $grandTotalPolicies,
                'totalGross' => round($grandTotalGross, 2),
                'totalNet' => round($grandTotalNet, 2),
                'totalHakedis' => round($grandTotalHakedis, 2),
            ]
        ]);
    }

    private function unassignedReconciliation(array $user, int $year, int $month, string $dateType): void
    {
        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $dateFilter = "p.$dateType BETWEEN '$firstDay' AND '$lastDay'";

        $agencySetting = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'agency_name'");
        $agencyName = $agencySetting['value'] ?? '';

        $sql = "SELECT p.*,
                    cu.name as customer_name,
                    i.name as insurance_name,
                    co.name as company_name,
                    br.name as branch_name,
                    rs.name as ref_source_name,
                    rs.commission_rate as ref_commission_rate,
                    su.name as sold_by_name,
                    (SELECT COUNT(*) FROM policies pp
                       WHERE pp.customer_id = p.customer_id
                         AND pp.insurance_type_id = p.insurance_type_id
                         AND pp.deleted_at IS NULL
                         AND pp.parent_id IS NULL
                         AND pp.id <> p.id
                         AND pp.starts_at < p.starts_at) AS prev_count
                FROM policies p
                LEFT JOIN customers cu ON p.customer_id = cu.id
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN companies co ON p.company_id = co.id
                LEFT JOIN branches br ON p.branch_id = br.id
                LEFT JOIN reference_sources rs ON p.reference_source = rs.id
                LEFT JOIN users su ON p.sold_by = su.id
                WHERE p.sold_by IS NULL
                  AND p.deleted_at IS NULL
                  AND $dateFilter
                ORDER BY p.$dateType DESC";

        $policies = Database::fetchAll($sql);

        $totalGross = 0;
        $totalNet = 0;
        $totalCompanyComm = 0;
        $totalBranchComm = 0;
        $totalBizeKalan = 0;
        $totalHakedis = 0;
        $totalPolicies = 0;
        $totalCancelled = 0;
        $bySelf = 0;
        $byIncoming = 0;
        $byOutgoing = 0;

        $result = [];
        foreach ($policies as $p) {
            $grossAmount = (float) $p['gross_premium'];
            $netAmount = (float) $p['net_premium'];
            $companyCommRate = (float) ($p['company_comm_rate'] ?? 0);
            $branchCommRate = (float) ($p['branch_comm_rate'] ?? 0);
            $refCommRate = (float) ($p['ref_commission_rate'] ?? 0);

            $companyComm = (isset($p['company_comm_amount']) && $p['company_comm_amount'] !== null)
                ? (float) $p['company_comm_amount']
                : $netAmount * $companyCommRate / 100;
            $branchComm = (isset($p['branch_comm_amount']) && $p['branch_comm_amount'] !== null)
                ? (float) $p['branch_comm_amount']
                : $companyComm * $branchCommRate / 100;

            $bizeKalan = $companyComm;
            if ($p['production_type'] === 'OUTGOING') {
                $bizeKalan = $branchComm;
            } elseif ($p['production_type'] === 'INCOMING') {
                $bizeKalan = $companyComm - $branchComm;
            }

            $hakedis = $bizeKalan * $refCommRate / 100;

            $totalGross += $grossAmount;
            $totalNet += $netAmount;
            $totalCompanyComm += $companyComm;
            $totalBranchComm += $branchComm;
            $totalBizeKalan += $bizeKalan;
            $totalHakedis += $hakedis;
            $totalPolicies++;
            if ($p['is_cancelled'] == '1') $totalCancelled++;

            if ($p['production_type'] === 'SELF') $bySelf++;
            elseif ($p['production_type'] === 'INCOMING') $byIncoming++;
            elseif ($p['production_type'] === 'OUTGOING') $byOutgoing++;

            $result[] = [
                'id' => (int) $p['id'],
                'customerId' => (int) $p['customer_id'],
                'policyNo' => $p['policy_no'],
                'endorsementNo' => (int) $p['endorsement_no'],
                'isCancelled' => (bool) $p['is_cancelled'],
                'productionType' => $p['production_type'],
                'customerName' => $p['customer_name'] ?? '',
                'insuranceName' => $p['insurance_name'] ?? '',
                'companyName' => $p['company_name'] ?? '',
                'branchName' => (!empty($p['branch_name']) ? $p['branch_name'] : ($p['production_type'] === 'SELF' ? ($agencyName ?? '') : '')),
                'refSourceName' => $p['ref_source_name'] ?? null,
                'soldByName' => $p['sold_by_name'] ?? null,
                'policyType' => ((int) ($p['prev_count'] ?? 0)) > 0 ? 'Yenileme' : 'Yeni İş',
                'companyCommRate' => $companyCommRate,
                'branchCommRate' => $branchCommRate,
                'refCommRate' => $refCommRate,
                'issuedAt' => $p['issued_at'],
                'startsAt' => $p['starts_at'],
                'expiresAt' => $p['expires_at'],
                'grossPremium' => $grossAmount,
                'netPremium' => $netAmount,
                'companyComm' => round($companyComm, 2),
                'branchComm' => round($branchComm, 2),
                'bizeKalan' => round($bizeKalan, 2),
                'hakedis' => round($hakedis, 2),
                'plateNo' => $p['plate_no'] ?? null,
                'reconciliationStatus' => $p['reconciliation_status'] ?? 'PENDING',
            ];
        }

        Response::success([
            'user' => [
                'id' => 0,
                'name' => 'Atanmamış Poliçeler',
            ],
            'isLocked' => false,
            'stats' => [
                'totalPolicies' => $totalPolicies,
                'totalCancelled' => $totalCancelled,
                'totalGross' => round($totalGross, 2),
                'totalNet' => round($totalNet, 2),
                'totalCompanyComm' => round($totalCompanyComm, 2),
                'totalBranchComm' => round($totalBranchComm, 2),
                'totalBizeKalan' => round($totalBizeKalan, 2),
                'totalHakedis' => round($totalHakedis, 2),
                'bySelf' => $bySelf,
                'byIncoming' => $byIncoming,
                'byOutgoing' => $byOutgoing,
            ],
            'policies' => $result,
        ]);
    }

    public function reconciliationLock(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $userId = (int) ($input['userId'] ?? 0);
        $year = (int) ($input['year'] ?? 0);
        $month = (int) ($input['month'] ?? 0);
        $dateType = ($input['dateType'] ?? 'issued_at') === 'starts_at' ? 'starts_at' : 'issued_at';
        if (!$userId || !$year || !$month) {
            Response::error('Eksik parametreler', 400);
        }

        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $sql = "UPDATE policies
                SET reconciliation_status = 'RECONCILED', updated_at = NOW()
                WHERE sold_by = ?
                  AND deleted_at IS NULL
                  AND $dateType BETWEEN ? AND ?";

        Database::query($sql, [$userId, $firstDay, $lastDay]);

        Response::success(null, 'Mutabakat kilitlendi');
    }

    public function userReconciliationExport(array $user, array $query): void
    {
        AuthMiddleware::requireAdmin($user);

        $userId = (int) ($query['userId'] ?? 0);
        $year = (int) ($query['year'] ?? date('Y'));
        $month = (int) ($query['month'] ?? date('n'));
        $dateType = ($query['dateType'] ?? 'issued_at') === 'starts_at' ? 'starts_at' : 'issued_at';

        if (!$userId) {
            Response::error('Kullanici secilmedi', 400);
        }

        $targetUser = Database::fetch("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
        if (!$targetUser) {
            Response::error('Kullanici bulunamadi', 404);
        }

        $months = ['', 'Ocak', 'Subat', 'Mart', 'Nisan', 'Mayis', 'Haziran', 'Temmuz', 'Agustos', 'Eylul', 'Ekim', 'Kasim', 'Aralik'];

        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));
        $dateFilter = "p.$dateType BETWEEN '$firstDay' AND '$lastDay'";

        $sql = "SELECT p.*,
                    cu.name as customer_name,
                    i.name as insurance_name,
                    co.name as company_name,
                    br.name as branch_name,
                    rs.name as ref_source_name,
                    rs.commission_rate as ref_commission_rate,
                    su.name as sold_by_name,
                    (SELECT COUNT(*) FROM policies pp
                       WHERE pp.customer_id = p.customer_id
                         AND pp.insurance_type_id = p.insurance_type_id
                         AND pp.deleted_at IS NULL
                         AND pp.parent_id IS NULL
                         AND pp.id <> p.id
                         AND pp.starts_at < p.starts_at) AS prev_count
                FROM policies p
                LEFT JOIN customers cu ON p.customer_id = cu.id
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN companies co ON p.company_id = co.id
                LEFT JOIN branches br ON p.branch_id = br.id
                LEFT JOIN reference_sources rs ON p.reference_source = rs.id
                LEFT JOIN users su ON p.sold_by = su.id
                WHERE p.sold_by = ?
                  AND p.deleted_at IS NULL
                  AND $dateFilter
                ORDER BY p.$dateType DESC";

        $policies = Database::fetchAll($sql, [$userId]);

        $totalGross = 0;
        $totalNet = 0;
        $totalCompanyComm = 0;
        $totalBranchComm = 0;
        $totalBizeKalan = 0;
        $totalHakedis = 0;

        $rows = [];
        foreach ($policies as $p) {
            $grossAmount = (float) $p['gross_premium'];
            $netAmount = (float) $p['net_premium'];
            $companyCommRate = (float) ($p['company_comm_rate'] ?? 0);
            $branchCommRate = (float) ($p['branch_comm_rate'] ?? 0);
            $refCommRate = (float) ($p['ref_commission_rate'] ?? 0);

            // Kayitli komisyon tutari varsa onu kullan, yoksa orandan hesapla
            $companyComm = (isset($p['company_comm_amount']) && $p['company_comm_amount'] !== null)
                ? (float) $p['company_comm_amount']
                : $netAmount * $companyCommRate / 100;
            $branchComm = (isset($p['branch_comm_amount']) && $p['branch_comm_amount'] !== null)
                ? (float) $p['branch_comm_amount']
                : $companyComm * $branchCommRate / 100;

            // Bize kalan (hakedis): uretim tipine gore
            // SELF -> sirket komisyonu, OUTGOING -> acente komisyonu (odeme aliriz),
            // INCOMING -> sirket komisyonu - acente komisyonu (odeme yapariz)
            $bizeKalan = $companyComm;
            if ($p['production_type'] === 'OUTGOING') {
                $bizeKalan = $branchComm;
            } elseif ($p['production_type'] === 'INCOMING') {
                $bizeKalan = $companyComm - $branchComm;
            }
            $hakedis = $bizeKalan * $refCommRate / 100;

            $totalGross += $grossAmount;
            $totalNet += $netAmount;
            $totalCompanyComm += $companyComm;
            $totalBranchComm += $branchComm;
            $totalBizeKalan += $bizeKalan;
            $totalHakedis += $hakedis;

            $status = 'Aktif';
            if ($p['is_cancelled'] == '1') $status = 'Iptal';
            elseif ((int) $p['endorsement_no'] > 1) $status = 'Zeyil';

            $policyType = ((int) ($p['prev_count'] ?? 0)) > 0 ? 'Yenileme' : 'Yeni Is';
            $issued = $p['issued_at'] ?: '';
            $starts = $p['starts_at'] ?: '';

            $rows[] = [
                'branch_name' => ($p['production_type'] !== 'SELF' ? ($p['branch_name'] ?? '') : ''),
                'company_name' => $p['company_name'] ?? '',
                'sold_by_name' => $p['sold_by_name'] ?? '',
                'policy_no' => $p['policy_no'] . '/' . $p['endorsement_no'],
                'insurance_name' => $p['insurance_name'] ?? '',
                'customer_name' => $p['customer_name'] ?? '',
                'issued_at' => $issued,
                'starts_at' => $starts,
                'gross_premium' => $grossAmount,
                'net_premium' => $netAmount,
                'company_comm' => round($companyComm, 2),
                'status' => $status,
                'ref_source' => $p['ref_source_name'] ?? '',
                'hakedis' => round($hakedis, 2),
            ];
        }

        // Toplam satiri
        $rows[] = [
            'branch_name' => '',
            'company_name' => '',
            'sold_by_name' => '',
            'policy_no' => '',
            'insurance_name' => '',
            'customer_name' => 'TOPLAM',
            'issued_at' => '',
            'starts_at' => '',
            'gross_premium' => round($totalGross, 2),
            'net_premium' => round($totalNet, 2),
            'company_comm' => round($totalCompanyComm, 2),
            'status' => '',
            'ref_source' => '',
            'hakedis' => round($totalHakedis, 2),
        ];

        $columns = [
            ['key' => 'branch_name', 'label' => 'Tali Acente'],
            ['key' => 'company_name', 'label' => 'Sirket'],
            ['key' => 'sold_by_name', 'label' => 'Satis Yapan Temsilci'],
            ['key' => 'policy_no', 'label' => 'Police No', 'type' => Response::COL_IDENTIFIER],
            ['key' => 'insurance_name', 'label' => 'Alt Urun'],
            ['key' => 'customer_name', 'label' => 'Musteri Ad Soyad'],
            ['key' => 'issued_at', 'label' => 'Tanzim Tarihi', 'type' => Response::COL_DATE],
            ['key' => 'starts_at', 'label' => 'Baslangic Tarihi', 'type' => Response::COL_DATE],
            ['key' => 'gross_premium', 'label' => 'Brut Prim', 'type' => Response::COL_CURRENCY],
            ['key' => 'net_premium', 'label' => 'Net Prim', 'type' => Response::COL_CURRENCY],
            ['key' => 'company_comm', 'label' => 'Net Acente Komisyon', 'type' => Response::COL_CURRENCY],
            ['key' => 'status', 'label' => 'Durum'],
            ['key' => 'ref_source', 'label' => 'Referans Kaynagi'],
            ['key' => 'hakedis', 'label' => 'Hak Edis', 'type' => Response::COL_CURRENCY],
        ];

        $filename = 'calisan_mutabakat_' . $targetUser['name'] . '_' . $months[$month] . '_' . $year . '.xlsx';
        $filename = str_replace(' ', '_', $filename);

        Response::xlsx($rows, $columns, $filename);
    }

    private function normalizeSearch(string $q): string
    {
        // Turkce karakter normalize
        $map = ['ş'=>'s','Ş'=>'S','ı'=>'i','İ'=>'I','ğ'=>'g','Ğ'=>'G','ü'=>'u','Ü'=>'U','ö'=>'o','Ö'=>'O','ç'=>'c','Ç'=>'C'];
        $q = strtr($q, $map);
        // Bosluk, tire, nokta temizle (plaka icin)
        $q = preg_replace('/[\s\-\.]/', '', $q);
        return mb_strtolower($q);
    }

    public function search(array $user, array $query): void
    {
        $q = trim($query['q'] ?? '');
        if (mb_strlen($q) < 2) {
            Response::success(['customers' => [], 'policies' => []]);
            return;
        }

        $search = '%' . $q . '%';
        $normalized = '%' . $this->normalizeSearch($q) . '%';

        $phoneNormalized = '%' . preg_replace('/[\s\-\(\)\+]/', '', $q) . '%';

        $startsWith = $q . '%';

        $customers = Database::fetchAll(
            "SELECT id, name, phone, identity_no, customer_type FROM customers
             WHERE deleted_at IS NULL AND (
               name LIKE ? OR identity_no LIKE ? OR phone LIKE ?
               OR REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') LIKE ?
               OR REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') LIKE ?
               OR LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name,'ş','s'),'Ş','S'),'ı','i'),'İ','I'),'ğ','g'),'Ğ','G'),'ü','u'),'Ü','U'),'ö','o'),'Ö','O'),'ç','c'),'Ç','C')) LIKE ?
             )
             ORDER BY
               CASE WHEN name LIKE ? THEN 0 ELSE 1 END,
               name
             LIMIT 10",
            [$search, $search, $search, $search, $phoneNormalized, $normalized, $startsWith]
        );

        $policyNormalized = '%' . preg_replace('/[\s\-\.]/', '', $q) . '%';
        $plateNormalized  = '%' . preg_replace('/[\s\-\.]/', '', mb_strtoupper($q)) . '%';

        $policies = Database::fetchAll(
            "SELECT p.id, p.customer_id, p.policy_no, p.plate_no, cu.name as customer_name,
                    p.insured_name, p.additional_insureds
             FROM policies p
             LEFT JOIN customers cu ON p.customer_id = cu.id
             WHERE p.deleted_at IS NULL AND p.parent_id IS NULL
               AND (
                 p.policy_no LIKE ? OR REPLACE(REPLACE(p.policy_no,'-',''),' ','') LIKE ?
                 OR cu.name LIKE ? OR cu.identity_no LIKE ?
                 OR UPPER(REPLACE(REPLACE(REPLACE(p.plate_no,' ',''),'-',''),'.','')) LIKE ?
                 OR p.insured_name LIKE ?
                 OR p.additional_insureds LIKE ?
                 OR LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(cu.name,'ş','s'),'Ş','S'),'ı','i'),'İ','I'),'ğ','g'),'Ğ','G'),'ü','u'),'Ü','U'),'ö','o'),'Ö','O'),'ç','c'),'Ç','C')) LIKE ?
               )
             LIMIT 10",
            [$search, $policyNormalized, $search, $search, $plateNormalized, $search, $search, $normalized]
        );

        Response::success([
            'customers' => array_map(fn($c) => [
                'id' => (int) $c['id'],
                'name' => $c['name'],
                'phone' => $c['phone'],
                'customerType' => $c['customer_type'],
            ], $customers),
            'policies' => array_map(function($p) use ($q) {
                // Ek sigortalı üzerinden mi bulundu? — göstermek için hint
                $matchedInsured = null;
                if (!empty($p['additional_insureds']) && stripos($p['additional_insureds'], $q) !== false) {
                    $list = json_decode($p['additional_insureds'], true);
                    if (is_array($list)) {
                        foreach ($list as $ins) {
                            if (stripos($ins['name'] ?? '', $q) !== false || stripos($ins['tc'] ?? '', $q) !== false) {
                                $matchedInsured = $ins['name'] ?? null;
                                break;
                            }
                        }
                    }
                    if (!$matchedInsured) $matchedInsured = $q; // eski virgüllü format için
                }
                return [
                    'id' => (int) $p['id'],
                    'customerId' => (int) $p['customer_id'],
                    'policyNo' => $p['policy_no'],
                    'plateNo' => $p['plate_no'],
                    'customerName' => $p['customer_name'],
                    'insuredName' => $p['insured_name'],
                    'matchedInsured' => $matchedInsured,
                ];
            }, $policies),
        ]);
    }

    public function taskCounts(array $user, array $query): void
    {
        $year = (int) ($query['year'] ?? date('Y'));
        $month = (int) ($query['month'] ?? date('m'));

        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime($firstDay));

        $assignFilter = '';
        $params = [$firstDay, $lastDay];

        if ((int) $user['role'] === 2) {
            $assignFilter = " AND t.assigned_to = ?";
            $params[] = $user['id'];
        }

        $rows = Database::fetchAll(
            "SELECT COALESCE(t.offer_expires_at, DATE(t.deadline)) as task_date, COUNT(*) as cnt
             FROM tasks t
             WHERE t.deleted_at IS NULL
               AND t.type IN ('RENEWAL', 'OFFER', 'REFERENCE', 'FOLLOW_UP_CALL')
               AND t.status IN ('PENDING', 'IN_PROGRESS')
               AND COALESCE(t.offer_expires_at, DATE(t.deadline)) BETWEEN ? AND ?
               $assignFilter
             GROUP BY task_date",
            $params
        );

        $counts = [];
        foreach ($rows as $r) {
            if ($r['task_date']) {
                $counts[$r['task_date']] = (int) $r['cnt'];
            }
        }

        Response::success($counts);
    }

    public function portfolio(array $user, array $query): void
    {
        Permission::require($user, 'portfolio.view');
        $currentYear = (int) ($query['year'] ?? date('Y'));
        $prevYear = $currentYear - 1;

        $view = $query['view'] ?? 'all';
        $productionFilter = match($view) {
            'self'     => "AND p.production_type = 'SELF'",
            'incoming' => "AND p.production_type = 'INCOMING'",
            'outgoing' => "AND p.production_type = 'OUTGOING'",
            default    => '',
        };

        // Ay isimleri
        $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

        // Brüt prim ve adet, aylık bazda
        // Her kayit kendi issued_at ayina yazilir. Iptal zeyilleri negatif gross_premium ile kaydedilir.
        // issued_at (tanzim tarihi) üzerinden gruplama
        $sql = "SELECT
                    YEAR(p.issued_at) as y,
                    MONTH(p.issued_at) as m,
                    COALESCE(SUM(p.gross_premium), 0) as total_premium,
                    COALESCE(SUM(p.net_premium), 0) as total_net_premium,
                    COUNT(CASE WHEN p.is_cancelled = 0 THEN 1 END) as policy_count,
                    COALESCE(SUM(
                        CASE WHEN p.company_comm_amount IS NOT NULL AND p.company_comm_amount != 0
                             THEN p.company_comm_amount
                             ELSE p.gross_premium * p.company_comm_rate / 100
                        END
                    ), 0) as total_commission,
                    COALESCE(SUM(
                        CASE WHEN p.branch_comm_amount IS NOT NULL AND p.branch_comm_amount != 0
                             THEN p.branch_comm_amount
                             ELSE (
                                 CASE WHEN p.company_comm_amount IS NOT NULL AND p.company_comm_amount != 0
                                      THEN p.company_comm_amount
                                      ELSE p.gross_premium * p.company_comm_rate / 100
                                 END
                             ) * p.branch_comm_rate / 100
                        END
                    ), 0) as branch_commission
                FROM policies p
                WHERE p.deleted_at IS NULL
                  $productionFilter
                  AND YEAR(p.issued_at) IN (?, ?)
                GROUP BY YEAR(p.issued_at), MONTH(p.issued_at)
                ORDER BY y, m";

        $rows = Database::fetchAll($sql, [$currentYear, $prevYear]);

        // Ay bazlı index
        $data = [];
        foreach ($rows as $r) {
            $key = $r['y'] . '-' . $r['m'];
            $data[$key] = [
                'premium'           => (float) $r['total_premium'],
                'net_premium'       => (float) $r['total_net_premium'],
                'count'             => (int)   $r['policy_count'],
                'commission'        => (float) $r['total_commission'],
                'branch_commission' => (float) $r['branch_commission'],
            ];
        }

        $result = [];
        $totalCurrentPrem        = 0; $totalPrevPrem        = 0;
        $totalCurrentNet         = 0; $totalPrevNet         = 0;
        $totalCurrentCount       = 0; $totalPrevCount       = 0;
        $totalCurrentComm        = 0; $totalPrevComm        = 0;
        $totalCurrentBranchComm  = 0; $totalPrevBranchComm  = 0;

        // Mevcut yil ise sadece bugune kadarki aylari goster, gecmis yillar icin 12 ay
        $nowYear = (int) date('Y');
        $nowMonth = (int) date('m');
        $maxMonth = ($currentYear >= $nowYear) ? $nowMonth : 12;

        for ($m = 1; $m <= $maxMonth; $m++) {
            $curKey  = $currentYear . '-' . $m;
            $prevKey = $prevYear    . '-' . $m;

            $curPrem  = $data[$curKey]['premium']     ?? 0;
            $prevPrem = $data[$prevKey]['premium']    ?? 0;
            $curNet   = $data[$curKey]['net_premium'] ?? 0;
            $prevNet  = $data[$prevKey]['net_premium']?? 0;
            $curCount  = $data[$curKey]['count']   ?? 0;
            $prevCount = $data[$prevKey]['count']  ?? 0;
            $curComm        = $data[$curKey]['commission']         ?? 0;
            $prevComm       = $data[$prevKey]['commission']        ?? 0;
            $curBranchComm  = $data[$curKey]['branch_commission']  ?? 0;
            $prevBranchComm = $data[$prevKey]['branch_commission'] ?? 0;

            $premChange    = $prevPrem  > 0 ? round((($curPrem  - $prevPrem)  / $prevPrem  * 100), 2) : ($curPrem  > 0 ? 100 : 0);
            $netPremChange = $prevNet   > 0 ? round((($curNet   - $prevNet)   / $prevNet   * 100), 2) : ($curNet   > 0 ? 100 : 0);
            $countChange   = $prevCount > 0 ? round((($curCount - $prevCount) / $prevCount * 100), 2) : ($curCount > 0 ? 100 : 0);
            $commChange    = $prevComm  > 0 ? round((($curComm  - $prevComm)  / $prevComm  * 100), 2) : ($curComm  > 0 ? 100 : 0);

            $totalCurrentPrem       += $curPrem;       $totalPrevPrem       += $prevPrem;
            $totalCurrentNet        += $curNet;        $totalPrevNet        += $prevNet;
            $totalCurrentCount      += $curCount;      $totalPrevCount      += $prevCount;
            $totalCurrentComm       += $curComm;       $totalPrevComm       += $prevComm;
            $totalCurrentBranchComm += $curBranchComm; $totalPrevBranchComm += $prevBranchComm;

            $result[] = [
                'month'                  => $months[$m - 1],
                'currentPremium'         => $curPrem,
                'prevPremium'            => $prevPrem,
                'premiumChange'          => $premChange,
                'currentNetPremium'      => $curNet,
                'prevNetPremium'         => $prevNet,
                'netPremiumChange'       => $netPremChange,
                'currentCount'           => $curCount,
                'prevCount'              => $prevCount,
                'countChange'            => $countChange,
                'currentCommission'      => round($curComm, 2),
                'prevCommission'         => round($prevComm, 2),
                'commissionChange'       => $commChange,
                'currentBranchCommission'=> round($curBranchComm, 2),
                'prevBranchCommission'   => round($prevBranchComm, 2),
            ];
        }

        $totalPremChange    = $totalPrevPrem  > 0 ? round((($totalCurrentPrem  - $totalPrevPrem)  / $totalPrevPrem  * 100), 2) : ($totalCurrentPrem  > 0 ? 100 : 0);
        $totalNetPremChange = $totalPrevNet   > 0 ? round((($totalCurrentNet   - $totalPrevNet)   / $totalPrevNet   * 100), 2) : ($totalCurrentNet   > 0 ? 100 : 0);
        $totalCountChange   = $totalPrevCount > 0 ? round((($totalCurrentCount - $totalPrevCount) / $totalPrevCount * 100), 2) : ($totalCurrentCount > 0 ? 100 : 0);
        $totalCommChange    = $totalPrevComm  > 0 ? round((($totalCurrentComm  - $totalPrevComm)  / $totalPrevComm  * 100), 2) : ($totalCurrentComm  > 0 ? 100 : 0);

        // Urun bazli aylik uretim
        $productGroups = [
            'TSS' => ['TSS'],
            'ÖSS' => ['ÖSS'],
            'TRAFİK' => ['TRAFİK', 'KARAYOLU MOTORLU ARAÇLAR ZORUNLU MALİ SORUMLULUK SİGORTASI TRAFİK'],
            'KASKO' => ['KASKO'],
            'DASK' => ['DASK'],
            'KONUT' => ['KONUT'],
            'İŞYERİ' => ['İŞYERİ'],
        ];

        $allNames = [];
        foreach ($productGroups as $names) {
            $allNames = array_merge($allNames, $names);
        }
        $placeholders = implode(',', array_fill(0, count($allNames), '?'));

        $productSql = "SELECT
                            it.name as insurance_name,
                            MONTH(p.issued_at) as m,
                            COALESCE(SUM(p.gross_premium), 0) as total_premium,
                            COALESCE(SUM(p.net_premium), 0) as total_net_premium,
                            COUNT(CASE WHEN p.is_cancelled = 0 THEN 1 END) as policy_count,
                            COALESCE(SUM(
                                CASE WHEN p.company_comm_amount IS NOT NULL AND p.company_comm_amount != 0
                                     THEN p.company_comm_amount
                                     ELSE p.gross_premium * p.company_comm_rate / 100
                                END
                            ), 0) as total_commission
                        FROM policies p
                        JOIN insurance_types it ON p.insurance_type_id = it.id
                        WHERE p.deleted_at IS NULL
                          $productionFilter
                          AND YEAR(p.issued_at) = ?
                          AND it.name IN ($placeholders)
                        GROUP BY it.name, MONTH(p.issued_at)
                        ORDER BY m";

        $productRows = Database::fetchAll($productSql, array_merge([$currentYear], $allNames));

        // Grupla
        $productData    = [];
        $productNetData = [];
        $productCountData = [];
        $productCommData  = [];
        foreach ($productRows as $r) {
            foreach ($productGroups as $groupName => $names) {
                if (in_array($r['insurance_name'], $names)) {
                    $key = $groupName . '-' . $r['m'];
                    $productData[$key]    = ($productData[$key]    ?? 0) + (float) $r['total_premium'];
                    $productNetData[$key] = ($productNetData[$key] ?? 0) + (float) $r['total_net_premium'];
                    $productCountData[$key] = ($productCountData[$key] ?? 0) + (int) $r['policy_count'];
                    $productCommData[$key]  = ($productCommData[$key]  ?? 0) + (float) $r['total_commission'];
                    break;
                }
            }
        }

        // "Diğer": yukaridaki gruplara girmeyen tum policeler
        $digerSql = "SELECT
                        MONTH(p.issued_at) as m,
                        COALESCE(SUM(p.gross_premium), 0) as total_premium,
                        COALESCE(SUM(p.net_premium), 0) as total_net_premium,
                        COUNT(CASE WHEN p.is_cancelled = 0 THEN 1 END) as policy_count,
                        COALESCE(SUM(p.gross_premium * p.company_comm_rate / 100), 0) as total_commission
                     FROM policies p
                     LEFT JOIN insurance_types it ON p.insurance_type_id = it.id
                     WHERE p.deleted_at IS NULL
                       $productionFilter
                       AND YEAR(p.issued_at) = ?
                       AND (it.name IS NULL OR it.name NOT IN ($placeholders))
                     GROUP BY MONTH(p.issued_at)
                     ORDER BY m";
        $digerRows = Database::fetchAll($digerSql, array_merge([$currentYear], $allNames));
        foreach ($digerRows as $r) {
            $key = 'Diğer-' . $r['m'];
            $productData[$key]      = (float) $r['total_premium'];
            $productNetData[$key]   = (float) $r['total_net_premium'];
            $productCountData[$key] = (int)   $r['policy_count'];
            $productCommData[$key]  = (float) $r['total_commission'];
        }
        $productGroups['Diğer'] = [];

        $productResult      = [];
        $productTotals      = array_fill_keys(array_keys($productGroups), 0);
        $productNetTotals   = array_fill_keys(array_keys($productGroups), 0);
        $productCountTotals = array_fill_keys(array_keys($productGroups), 0);
        $productCommTotals  = array_fill_keys(array_keys($productGroups), 0);

        for ($m = 1; $m <= $maxMonth; $m++) {
            $row = ['month' => $months[$m - 1]];
            foreach ($productGroups as $groupName => $names) {
                $val  = $productData[$groupName . '-' . $m]    ?? 0;
                $net  = $productNetData[$groupName . '-' . $m] ?? 0;
                $cnt  = $productCountData[$groupName . '-' . $m] ?? 0;
                $comm = $productCommData[$groupName . '-' . $m]  ?? 0;
                $row[$groupName]               = $val;
                $row[$groupName . '_net']      = $net;
                $row[$groupName . '_count']    = $cnt;
                $row[$groupName . '_comm']     = round($comm, 2);
                $productTotals[$groupName]    += $val;
                $productNetTotals[$groupName] += $net;
                $productCountTotals[$groupName] += $cnt;
                $productCommTotals[$groupName]  += $comm;
            }
            $productResult[] = $row;
        }

        // OUTGOING modunda sirket bazli komisyon kırılımı
        $outgoingByCompany = [];
        if ($view === 'outgoing') {
            $companySql = "SELECT
                co.name as company_name,
                YEAR(p.issued_at) as yr,
                COUNT(CASE WHEN p.is_cancelled = 0 THEN 1 END) as policy_count,
                COALESCE(SUM(p.gross_premium), 0) as total_premium,
                COALESCE(SUM(
                    CASE WHEN p.company_comm_amount IS NOT NULL AND p.company_comm_amount != 0
                         THEN p.company_comm_amount
                         ELSE p.gross_premium * p.company_comm_rate / 100
                    END
                ), 0) as company_comm,
                COALESCE(SUM(
                    CASE WHEN p.branch_comm_amount IS NOT NULL AND p.branch_comm_amount != 0
                         THEN p.branch_comm_amount
                         ELSE (
                             CASE WHEN p.company_comm_amount IS NOT NULL AND p.company_comm_amount != 0
                                  THEN p.company_comm_amount
                                  ELSE p.gross_premium * p.company_comm_rate / 100
                             END
                         ) * p.branch_comm_rate / 100
                    END
                ), 0) as branch_comm,
                AVG(p.branch_comm_rate) as avg_branch_rate
            FROM policies p
            JOIN companies co ON p.company_id = co.id
            WHERE p.deleted_at IS NULL
              AND p.production_type = 'OUTGOING'
              AND YEAR(p.issued_at) IN (?, ?)
            GROUP BY co.id, co.name, YEAR(p.issued_at)
            ORDER BY co.name, yr";

            $companyRows = Database::fetchAll($companySql, [$currentYear, $prevYear]);

            // Yıla göre grupla
            $byCompany = [];
            foreach ($companyRows as $cr) {
                $name = $cr['company_name'];
                $yr   = (int) $cr['yr'];
                if (!isset($byCompany[$name])) $byCompany[$name] = [];
                $companyComm = (float) $cr['company_comm'];
                $branchComm  = (float) $cr['branch_comm'];
                $byCompany[$name][$yr] = [
                    'count'         => (int) $cr['policy_count'],
                    'grossPremium'  => (float) $cr['total_premium'],
                    'premium'       => $companyComm,
                    'received'      => $branchComm,
                    'lost'          => $companyComm - $branchComm,
                    'avgBranchRate' => round((float) $cr['avg_branch_rate'], 1),
                ];
            }

            foreach ($byCompany as $name => $years) {
                $cur  = $years[$currentYear] ?? ['count' => 0, 'grossPremium' => 0, 'premium' => 0, 'received' => 0, 'lost' => 0, 'avgBranchRate' => 0];
                $prev = $years[$prevYear]    ?? ['count' => 0, 'grossPremium' => 0, 'premium' => 0, 'received' => 0, 'lost' => 0, 'avgBranchRate' => 0];
                $lostChange = $prev['lost'] > 0
                    ? round(($cur['lost'] - $prev['lost']) / $prev['lost'] * 100, 1)
                    : ($cur['lost'] > 0 ? 100 : 0);
                $outgoingByCompany[] = [
                    'company'        => $name,
                    'count'          => $cur['count'],
                    'grossPremium'   => round($cur['grossPremium'], 2),
                    'lost'           => round($cur['lost'], 2),
                    'received'       => round($cur['received'], 2),
                    'premium'        => round($cur['premium'], 2),
                    'avgBranchRate'  => $cur['avgBranchRate'],
                    'prevLost'       => round($prev['lost'], 2),
                    'prevCount'      => $prev['count'],
                    'lostChange'     => $lostChange,
                ];
            }
            // Mevcut yil kayibina gore sirala
            usort($outgoingByCompany, fn($a, $b) => $b['lost'] <=> $a['lost']);

            // Production type bazlı sayım (SELF vs OUTGOING karşılaştırması için)
            $typeBreakdown = Database::fetchAll(
                "SELECT production_type,
                        COUNT(CASE WHEN is_cancelled = 0 THEN 1 END) as cnt,
                        COALESCE(SUM(gross_premium), 0) as premium
                 FROM policies
                 WHERE deleted_at IS NULL AND YEAR(issued_at) = ?
                 GROUP BY production_type",
                [$currentYear]
            );
            $outgoingTotalCount = array_sum(array_column($outgoingByCompany, 'count'));
        }
        $selfCount      = 0; $selfPremium    = 0;
        $outgoingCount  = 0; $outgoingPremium= 0;
        $incomingCount  = 0;
        if (isset($typeBreakdown)) {
            foreach ($typeBreakdown as $tb) {
                if ($tb['production_type'] === 'SELF')     { $selfCount = (int)$tb['cnt']; $selfPremium = (float)$tb['premium']; }
                if ($tb['production_type'] === 'OUTGOING') { $outgoingCount = (int)$tb['cnt']; $outgoingPremium = (float)$tb['premium']; }
                if ($tb['production_type'] === 'INCOMING') { $incomingCount = (int)$tb['cnt']; }
            }
        }

        Response::success([
            'currentYear' => $currentYear,
            'prevYear'    => $prevYear,
            'months'      => $result,
            'totals'      => [
                'currentPremium'    => $totalCurrentPrem,
                'prevPremium'       => $totalPrevPrem,
                'premiumChange'     => $totalPremChange,
                'currentNetPremium' => $totalCurrentNet,
                'prevNetPremium'    => $totalPrevNet,
                'netPremiumChange'  => $totalNetPremChange,
                'currentCount'      => $totalCurrentCount,
                'prevCount'         => $totalPrevCount,
                'countChange'       => $totalCountChange,
                'currentCommission'       => round($totalCurrentComm, 2),
                'prevCommission'         => round($totalPrevComm, 2),
                'commissionChange'       => $totalCommChange,
                'currentBranchCommission'=> round($totalCurrentBranchComm, 2),
                'prevBranchCommission'   => round($totalPrevBranchComm, 2),
                'lostCommission'         => round($totalCurrentComm - $totalCurrentBranchComm, 2),
            ],
            'productMonths'        => $productResult,
            'productTotals'        => $productTotals,
            'productNetTotals'     => $productNetTotals,
            'productCountTotals'   => $productCountTotals,
            'productCommTotals'    => array_map(fn($v) => round($v, 2), $productCommTotals),
            'productGroups'        => array_keys($productGroups),
            'outgoingByCompany'    => $outgoingByCompany,
            'selfCount'            => $selfCount,
            'selfPremium'          => round($selfPremium, 2),
            'outgoingCount'        => $outgoingCount,
            'outgoingPremium'      => round($outgoingPremium, 2),
            'incomingCount'        => $incomingCount,
        ]);
    }

    /**
     * Tahmin icin gecmis yillarin aylik verisini dondurur
     * GET /api/dashboard/portfolio-forecast?year=2026
     */
    public function portfolioForecast(array $user, array $query): void
    {
        $currentYear = (int) ($query['year'] ?? date('Y'));
        $minYear = $currentYear - 4; // son 5 yil

        $view = $query['view'] ?? 'all';
        $productionFilter = match($view) {
            'self'     => "AND p.production_type = 'SELF'",
            'incoming' => "AND p.production_type = 'INCOMING'",
            'outgoing' => "AND p.production_type = 'OUTGOING'",
            default    => '',
        };

        // Genel aylik prim, adet ve komisyon
        $sql = "SELECT
                    YEAR(p.issued_at) as y,
                    MONTH(p.issued_at) as m,
                    COALESCE(SUM(p.gross_premium), 0) as total_premium,
                    COUNT(CASE WHEN p.is_cancelled = 0 THEN 1 END) as policy_count,
                    COALESCE(SUM(
                        CASE WHEN p.company_comm_amount IS NOT NULL AND p.company_comm_amount != 0
                             THEN p.company_comm_amount
                             ELSE p.gross_premium * p.company_comm_rate / 100
                        END
                    ), 0) as total_commission
                FROM policies p
                WHERE p.deleted_at IS NULL
                  $productionFilter
                  AND YEAR(p.issued_at) >= ?
                GROUP BY YEAR(p.issued_at), MONTH(p.issued_at)
                ORDER BY y, m";

        $rows = Database::fetchAll($sql, [$minYear]);

        $history = [];
        foreach ($rows as $r) {
            $history[] = [
                'year' => (int) $r['y'],
                'month' => (int) $r['m'],
                'premium' => (float) $r['total_premium'],
                'count' => (int) $r['policy_count'],
                'commission' => (float) $r['total_commission'],
            ];
        }

        // Urun bazli
        $productGroups = [
            'TSS' => ['TSS'],
            'ÖSS' => ['ÖSS'],
            'TRAFİK' => ['TRAFİK', 'KARAYOLU MOTORLU ARAÇLAR ZORUNLU MALİ SORUMLULUK SİGORTASI TRAFİK'],
            'KASKO' => ['KASKO'],
            'DASK' => ['DASK'],
            'KONUT' => ['KONUT'],
            'İŞYERİ' => ['İŞYERİ'],
        ];

        $allNames = [];
        foreach ($productGroups as $names) {
            $allNames = array_merge($allNames, $names);
        }
        $placeholders = implode(',', array_fill(0, count($allNames), '?'));

        $productSql = "SELECT
                            YEAR(p.issued_at) as y,
                            MONTH(p.issued_at) as m,
                            it.name as insurance_name,
                            COALESCE(SUM(p.gross_premium), 0) as total_premium,
                            COUNT(CASE WHEN p.is_cancelled = 0 THEN 1 END) as policy_count,
                            COALESCE(SUM(
                                CASE WHEN p.company_comm_amount IS NOT NULL AND p.company_comm_amount != 0
                                     THEN p.company_comm_amount
                                     ELSE p.gross_premium * p.company_comm_rate / 100
                                END
                            ), 0) as total_commission
                        FROM policies p
                        JOIN insurance_types it ON p.insurance_type_id = it.id
                        WHERE p.deleted_at IS NULL
                          $productionFilter
                          AND YEAR(p.issued_at) >= ?
                          AND it.name IN ($placeholders)
                        GROUP BY YEAR(p.issued_at), MONTH(p.issued_at), it.name
                        ORDER BY y, m";

        $productRows = Database::fetchAll($productSql, array_merge([$minYear], $allNames));

        $productHistory = [];
        foreach ($productRows as $r) {
            foreach ($productGroups as $groupName => $names) {
                if (in_array($r['insurance_name'], $names)) {
                    $key = $r['y'] . '-' . $r['m'] . '-' . $groupName;
                    if (!isset($productHistory[$key])) {
                        $productHistory[$key] = [
                            'year' => (int) $r['y'],
                            'month' => (int) $r['m'],
                            'group' => $groupName,
                            'premium' => 0,
                            'count' => 0,
                            'commission' => 0,
                        ];
                    }
                    $productHistory[$key]['premium'] += (float) $r['total_premium'];
                    $productHistory[$key]['count'] += (int) $r['policy_count'];
                    $productHistory[$key]['commission'] += (float) $r['total_commission'];
                    break;
                }
            }
        }

        // "Diğer": yukaridaki gruplara girmeyen tum policeler (gecmis yillar dahil)
        $digerForecastSql = "SELECT
                                YEAR(p.issued_at) as y,
                                MONTH(p.issued_at) as m,
                                COALESCE(SUM(p.gross_premium), 0) as total_premium,
                                COUNT(CASE WHEN p.is_cancelled = 0 THEN 1 END) as policy_count,
                                COALESCE(SUM(p.gross_premium * p.company_comm_rate / 100), 0) as total_commission
                             FROM policies p
                             LEFT JOIN insurance_types it ON p.insurance_type_id = it.id
                             WHERE p.deleted_at IS NULL
                               $productionFilter
                               AND YEAR(p.issued_at) >= ?
                               AND (it.name IS NULL OR it.name NOT IN ($placeholders))
                             GROUP BY YEAR(p.issued_at), MONTH(p.issued_at)
                             ORDER BY y, m";
        $digerForecastRows = Database::fetchAll($digerForecastSql, array_merge([$minYear], $allNames));
        foreach ($digerForecastRows as $r) {
            $key = $r['y'] . '-' . $r['m'] . '-Diğer';
            $productHistory[$key] = [
                'year'       => (int)   $r['y'],
                'month'      => (int)   $r['m'],
                'group'      => 'Diğer',
                'premium'    => (float) $r['total_premium'],
                'count'      => (int)   $r['policy_count'],
                'commission' => (float) $r['total_commission'],
            ];
        }
        $productGroups['Diğer'] = [];

        Response::success([
            'currentYear' => $currentYear,
            'history' => $history,
            'productHistory' => array_values($productHistory),
            'productGroups' => array_keys($productGroups),
        ]);
    }

    /**
     * Satış Performansı — poliçe bazlı.
     * GET /dashboard/sales-performance?month=9&year=2026&soldBy=6
     */
    public function salesPerformance(array $user, array $query): void
    {
        Permission::require($user, 'performance.view');

        $month = (int) ($query['month'] ?? date('n'));
        $year = (int) ($query['year'] ?? date('Y'));
        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $where = [
            "p.deleted_at IS NULL",
            "p.issued_at BETWEEN ? AND ?",
            "(p.parent_id IS NULL OR p.is_cancelled = 1)"
        ];
        $params = [$firstDay, $lastDay];

        // Temsilci filtresi
        if (!empty($query['soldBy'])) {
            $where[] = "p.sold_by = ?";
            $params[] = (int) $query['soldBy'];
        } elseif ((int) $user['role'] !== 1) {
            $where[] = "p.sold_by = ?";
            $params[] = $user['userId'];
        }

        $whereSql = implode(' AND ', $where);

        // İstatistikler — dedup edilmiş poliçeler üzerinden
        $stats = Database::fetch(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN p.business_type = 'NEW' AND p.is_cancelled = 0 THEN 1 ELSE 0 END) as new_count,
                SUM(CASE WHEN p.business_type = 'RENEWAL' AND p.is_cancelled = 0 THEN 1 ELSE 0 END) as renewal_count,
                SUM(p.gross_premium) as total_gross,
                SUM(p.net_premium) as total_net,
                SUM(CASE WHEN p.is_cancelled = 1 THEN 1 ELSE 0 END) as cancelled
             FROM policies p WHERE $whereSql",
            $params
        );

        // Poliçe listesi — aynı policy_no toplam primi ile
        $policies = Database::fetchAll(
            "SELECT p.id, p.policy_no, p.gross_premium, p.net_premium,
                    p.issued_at, p.plate_no, p.business_type, p.is_cancelled, p.production_type,
                    cu.name as customer_name, cu.id as customer_id, cu.identity_no as customer_identity,
                    i.name as insurance_name, i.color as insurance_color, co.name as company_name,
                    rs.name as reference_source_name, u.name as sold_by_name
             FROM policies p
             LEFT JOIN customers cu ON p.customer_id = cu.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN companies co ON p.company_id = co.id
             LEFT JOIN reference_sources rs ON p.reference_source = rs.id
             LEFT JOIN users u ON p.sold_by = u.id
             WHERE $whereSql
             ORDER BY p.issued_at ASC, p.id ASC",
            $params
        );

        Response::success([
            'stats' => [
                'total' => (int) $stats['total'],
                'new' => (int) $stats['new_count'],
                'renewal' => (int) $stats['renewal_count'],
                'totalGross' => round((float) $stats['total_gross'], 2),
                'totalNet' => round((float) $stats['total_net'], 2),
                'cancelled' => (int) $stats['cancelled'],
            ],
            'policies' => array_map(fn($p) => [
                'id' => (int) $p['id'],
                'policyNo' => $p['policy_no'],
                'customerName' => $p['customer_name'] ?? '',
                'customerId' => $p['customer_id'] ? (int) $p['customer_id'] : null,
                'customerIdentity' => $p['customer_identity'] ?? '',
                'insuranceName' => $p['insurance_name'] ?? '',
                'insuranceColor' => $p['insurance_color'] ?? '',
                'companyName' => $p['company_name'] ?? '',
                'grossPremium' => (float) $p['gross_premium'],
                'netPremium' => (float) $p['net_premium'],
                'plateNo' => $p['plate_no'] ?? '',
                'businessType' => $p['business_type'] ?? null,
                'isCancelled' => (bool) $p['is_cancelled'],
                'referenceSourceName' => $p['reference_source_name'] ?? '',
                'soldByName' => $p['sold_by_name'] ?? '',
                'issuedAt' => $p['issued_at'],
            ], $policies),
        ]);
    }

    public function taskPerformance(array $user, array $query): void
    {
        Permission::require($user, 'performance.view');
        $month = (int) ($query['month'] ?? date('n'));
        $year = (int) ($query['year'] ?? date('Y'));

        $assignFilter = '';
        $assignParams = [];
        if ((int) $user['role'] !== 1) {
            $assignFilter = " AND t.assigned_to = ?";
            $assignParams = [$user['userId']];
        }

        $baseParams = array_merge([$year, $month], $assignParams);

        // Basarili sonuclar
        $successResults = "'RENEWED','OFFER_APPROVED','DONE'";
        // Basarisiz sonuclar
        $failResults = "'NOT_RENEWED','OFFER_REJECTED','FAILED'";

        $renewableFilter = " AND (t.policy_id IS NULL OR EXISTS (SELECT 1 FROM policies pp INNER JOIN insurance_types it ON it.id = pp.insurance_type_id AND it.is_renewable = 1 WHERE pp.id = t.policy_id))";

        // Tum gorevler listesi
        $allDetails = Database::fetchAll(
            "SELECT t.id, t.type, t.status, t.result, t.result_reason, t.result_note, t.completed_at,
                    COALESCE(t.offer_expires_at, DATE(t.deadline)) as expires_at,
                    cu.name as customer_name, cu.id as customer_id, cu.identity_no as customer_identity,
                    COALESCE(i.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')) as insurance_name,
                    i.branch_group as branch_group,
                    COALESCE(co.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.companyName')), 'null')) as company_name,
                    p.id as policy_id, p.policy_no,
                    COALESCE(anap.gross_premium, p.gross_premium) as gross_premium,
                    COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) as plate_no,
                    COALESCE(p.insurance_type_id, 0) as insurance_type_id,
                    p.business_type as policy_business_type,
                    u.name as assigned_to_name,
                    (SELECT pp.gross_premium
                     FROM policies pp
                     WHERE pp.customer_id = t.customer_id
                       AND pp.deleted_at IS NULL
                       AND pp.is_cancelled = 0
                       AND (p.id IS NULL OR pp.id != p.id)
                       AND pp.issued_at >= DATE_SUB(
                             COALESCE(p.expires_at, t.offer_expires_at, DATE(t.deadline)),
                             INTERVAL 60 DAY)
                       AND (
                         -- Plakali: plaka + sigorta turu ile eslesir
                         (COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) IS NOT NULL
                          AND REPLACE(pp.plate_no, ' ', '') = REPLACE(
                                COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')),
                                ' ', '')
                          AND (
                            (p.insurance_type_id IS NOT NULL AND pp.insurance_type_id = p.insurance_type_id)
                            OR
                            (p.insurance_type_id IS NULL AND pp.insurance_type_id IN (
                              SELECT it.id FROM insurance_types it
                              WHERE it.name = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')
                            ))
                          )
                         )
                         OR
                         -- Plakasiz: ayni branch_group ile eslesir (TSS -> OSS gibi gecisleri destekler)
                         (COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) IS NULL
                          AND (SELECT it2.branch_group FROM insurance_types it2 WHERE it2.id = pp.insurance_type_id LIMIT 1) =
                              (SELECT it3.branch_group FROM insurance_types it3
                               WHERE it3.id = COALESCE(
                                 p.insurance_type_id,
                                 (SELECT it.id FROM insurance_types it
                                  WHERE it.name = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')
                                  LIMIT 1))
                               LIMIT 1)
                         )
                       )
                       -- Ayni police_no'ya ait iptal kaydi varsa gosterme
                       AND NOT EXISTS (
                         SELECT 1 FROM policies pc
                         WHERE pc.policy_no = pp.policy_no
                           AND pc.is_cancelled = 1
                           AND pc.deleted_at IS NULL
                       )
                     ORDER BY pp.issued_at DESC
                     LIMIT 1
                    ) as new_gross_premium,
                    -- Yenilendi sonra iptal edildi mi? (new_gross_premium null ama eslesme var)
                    (SELECT 1
                     FROM policies pp
                     WHERE pp.customer_id = t.customer_id
                       AND pp.deleted_at IS NULL
                       AND pp.is_cancelled = 0
                       AND (p.id IS NULL OR pp.id != p.id)
                       AND pp.issued_at >= DATE_SUB(
                             COALESCE(p.expires_at, t.offer_expires_at, DATE(t.deadline)),
                             INTERVAL 60 DAY)
                       AND (
                         (COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) IS NOT NULL
                          AND REPLACE(pp.plate_no, ' ', '') = REPLACE(
                                COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')),
                                ' ', '')
                          AND pp.insurance_type_id = COALESCE(
                                p.insurance_type_id,
                                (SELECT it.id FROM insurance_types it
                                 WHERE it.name = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')
                                 LIMIT 1))
                         )
                         OR
                         (COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) IS NULL
                          AND pp.insurance_type_id = COALESCE(
                                p.insurance_type_id,
                                (SELECT it.id FROM insurance_types it
                                 WHERE it.name = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')
                                 LIMIT 1))
                         )
                       )
                       AND EXISTS (
                         SELECT 1 FROM policies pc
                         WHERE pc.policy_no = pp.policy_no
                           AND pc.is_cancelled = 1
                           AND pc.deleted_at IS NULL
                       )
                     LIMIT 1
                    ) as new_policy_was_cancelled
             FROM tasks t
             LEFT JOIN customers cu ON t.customer_id = cu.id
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN policies anap ON p.parent_id = anap.id
             LEFT JOIN companies co ON p.company_id = co.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN users u ON t.assigned_to = u.id AND u.is_active = 1 AND u.deleted_at IS NULL
             WHERE t.type IN ('RENEWAL','OFFER','REFERENCE')
               AND t.deleted_at IS NULL
               $renewableFilter
               AND YEAR(COALESCE(t.offer_expires_at, DATE(t.deadline))) = ?
               AND MONTH(COALESCE(t.offer_expires_at, DATE(t.deadline))) = ?
               $assignFilter
             ORDER BY COALESCE(t.offer_expires_at, DATE(t.deadline)) ASC",
            $baseParams
        );

        // Otomatik yenileme kapatma DEVRE DISI — görevler ana sayfada kalır,
        // kullanıcı manuel olarak yönetir. (v1.4.4)
        $now = date('Y-m-d H:i:s');

        // 2. Mukerrer kayit temizleme (auto-update sonrasi — CANCELLED→RENEWED artik priority 5 alir)
        // Dedup anahtari: customer_id + insurance_type_id + plaka (normalize)
        // Oncelik: COMPLETED basarili > COMPLETED basarisiz > IN_PROGRESS > PENDING > diger
        $statusPriority = static function (string $status, ?string $result): int {
            if ($status === 'COMPLETED') {
                return in_array($result, ['RENEWED', 'OFFER_APPROVED', 'DONE']) ? 5 : 4;
            }
            return match ($status) {
                'IN_PROGRESS' => 3,
                'PENDING'     => 2,
                'EXPIRED'     => 1,
                default       => 0,
            };
        };

        $dedupSeen   = [];
        $dedupResult = [];
        foreach ($allDetails as $r) {
            $plateNorm  = strtolower(str_replace(' ', '', $r['plate_no'] ?? ''));
            $dedupKey = ($r['customer_id'] ?? '') . '|' . ($r['insurance_type_id'] ?? '') . '|' . $plateNorm;
            $priority = $statusPriority($r['status'], $r['result'] ?? null);

            if (!isset($dedupSeen[$dedupKey])) {
                $dedupSeen[$dedupKey]   = ['index' => count($dedupResult), 'priority' => $priority];
                $dedupResult[]          = $r;
            } elseif ($priority > $dedupSeen[$dedupKey]['priority']) {
                $idx = $dedupSeen[$dedupKey]['index'];
                $dedupResult[$idx]                = $r;
                $dedupSeen[$dedupKey]['priority'] = $priority;
            }
        }
        $allDetails = array_values($dedupResult);

        // 3. Stats: dedup + auto-update sonrasi final listeden hesapla (DB sorgusu gereksiz)
        $statsSuccessArr = ['RENEWED', 'OFFER_APPROVED', 'DONE'];
        $statsFailArr    = ['NOT_RENEWED', 'OFFER_REJECTED', 'FAILED'];
        $statsTotal      = count($allDetails);
        $statsSuccess    = 0; $statsFail = 0; $statsPending = 0; $statsCancelled = 0;
        foreach ($allDetails as $r) {
            if ($r['status'] === 'COMPLETED' && in_array($r['result'] ?? '', $statsSuccessArr)) $statsSuccess++;
            elseif ($r['status'] === 'COMPLETED' && in_array($r['result'] ?? '', $statsFailArr)) $statsFail++;
            elseif (in_array($r['status'], ['PENDING', 'IN_PROGRESS'])) $statsPending++;
            elseif ($r['status'] === 'CANCELLED') $statsCancelled++;
        }

        // Basarisiz detay listesi
        $failDetails = Database::fetchAll(
            "SELECT t.id, t.type, t.result, t.result_reason, t.completed_at,
                    cu.name as customer_name, cu.id as customer_id,
                    COALESCE(i.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')) as insurance_name,
                    COALESCE(co.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.companyName')), 'null')) as company_name,
                    p.policy_no, p.gross_premium,
                    COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) as plate_no
             FROM tasks t
             LEFT JOIN customers cu ON t.customer_id = cu.id
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN companies co ON p.company_id = co.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE t.type IN ('RENEWAL','OFFER','REFERENCE')
               AND t.deleted_at IS NULL
               $renewableFilter
               AND t.status = 'COMPLETED'
               AND t.result IN ($failResults)
               AND YEAR(COALESCE(t.offer_expires_at, DATE(t.deadline))) = ?
               AND MONTH(COALESCE(t.offer_expires_at, DATE(t.deadline))) = ?
               $assignFilter
             ORDER BY t.completed_at DESC",
            $baseParams
        );

        // Basarili detay listesi
        $successDetails = Database::fetchAll(
            "SELECT t.id, t.type, t.result, t.completed_at,
                    cu.name as customer_name, cu.id as customer_id,
                    COALESCE(i.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')) as insurance_name,
                    COALESCE(co.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.companyName')), 'null')) as company_name,
                    p.policy_no, p.gross_premium,
                    COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) as plate_no
             FROM tasks t
             LEFT JOIN customers cu ON t.customer_id = cu.id
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN companies co ON p.company_id = co.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE t.type IN ('RENEWAL','OFFER','REFERENCE')
               AND t.deleted_at IS NULL
               $renewableFilter
               AND t.status = 'COMPLETED'
               AND t.result IN ($successResults)
               AND YEAR(COALESCE(t.offer_expires_at, DATE(t.deadline))) = ?
               AND MONTH(COALESCE(t.offer_expires_at, DATE(t.deadline))) = ?
               $assignFilter
             ORDER BY t.completed_at DESC",
            $baseParams
        );

        Response::success([
            'year' => $year,
            'month' => $month,
            'total' => $statsTotal,
            'successCount' => $statsSuccess,
            'failCount' => $statsFail,
            'pendingCount' => $statsPending,
            'cancelledCount' => $statsCancelled,
            'failDetails' => array_map(fn($r) => [
                'id' => (int) $r['id'],
                'type' => $r['type'],
                'result' => $r['result'],
                'reason' => $r['result_reason'],
                'completedAt' => $r['completed_at'],
                'customerName' => $r['customer_name'],
                'customerId' => $r['customer_id'] ? (int) $r['customer_id'] : null,
                'insuranceName' => $r['insurance_name'],
                'companyName' => $r['company_name'],
                'policyNo' => $r['policy_no'],
                'grossPremium' => $r['gross_premium'] ? (float) $r['gross_premium'] : null,
                'plateNo' => $r['plate_no'],
            ], $failDetails),
            'successDetails' => array_map(fn($r) => [
                'id' => (int) $r['id'],
                'type' => $r['type'],
                'result' => $r['result'],
                'completedAt' => $r['completed_at'],
                'customerName' => $r['customer_name'],
                'customerId' => $r['customer_id'] ? (int) $r['customer_id'] : null,
                'insuranceName' => $r['insurance_name'],
                'companyName' => $r['company_name'],
                'policyNo' => $r['policy_no'],
                'grossPremium' => $r['gross_premium'] ? (float) $r['gross_premium'] : null,
                'plateNo' => $r['plate_no'],
            ], $successDetails),
            'allDetails' => array_map(fn($r) => [
                'id' => (int) $r['id'],
                'type' => $r['type'],
                'status' => $r['status'],
                'result' => $r['result'],
                'reason' => $r['result_reason'],
                'completedAt' => $r['completed_at'],
                'expiresAt' => $r['expires_at'],
                'customerName' => $r['customer_name'],
                'customerId' => $r['customer_id'] ? (int) $r['customer_id'] : null,
                'customerIdentity' => $r['customer_identity'] ?? null,
                'insuranceName' => $r['insurance_name'],
                'branchGroup' => $r['branch_group'] ?? null,
                'autoUpdated' => !empty($r['auto_updated']) || ($r['result_note'] ?? '') === 'Yeni poliçe tespit edildi — otomatik güncellendi',
                'newPolicyWasCancelled' => !empty($r['new_policy_was_cancelled']),
                'companyName' => $r['company_name'],
                'policyNo' => $r['policy_no'],
                'grossPremium' => $r['gross_premium'] ? (float) $r['gross_premium'] : null,
                'newGrossPremium' => $r['new_gross_premium'] ? (float) $r['new_gross_premium'] : null,
                'plateNo' => $r['plate_no'],
                'assignedToName' => $r['assigned_to_name'],
                'businessType' => $r['policy_business_type'] ?? null,
            ], $allDetails),

            // Poliçe bazlı İş Türü özeti (Yeni İş / Yenileme)
            'businessTypeSummary' => $this->getBusinessTypeSummary($year, $month, $assignFilter, $assignParams),
        ]);
    }

    private function getBusinessTypeSummary(int $year, int $month, string $assignFilter, array $assignParams): array
    {
        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime("$year-$month-01"));

        $soldByFilter = '';
        $soldByParams = [];
        if (!empty($assignParams)) {
            $soldByFilter = " AND p.sold_by = ?";
            $soldByParams = $assignParams;
        }

        $rows = Database::fetchAll(
            "SELECT
                COALESCE(p.business_type, 'UNKNOWN') as business_type,
                COUNT(*) as cnt,
                SUM(p.gross_premium) as gross,
                SUM(p.net_premium) as net
             FROM policies p
             WHERE p.deleted_at IS NULL
               AND p.is_cancelled = 0
               AND p.issued_at BETWEEN ? AND ?
               $soldByFilter
             GROUP BY p.business_type",
            array_merge([$firstDay, $lastDay], $soldByParams)
        );

        $result = ['new' => 0, 'renewal' => 0, 'unknown' => 0, 'newGross' => 0, 'renewalGross' => 0, 'unknownGross' => 0];
        foreach ($rows as $r) {
            if ($r['business_type'] === 'NEW') {
                $result['new'] = (int) $r['cnt'];
                $result['newGross'] = round((float) $r['gross'], 2);
            } elseif ($r['business_type'] === 'RENEWAL') {
                $result['renewal'] = (int) $r['cnt'];
                $result['renewalGross'] = round((float) $r['gross'], 2);
            } else {
                $result['unknown'] = (int) $r['cnt'];
                $result['unknownGross'] = round((float) $r['gross'], 2);
            }
        }
        $result['total'] = $result['new'] + $result['renewal'] + $result['unknown'];

        // Yeni iş poliçe listesi
        $newPolicies = Database::fetchAll(
            "SELECT p.id, p.policy_no, p.gross_premium, p.net_premium, p.issued_at, p.starts_at, p.expires_at,
                    p.plate_no, p.business_type,
                    cu.name as customer_name, cu.id as customer_id, cu.identity_no as customer_identity,
                    i.name as insurance_name, co.name as company_name,
                    rs.name as reference_source_name, u.name as sold_by_name
             FROM policies p
             LEFT JOIN customers cu ON p.customer_id = cu.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN companies co ON p.company_id = co.id
             LEFT JOIN reference_sources rs ON p.reference_source = rs.id
             LEFT JOIN users u ON p.sold_by = u.id
             WHERE p.deleted_at IS NULL AND p.is_cancelled = 0
               AND p.business_type = 'NEW'
               AND p.issued_at BETWEEN ? AND ?
               $soldByFilter
             ORDER BY p.issued_at DESC",
            array_merge([$firstDay, $lastDay], $soldByParams)
        );

        $result['newPolicies'] = array_map(fn($p) => [
            'id' => (int) $p['id'],
            'policyNo' => $p['policy_no'],
            'customerName' => $p['customer_name'] ?? '',
            'customerId' => $p['customer_id'] ? (int) $p['customer_id'] : null,
            'customerIdentity' => $p['customer_identity'] ?? '',
            'insuranceName' => $p['insurance_name'] ?? '',
            'companyName' => $p['company_name'] ?? '',
            'grossPremium' => (float) $p['gross_premium'],
            'plateNo' => $p['plate_no'] ?? '',
            'referenceSourceName' => $p['reference_source_name'] ?? '',
            'soldByName' => $p['sold_by_name'] ?? '',
            'issuedAt' => $p['issued_at'],
        ], $newPolicies);

        return $result;
    }

    public function taskPerformanceExport(array $user, array $query): void
    {
        Permission::require($user, 'performance.view');
        $month = (int) ($query['month'] ?? date('n'));
        $year = (int) ($query['year'] ?? date('Y'));
        $filter = $query['filter'] ?? 'all';

        $assignFilter = '';
        $assignParams = [];
        if ((int) $user['role'] !== 1) {
            $assignFilter = " AND t.assigned_to = ?";
            $assignParams = [$user['userId']];
        }

        $baseParams = array_merge([$year, $month], $assignParams);

        $successResults = "'RENEWED','OFFER_APPROVED','DONE'";
        $failResults = "'NOT_RENEWED','OFFER_REJECTED','FAILED'";
        $renewableFilter = " AND (t.policy_id IS NULL OR EXISTS (SELECT 1 FROM policies pp INNER JOIN insurance_types it ON it.id = pp.insurance_type_id AND it.is_renewable = 1 WHERE pp.id = t.policy_id))";

        $statusFilter = '';
        if ($filter === 'success') {
            $statusFilter = " AND t.status = 'COMPLETED' AND t.result IN ($successResults)";
        } elseif ($filter === 'fail') {
            $statusFilter = " AND t.status = 'COMPLETED' AND t.result IN ($failResults)";
        } elseif ($filter === 'pending') {
            $statusFilter = " AND t.status IN ('PENDING','IN_PROGRESS')";
        }

        $rows = Database::fetchAll(
            "SELECT t.id, t.type, t.status, t.result, t.result_reason,
                    COALESCE(t.offer_expires_at, DATE(t.deadline)) as expires_at,
                    t.completed_at,
                    cu.name as customer_name, cu.identity_no as customer_identity,
                    COALESCE(i.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')) as insurance_name,
                    COALESCE(co.name, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.companyName')), 'null')) as company_name,
                    p.policy_no,
                    COALESCE(anap.gross_premium, p.gross_premium) as gross_premium,
                    COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) as plate_no,
                    COALESCE(p.insurance_type_id, 0) as insurance_type_id,
                    p.business_type as policy_business_type,
                    u.name as assigned_to_name,
                    (SELECT pp.gross_premium
                     FROM policies pp
                     WHERE pp.customer_id = t.customer_id
                       AND pp.deleted_at IS NULL
                       AND pp.is_cancelled = 0
                       AND (p.id IS NULL OR pp.id != p.id)
                       AND pp.issued_at >= DATE_SUB(
                             COALESCE(p.expires_at, t.offer_expires_at, DATE(t.deadline)),
                             INTERVAL 60 DAY)
                       AND (
                         (COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) IS NOT NULL
                          AND REPLACE(pp.plate_no, ' ', '') = REPLACE(
                                COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')),
                                ' ', '')
                          AND (
                            (p.insurance_type_id IS NOT NULL AND pp.insurance_type_id = p.insurance_type_id)
                            OR
                            (p.insurance_type_id IS NULL AND pp.insurance_type_id IN (
                              SELECT it.id FROM insurance_types it
                              WHERE it.name = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')
                            ))
                          )
                         )
                         OR
                         (COALESCE(p.plate_no, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.plateNo')), 'null')) IS NULL
                          AND (SELECT it2.branch_group FROM insurance_types it2 WHERE it2.id = pp.insurance_type_id LIMIT 1) =
                              (SELECT it3.branch_group FROM insurance_types it3
                               WHERE it3.id = COALESCE(
                                 p.insurance_type_id,
                                 (SELECT it.id FROM insurance_types it
                                  WHERE it.name = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName')), 'null')
                                  LIMIT 1))
                               LIMIT 1)
                         )
                       )
                       AND NOT EXISTS (
                         SELECT 1 FROM policies pc
                         WHERE pc.policy_no = pp.policy_no
                           AND pc.is_cancelled = 1
                           AND pc.deleted_at IS NULL
                       )
                     ORDER BY pp.issued_at DESC
                     LIMIT 1
                    ) as new_gross_premium
             FROM tasks t
             LEFT JOIN customers cu ON t.customer_id = cu.id
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN policies anap ON p.parent_id = anap.id
             LEFT JOIN companies co ON p.company_id = co.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN users u ON t.assigned_to = u.id AND u.is_active = 1 AND u.deleted_at IS NULL
             WHERE t.type IN ('RENEWAL','OFFER','REFERENCE')
               AND t.deleted_at IS NULL
               $renewableFilter
               $statusFilter
               AND YEAR(COALESCE(t.offer_expires_at, DATE(t.deadline))) = ?
               AND MONTH(COALESCE(t.offer_expires_at, DATE(t.deadline))) = ?
               $assignFilter
             ORDER BY COALESCE(t.offer_expires_at, DATE(t.deadline)) ASC",
            $baseParams
        );

        // Sayfa ile ayni dedup mantigi: customer + insurance_type + plaka
        $statusPriority = static function (string $status, ?string $result): int {
            if ($status === 'COMPLETED') {
                return in_array($result, ['RENEWED', 'OFFER_APPROVED', 'DONE']) ? 5 : 4;
            }
            return match ($status) { 'IN_PROGRESS' => 3, 'PENDING' => 2, 'EXPIRED' => 1, default => 0 };
        };
        $dedupSeen = []; $dedupResult = [];
        foreach ($rows as $r) {
            $plateNorm = strtolower(str_replace(' ', '', $r['plate_no'] ?? ''));
            $key = ($r['customer_name'] ?? '') . '|' . ($r['insurance_type_id'] ?? '') . '|' . $plateNorm;
            $priority = $statusPriority($r['status'], $r['result'] ?? null);
            if (!isset($dedupSeen[$key])) {
                $dedupSeen[$key] = ['index' => count($dedupResult), 'priority' => $priority];
                $dedupResult[] = $r;
            } elseif ($priority > $dedupSeen[$key]['priority']) {
                $idx = $dedupSeen[$key]['index'];
                $dedupResult[$idx] = $r;
                $dedupSeen[$key]['priority'] = $priority;
            }
        }
        $rows = array_values($dedupResult);

        $typeLabels = ['RENEWAL' => 'Yenileme', 'OFFER' => 'Teklif', 'REFERENCE' => 'Referans'];
        $resultLabels = [
            'RENEWED' => 'Yenilendi', 'NOT_RENEWED' => 'Yenilenmedi',
            'OFFER_APPROVED' => 'Teklif Onaylandı', 'OFFER_REJECTED' => 'Teklif Reddedildi',
            'DONE' => 'Satış Yapıldı', 'FAILED' => 'Satış Yapılamadı'
        ];
        $statusLabels = [
            'PENDING' => 'Bekliyor', 'IN_PROGRESS' => 'Devam Ediyor',
            'COMPLETED' => 'Tamamlandı', 'CANCELLED' => 'İptal', 'EXPIRED' => 'Süresi Geçti'
        ];

        $months = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

        $exportRows = [];
        foreach ($rows as $r) {
            $durum = $r['status'] === 'COMPLETED' && $r['result']
                ? ($resultLabels[$r['result']] ?? $r['result'])
                : ($statusLabels[$r['status']] ?? $r['status']);

            $exportRows[] = [
                'customer_name'     => $r['customer_name'] ?? '-',
                'customer_identity' => $r['customer_identity'] ?? '-',
                'plate_no'          => $r['plate_no'] ?: '-',
                'insurance_name'    => $r['insurance_name'] ?? '-',
                'type'              => $typeLabels[$r['type']] ?? $r['type'],
                'status'            => $durum,
                'reason'            => $r['result_reason'] ?? '-',
                'gross_premium'     => $r['gross_premium'] ? (float) $r['gross_premium'] : 0,
                'new_gross_premium' => $r['new_gross_premium'] ? (float) $r['new_gross_premium'] : 0,
                'expires_at'        => $r['expires_at'] ?? '',
                'assigned_to'       => $r['assigned_to_name'] ?? '-',
            ];
        }

        $columns = [
            ['key' => 'customer_name',     'label' => 'Müşteri'],
            ['key' => 'customer_identity', 'label' => 'TC/VKN',          'type' => Response::COL_IDENTIFIER],
            ['key' => 'plate_no',          'label' => 'Plaka'],
            ['key' => 'insurance_name',    'label' => 'Branş'],
            ['key' => 'type',              'label' => 'Görev Tipi'],
            ['key' => 'status',            'label' => 'Durum'],
            ['key' => 'reason',            'label' => 'Sebep'],
            ['key' => 'gross_premium',     'label' => 'Geçen Yıl Prim',  'type' => Response::COL_CURRENCY],
            ['key' => 'new_gross_premium', 'label' => 'Bu Yıl Prim',     'type' => Response::COL_CURRENCY],
            ['key' => 'expires_at',        'label' => 'Vade',            'type' => Response::COL_DATE],
            ['key' => 'assigned_to',       'label' => 'Atanan'],
        ];

        Response::xlsx($exportRows, $columns, "satis_performansi_{$months[$month]}_{$year}.xlsx");
    }
}
