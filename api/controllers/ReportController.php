<?php

class ReportController
{
    public function index(array $user, array $query): void
    {
        Permission::require($user, 'reports.view');
        $branchFilter = '';
        $params = [];

        if ((int) $user['role'] === 2) {
            $branchFilter = " AND p.branch_id = ?";
            $params[] = $user['branchId'];
        }

        // Tarih araligi filtresi (tanzim tarihine gore)
        $dateFilter = "1=1";
        if (!empty($query['dateFrom']) && !empty($query['dateTo'])) {
            $dateFilter = "p.issued_at BETWEEN ? AND ?";
            $params[] = $query['dateFrom'];
            $params[] = $query['dateTo'];
        } elseif (!empty($query['dateFrom'])) {
            $dateFilter = "p.issued_at >= ?";
            $params[] = $query['dateFrom'];
        } elseif (!empty($query['dateTo'])) {
            $dateFilter = "p.issued_at <= ?";
            $params[] = $query['dateTo'];
        } else {
            // Varsayilan: bu yil
            $dateFilter = "YEAR(p.issued_at) = YEAR(CURDATE())";
        }

        // Sadece ana policeler + son zeyili iptal olmayanlar + suresi bitmemis
        $latestCancelled = "(SELECT z2.is_cancelled FROM policies z2 WHERE z2.policy_no = p.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1)";
        $effectiveExpires = "(SELECT z3.expires_at FROM policies z3 WHERE z3.policy_no = p.policy_no AND z3.deleted_at IS NULL ORDER BY z3.endorsement_no DESC LIMIT 1)";
        $baseWhere = "p.parent_id IS NULL AND p.deleted_at IS NULL AND $latestCancelled = 0";

        // Zeyil toplam subquery'leri
        $totalGross = "(SELECT COALESCE(SUM(CASE WHEN z.is_cancelled = 1 AND z.gross_premium > 0 THEN -z.gross_premium ELSE z.gross_premium END), 0) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL)";
        $totalNet = "(SELECT COALESCE(SUM(CASE WHEN z.is_cancelled = 1 AND z.net_premium > 0 THEN -z.net_premium ELSE z.net_premium END), 0) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL)";

        // --- 1. Monthly Trend (YEAR + MONTH group) ---
        $monthlyParams = $params;
        $monthly = Database::fetchAll(
            "SELECT YEAR(p.issued_at) as y, MONTH(p.issued_at) as m,
                    COUNT(*) as cnt,
                    COALESCE(SUM($totalGross), 0) as premium,
                    COALESCE(SUM($totalNet), 0) as net
             FROM policies p
             WHERE $baseWhere AND $dateFilter $branchFilter
             GROUP BY YEAR(p.issued_at), MONTH(p.issued_at) ORDER BY y, m",
            $monthlyParams
        );

        // Onceki donem (ayni uzunlukta onceki aralik)
        $monthlyPrev = [];
        if (!empty($query['dateFrom']) && !empty($query['dateTo'])) {
            $from = new DateTime($query['dateFrom']);
            $to = new DateTime($query['dateTo']);
            $diff = $from->diff($to);
            $prevTo = clone $from;
            $prevTo->modify('-1 day');
            $prevFrom = clone $prevTo;
            $prevFrom->sub($diff);

            $prevParams = [];
            if ((int) $user['role'] === 2) {
                $prevParams[] = $user['branchId'];
            }
            $prevParams[] = $prevFrom->format('Y-m-d');
            $prevParams[] = $prevTo->format('Y-m-d');

            $monthlyPrev = Database::fetchAll(
                "SELECT YEAR(p.issued_at) as y, MONTH(p.issued_at) as m,
                        COUNT(*) as cnt,
                        COALESCE(SUM($totalGross), 0) as premium
                 FROM policies p
                 WHERE $baseWhere AND p.issued_at BETWEEN ? AND ? $branchFilter
                 GROUP BY YEAR(p.issued_at), MONTH(p.issued_at) ORDER BY y, m",
                $prevParams
            );
        } else {
            // Varsayilan: onceki yil
            $prevYearParams = [];
            if ((int) $user['role'] === 2) {
                $prevYearParams[] = $user['branchId'];
            }
            $prevYear = date('Y') - 1;
            $monthlyPrev = Database::fetchAll(
                "SELECT YEAR(p.issued_at) as y, MONTH(p.issued_at) as m,
                        COUNT(*) as cnt,
                        COALESCE(SUM($totalGross), 0) as premium
                 FROM policies p
                 WHERE $baseWhere
                   AND YEAR(p.issued_at) = $prevYear $branchFilter
                 GROUP BY YEAR(p.issued_at), MONTH(p.issued_at) ORDER BY y, m",
                $prevYearParams
            );
        }

        // --- 2. By Customer (Top 20) ---
        $custParams = $params;
        $byCustomer = Database::fetchAll(
            "SELECT cu.id, cu.name,
                    COUNT(*) as cnt,
                    COALESCE(SUM($totalGross), 0) as premium,
                    COALESCE(SUM($totalNet), 0) as net
             FROM policies p
             LEFT JOIN customers cu ON p.customer_id = cu.id
             WHERE $baseWhere AND $dateFilter $branchFilter
             GROUP BY cu.id, cu.name
             ORDER BY premium DESC LIMIT 20",
            $custParams
        );

        // --- 3. By Company ---
        $compParams = $params;
        $byCompany = Database::fetchAll(
            "SELECT co.id, co.name,
                    COUNT(*) as cnt,
                    COALESCE(SUM($totalGross), 0) as premium,
                    COALESCE(SUM($totalNet), 0) as net
             FROM policies p
             LEFT JOIN companies co ON p.company_id = co.id
             WHERE $baseWhere AND $dateFilter $branchFilter
             GROUP BY co.id, co.name
             ORDER BY premium DESC",
            $compParams
        );

        // --- 4. By Insurance Type ---
        $insParams = $params;
        $byInsurance = Database::fetchAll(
            "SELECT i.id, i.name, i.branch_group as ins_group,
                    COUNT(*) as cnt,
                    COALESCE(SUM($totalGross), 0) as premium,
                    COALESCE(SUM($totalNet), 0) as net
             FROM policies p
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE $baseWhere AND $dateFilter $branchFilter
             GROUP BY i.id, i.name, i.branch_group
             ORDER BY premium DESC",
            $insParams
        );

        // --- 5. By Insurance Group ---
        $grpParams = $params;
        $byGroup = Database::fetchAll(
            "SELECT i.branch_group as ins_group,
                    COUNT(*) as cnt,
                    COALESCE(SUM($totalGross), 0) as premium,
                    COALESCE(SUM($totalNet), 0) as net
             FROM policies p
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE $baseWhere AND $dateFilter $branchFilter
             GROUP BY i.branch_group
             ORDER BY premium DESC",
            $grpParams
        );

        // --- 6. By Production Type ---
        $prodParams = $params;
        $byProd = Database::fetchAll(
            "SELECT p.production_type,
                    COUNT(*) as cnt,
                    COALESCE(SUM($totalGross), 0) as premium,
                    COALESCE(SUM($totalNet), 0) as net
             FROM policies p
             WHERE $baseWhere AND $dateFilter $branchFilter
             GROUP BY p.production_type",
            $prodParams
        );

        // --- 7. By Branch (Acente) - Acentem (SELF) dahil ---
        $branchParams = $params;
        $byBranch = Database::fetchAll(
            "SELECT COALESCE(b.id, 0) as id, COALESCE(b.name, 'Acentem') as name,
                    COUNT(*) as cnt,
                    COALESCE(SUM($totalGross), 0) as premium,
                    COALESCE(SUM($totalNet), 0) as net
             FROM policies p
             LEFT JOIN branches b ON p.branch_id = b.id
             WHERE $baseWhere AND $dateFilter $branchFilter
             GROUP BY COALESCE(b.id, 0), COALESCE(b.name, 'Acentem')
             ORDER BY premium DESC",
            $branchParams
        );

        // --- 8. Summary totals (iptal haric) ---
        $totParams = $params;
        $totals = Database::fetch(
            "SELECT COUNT(*) as cnt,
                    COALESCE(SUM($totalGross), 0) as premium,
                    COALESCE(SUM($totalNet), 0) as net
             FROM policies p
             WHERE $baseWhere AND $dateFilter $branchFilter",
            $totParams
        );

        // Iptal edilen police sayisi (secili donemde tanzim edilip iptal olan tum policeler)
        $cancelledParams = $params;
        $cancelledRow = Database::fetch(
            "SELECT COUNT(*) as cnt
             FROM policies p
             WHERE p.parent_id IS NULL AND p.deleted_at IS NULL
               AND $latestCancelled = 1
               AND $dateFilter $branchFilter",
            $cancelledParams
        );

        $prodLabels = ['SELF' => 'Acentem', 'INCOMING' => 'Tali Gelen', 'OUTGOING' => 'Tali Giden'];

        Response::success([
            'totals' => [
                'count' => (int) $totals['cnt'],
                'premium' => (float) $totals['premium'],
                'net' => (float) $totals['net'],
                'cancelled' => (int) $cancelledRow['cnt'],
            ],
            'monthly' => array_map(fn($m) => [
                'year' => (int) $m['y'],
                'month' => (int) $m['m'],
                'count' => (int) $m['cnt'],
                'premium' => (float) $m['premium'],
                'net' => (float) $m['net'],
            ], $monthly),
            'monthlyPrev' => array_map(fn($m) => [
                'year' => (int) $m['y'],
                'month' => (int) $m['m'],
                'count' => (int) $m['cnt'],
                'premium' => (float) $m['premium'],
            ], $monthlyPrev),
            'byCustomer' => array_map(fn($c) => [
                'id' => (int) $c['id'],
                'name' => $c['name'] ?? 'Bilinmeyen',
                'count' => (int) $c['cnt'],
                'premium' => (float) $c['premium'],
                'net' => (float) $c['net'],
            ], $byCustomer),
            'byCompany' => array_map(fn($c) => [
                'name' => $c['name'] ?? 'Bilinmeyen',
                'count' => (int) $c['cnt'],
                'premium' => (float) $c['premium'],
                'net' => (float) $c['net'],
            ], $byCompany),
            'byInsurance' => array_map(fn($i) => [
                'name' => $i['name'] ?? 'Bilinmeyen',
                'group' => $i['ins_group'] ?? 'DIGER',
                'count' => (int) $i['cnt'],
                'premium' => (float) $i['premium'],
                'net' => (float) $i['net'],
            ], $byInsurance),
            'byGroup' => array_map(fn($g) => [
                'group' => $g['ins_group'] ?? 'DIGER',
                'count' => (int) $g['cnt'],
                'premium' => (float) $g['premium'],
                'net' => (float) $g['net'],
            ], $byGroup),
            'byProd' => array_map(fn($p) => [
                'prod' => $p['production_type'],
                'prodLabel' => $prodLabels[$p['production_type']] ?? $p['production_type'],
                'count' => (int) $p['cnt'],
                'premium' => (float) $p['premium'],
                'net' => (float) $p['net'],
            ], $byProd),
            'byBranch' => array_map(fn($b) => [
                'name' => $b['name'] ?? 'Bilinmeyen',
                'count' => (int) $b['cnt'],
                'premium' => (float) $b['premium'],
                'net' => (float) $b['net'],
            ], $byBranch),
        ]);
    }
}
