<?php

require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/Response.php';

class AgencyController
{
    public function stats($user): void
    {
        // Ilk police tarihi (0 primli test kayitlari haric)
        $first = Database::fetch("SELECT MIN(starts_at) as first_date FROM policies WHERE deleted_at IS NULL AND is_cancelled = 0 AND gross_premium > 0");

        // Yillara gore uretim (0 primli yillar haric)
        $yearly = Database::fetchAll(
            "SELECT YEAR(starts_at) as year,
                    COUNT(*) as count,
                    ROUND(SUM(gross_premium), 2) as gross_premium
             FROM policies
             WHERE deleted_at IS NULL AND is_cancelled = 0
             GROUP BY YEAR(starts_at)
             HAVING gross_premium > 0
             ORDER BY year"
        );

        // Toplam musteri
        $totalCustomers = Database::fetch("SELECT COUNT(*) as cnt FROM customers WHERE deleted_at IS NULL");

        // Aktif policeler
        $activePolicies = Database::fetch(
            "SELECT COUNT(*) as cnt FROM policies WHERE deleted_at IS NULL AND is_cancelled = 0 AND expires_at >= CURDATE()"
        );

        // Toplam uretim
        $totalProduction = Database::fetch(
            "SELECT COUNT(*) as count, ROUND(SUM(gross_premium), 2) as gross_premium
             FROM policies WHERE deleted_at IS NULL AND is_cancelled = 0"
        );

        // Brans dagilimi (tum zamanlar)
        $branches = Database::fetchAll(
            "SELECT it.name, COUNT(*) as count, ROUND(SUM(p.gross_premium), 2) as gross_premium
             FROM policies p
             INNER JOIN insurance_types it ON it.id = p.insurance_type_id
             WHERE p.deleted_at IS NULL AND p.is_cancelled = 0
             GROUP BY it.name
             ORDER BY gross_premium DESC
             LIMIT 10"
        );

        // Sirket dagilimi
        $companies = Database::fetchAll(
            "SELECT co.name, COUNT(*) as count, ROUND(SUM(p.gross_premium), 2) as gross_premium
             FROM policies p
             INNER JOIN companies co ON co.id = p.company_id
             WHERE p.deleted_at IS NULL AND p.is_cancelled = 0
             GROUP BY co.name
             ORDER BY gross_premium DESC
             LIMIT 10"
        );

        // Brans oranlari yillara gore (stratejik donusum) - gruplu
        $branchTrend = Database::fetchAll(
            "SELECT YEAR(p.starts_at) as year,
                    it.name as branch_name,
                    ROUND(SUM(p.gross_premium), 2) as branch_premium
             FROM policies p
             INNER JOIN insurance_types it ON it.id = p.insurance_type_id
             WHERE p.deleted_at IS NULL AND p.is_cancelled = 0
             GROUP BY YEAR(p.starts_at), it.name
             ORDER BY year, branch_premium DESC"
        );

        // Brans gruplama fonksiyonu
        $groupBranch = function (string $name): string {
            $upper = mb_strtoupper($name, 'UTF-8');
            if (in_array($upper, ['TSS', 'ÖSS', 'SEYAHAT SAĞLIK', 'ALLIANZ YABANCI SAĞLIK', 'DİJİTAL DOKTORUM', 'MORAL DESTEK', 'TIBBI KÖTÜ UYGULAMA'])) return 'Sağlık';
            if (strpos($upper, 'TRAFİK') !== false || strpos($upper, 'TRAFIK') !== false || strpos($upper, 'KARAYOLU') !== false) return 'Trafik';
            if ($upper === 'KASKO') return 'Kasko';
            if (in_array($upper, ['KONUT', 'DASK', 'ALLIANZ GÜVENLİ EVİM'])) return 'Konut & DASK';
            if ($upper === 'İŞYERİ') return 'İşyeri';
            return 'Diğer';
        };

        // Yillik toplam primler ve gruplu primler
        $yearlyTotals = [];
        $groupedByYear = [];
        foreach ($branchTrend as $row) {
            $y = $row['year'];
            $group = $groupBranch($row['branch_name']);
            if (!isset($yearlyTotals[$y])) $yearlyTotals[$y] = 0;
            $yearlyTotals[$y] += (float) $row['branch_premium'];
            if (!isset($groupedByYear[$y][$group])) $groupedByYear[$y][$group] = 0;
            $groupedByYear[$y][$group] += (float) $row['branch_premium'];
        }

        // Sabit grup sirasi
        $groupOrder = ['Sağlık', 'Trafik', 'Kasko', 'Konut & DASK', 'İşyeri', 'Diğer'];

        // Her yil icin grup oranlarini hesapla
        $portfolioTrend = [];
        foreach ($yearlyTotals as $year => $total) {
            if ($total <= 0) continue;
            $entry = ['year' => (int) $year, 'branches' => []];
            foreach ($groupOrder as $group) {
                $premium = $groupedByYear[$year][$group] ?? 0;
                $entry['branches'][] = [
                    'name' => $group,
                    'ratio' => round($premium / $total * 100, 1),
                    'premium' => $premium,
                ];
            }
            $portfolioTrend[] = $entry;
        }

        // Bu yilin brans dagilimi (gruplu)
        $currentYear = date('Y');
        $currentBranchesRaw = Database::fetchAll(
            "SELECT it.name, COUNT(*) as count, ROUND(SUM(p.gross_premium), 2) as gross_premium
             FROM policies p
             INNER JOIN insurance_types it ON it.id = p.insurance_type_id
             WHERE p.deleted_at IS NULL AND p.is_cancelled = 0 AND YEAR(p.starts_at) = ?
             GROUP BY it.name
             ORDER BY gross_premium DESC",
            [$currentYear]
        );
        $currentGrouped = [];
        foreach ($currentBranchesRaw as $row) {
            $group = $groupBranch($row['name']);
            if (!isset($currentGrouped[$group])) {
                $currentGrouped[$group] = ['name' => $group, 'count' => 0, 'gross_premium' => 0];
            }
            $currentGrouped[$group]['count'] += (int) $row['count'];
            $currentGrouped[$group]['gross_premium'] += (float) $row['gross_premium'];
        }
        // Sabit siraya gore sirala
        $currentBranches = [];
        foreach ($groupOrder as $group) {
            if (isset($currentGrouped[$group])) {
                $currentGrouped[$group]['gross_premium'] = round($currentGrouped[$group]['gross_premium'], 2);
                $currentBranches[] = $currentGrouped[$group];
            }
        }

        // Uretim tipi dagilimi
        $productionTypes = Database::fetchAll(
            "SELECT production_type, COUNT(*) as count, ROUND(SUM(gross_premium), 2) as gross_premium
             FROM policies WHERE deleted_at IS NULL AND is_cancelled = 0
             GROUP BY production_type ORDER BY gross_premium DESC"
        );

        // Musteri sadakati: kac farkli yilda police yaptirmis
        $loyalty = Database::fetch(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN year_count >= 2 THEN 1 ELSE 0 END) as returning_customers,
                SUM(CASE WHEN year_count = 1 THEN 1 ELSE 0 END) as y1,
                SUM(CASE WHEN year_count = 2 THEN 1 ELSE 0 END) as y2,
                SUM(CASE WHEN year_count = 3 THEN 1 ELSE 0 END) as y3,
                SUM(CASE WHEN year_count >= 4 THEN 1 ELSE 0 END) as y4plus
             FROM (
                SELECT p.customer_id, COUNT(DISTINCT YEAR(p.starts_at)) as year_count
                FROM policies p
                WHERE p.deleted_at IS NULL AND p.is_cancelled = 0 AND p.endorsement_no <= 1
                GROUP BY p.customer_id
             ) t"
        );

        // Bireysel vs Kurumsal musteri dagilimi
        $customerTypes = Database::fetchAll(
            "SELECT customer_type, COUNT(*) as count FROM customers WHERE deleted_at IS NULL GROUP BY customer_type"
        );

        // Yillara gore ortalama prim
        $avgPremium = Database::fetchAll(
            "SELECT YEAR(starts_at) as year, ROUND(AVG(gross_premium), 0) as avg_premium
             FROM policies
             WHERE deleted_at IS NULL AND is_cancelled = 0 AND gross_premium > 0
             GROUP BY YEAR(starts_at)
             HAVING COUNT(*) > 10
             ORDER BY year"
        );

        // Capraz satis analizi (aktif policeler uzerinden)
        $activeFilter = "p.deleted_at IS NULL AND p.is_cancelled = 0 AND p.expires_at >= CURDATE()";

        // Musteri basina urun cesidi dagilimi
        $crossDistribution = Database::fetch(
            "SELECT
                COUNT(*) as total_customers,
                ROUND(AVG(branch_count), 2) as avg_products,
                SUM(CASE WHEN branch_count = 1 THEN 1 ELSE 0 END) as single_product,
                SUM(CASE WHEN branch_count = 2 THEN 1 ELSE 0 END) as two_products,
                SUM(CASE WHEN branch_count = 3 THEN 1 ELSE 0 END) as three_products,
                SUM(CASE WHEN branch_count >= 4 THEN 1 ELSE 0 END) as four_plus,
                SUM(CASE WHEN branch_count >= 2 THEN 1 ELSE 0 END) as cross_sold
             FROM (
                SELECT p.customer_id, COUNT(DISTINCT p.insurance_type_id) as branch_count
                FROM policies p WHERE $activeFilter GROUP BY p.customer_id
             ) t"
        );

        // Ana urunler icin capraz satis detayi
        $crossSellDetails = [];
        $mainBranches = [
            ['name' => 'TRAFİK', 'filter' => "it.name LIKE '%TRAFİK%' OR it.name LIKE '%Trafik%'"],
            ['name' => 'TSS', 'filter' => "it.name = 'TSS'"],
            ['name' => 'KASKO', 'filter' => "it.name = 'KASKO'"],
        ];

        foreach ($mainBranches as $branch) {
            $total = Database::fetch(
                "SELECT COUNT(DISTINCT p.customer_id) as cnt FROM policies p
                 INNER JOIN insurance_types it ON it.id = p.insurance_type_id
                 WHERE $activeFilter AND ({$branch['filter']})"
            );
            $totalCount = (int) ($total['cnt'] ?? 0);
            if ($totalCount === 0) continue;

            $others = Database::fetchAll(
                "SELECT it.name, COUNT(DISTINCT p.customer_id) as cnt
                 FROM policies p
                 INNER JOIN insurance_types it ON it.id = p.insurance_type_id
                 WHERE $activeFilter
                   AND p.customer_id IN (
                       SELECT DISTINCT p2.customer_id FROM policies p2
                       INNER JOIN insurance_types it2 ON it2.id = p2.insurance_type_id
                       WHERE p2.deleted_at IS NULL AND p2.is_cancelled = 0 AND p2.expires_at >= CURDATE()
                         AND ({$branch['filter']})
                   )
                   AND NOT ({$branch['filter']})
                 GROUP BY it.name ORDER BY cnt DESC LIMIT 5"
            );

            $crossSellDetails[] = [
                'name' => $branch['name'],
                'totalCustomers' => $totalCount,
                'crossSold' => array_map(function ($r) use ($totalCount) {
                    return [
                        'name' => $r['name'],
                        'count' => (int) $r['cnt'],
                        'ratio' => round((int) $r['cnt'] / $totalCount * 100, 1),
                    ];
                }, $others),
            ];
        }

        Response::success([
            'firstPolicyDate' => $first['first_date'] ?? null,
            'totalCustomers' => (int) ($totalCustomers['cnt'] ?? 0),
            'activePolicies' => (int) ($activePolicies['cnt'] ?? 0),
            'totalPolicies' => (int) ($totalProduction['count'] ?? 0),
            'totalPremium' => (float) ($totalProduction['gross_premium'] ?? 0),
            'yearly' => $yearly,
            'branches' => $branches,
            'companies' => $companies,
            'loyalty' => [
                'total' => (int) ($loyalty['total'] ?? 0),
                'returningCustomers' => (int) ($loyalty['returning_customers'] ?? 0),
                'returningRate' => $loyalty['total'] > 0
                    ? round((int) $loyalty['returning_customers'] / (int) $loyalty['total'] * 100, 1)
                    : 0,
                'y1' => (int) ($loyalty['y1'] ?? 0),
                'y2' => (int) ($loyalty['y2'] ?? 0),
                'y3' => (int) ($loyalty['y3'] ?? 0),
                'y4plus' => (int) ($loyalty['y4plus'] ?? 0),
            ],
            'customerTypes' => $customerTypes,
            'avgPremium' => $avgPremium,
            'portfolioTrend' => $portfolioTrend,
            'currentYear' => (int) $currentYear,
            'currentBranches' => $currentBranches,
            'productionTypes' => $productionTypes,
            'crossSell' => [
                'totalCustomers' => (int) ($crossDistribution['total_customers'] ?? 0),
                'avgProducts' => (float) ($crossDistribution['avg_products'] ?? 0),
                'singleProduct' => (int) ($crossDistribution['single_product'] ?? 0),
                'twoProducts' => (int) ($crossDistribution['two_products'] ?? 0),
                'threeProducts' => (int) ($crossDistribution['three_products'] ?? 0),
                'fourPlus' => (int) ($crossDistribution['four_plus'] ?? 0),
                'crossSold' => (int) ($crossDistribution['cross_sold'] ?? 0),
                'crossRate' => $crossDistribution['total_customers'] > 0
                    ? round((int) $crossDistribution['cross_sold'] / (int) $crossDistribution['total_customers'] * 100, 1)
                    : 0,
                'details' => $crossSellDetails,
            ],
        ]);
    }
}
