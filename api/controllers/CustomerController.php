<?php

class CustomerController
{
    public function index(array $user, array $query): void
    {
        Permission::require($user, 'customers.view');
        $where = ["c.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $search = '%' . $query['search'] . '%';
            $where[] = "(c.name LIKE ? OR c.identity_no LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
            $params = array_merge($params, [$search, $search, $search, $search]);
        }

        if (!empty($query['type'])) {
            $where[] = "c.customer_type = ?";
            $params[] = $query['type'];
        }

        if (!empty($query['categoryId'])) {
            $catId = (int) $query['categoryId'];
            $cat = Database::fetch("SELECT min_amount, max_amount FROM customer_categories WHERE id = ? AND deleted_at IS NULL", [$catId]);
            if ($cat) {
                $totalGrossSub2 = "(SELECT COALESCE(SUM(CASE WHEN p3.is_cancelled = 1 AND p3.gross_premium > 0 THEN -p3.gross_premium ELSE p3.gross_premium END), 0) FROM policies p3 WHERE p3.customer_id = c.id AND p3.deleted_at IS NULL)";
                if ($cat['min_amount'] !== null) {
                    $where[] = "$totalGrossSub2 >= ?";
                    $params[] = (float) $cat['min_amount'];
                }
                if ($cat['max_amount'] !== null) {
                    $where[] = "$totalGrossSub2 < ?";
                    $params[] = (float) $cat['max_amount'];
                }
            }
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? ITEMS_PER_PAGE)));

        $allowedSorts = ['id', 'name', 'identity_no', 'customer_type', 'email', 'phone', 'active_policies', 'total_gross', 'birth_date', 'created_at'];
        $sort = in_array($query['sort'] ?? '', $allowedSorts) ? $query['sort'] : 'created_at';
        $order = ($query['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $activePolicySub = "(SELECT COUNT(*) FROM policies p WHERE p.customer_id = c.id AND p.parent_id IS NULL AND p.deleted_at IS NULL
            AND p.is_cancelled = 0
            AND (SELECT z.is_cancelled FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL ORDER BY z.endorsement_no DESC LIMIT 1) = 0
            AND (SELECT z2.expires_at FROM policies z2 WHERE z2.policy_no = p.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1) >= CURDATE())";
        $totalGrossSub = "(SELECT COALESCE(SUM(CASE WHEN zp.is_cancelled = 1 AND zp.gross_premium > 0 THEN -zp.gross_premium ELSE zp.gross_premium END), 0)
            FROM policies zp
            INNER JOIN policies p2 ON p2.policy_no = zp.policy_no AND p2.parent_id IS NULL
            WHERE p2.customer_id = c.id
              AND p2.deleted_at IS NULL
              AND p2.is_cancelled = 0
              AND zp.deleted_at IS NULL
              AND (SELECT z.is_cancelled FROM policies z WHERE z.policy_no = p2.policy_no AND z.deleted_at IS NULL ORDER BY z.endorsement_no DESC LIMIT 1) = 0
              AND (SELECT z2.expires_at FROM policies z2 WHERE z2.policy_no = p2.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1) >= CURDATE())";
        $sortMap = ['id' => 'c.id', 'active_policies' => $activePolicySub, 'total_gross' => $totalGrossSub];
        $orderCol = $sortMap[$sort] ?? "TRIM(c.$sort)";
        $sql = "SELECT c.*, $activePolicySub as active_policies, $totalGrossSub as total_gross FROM customers c WHERE $whereSql ORDER BY $orderCol $order";
        $result = Database::paginate($sql, $params, $page, $limit);

        // Format for frontend
        $result['data'] = array_map([$this, 'formatCustomer'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $customer = Database::fetch(
            "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$customer) {
            Response::error('Musteri bulunamadi', 404);
        }

        // Ana policeleri getir, son zeyil bilgileriyle
        $policies = Database::fetchAll(
            "SELECT p.id, p.policy_no, p.production_type, p.customer_id, p.created_at,
                lz.starts_at, lz.expires_at, lz.gross_premium, lz.net_premium,
                lz.company_comm_rate, lz.branch_comm_rate, lz.company_comm_amount, lz.branch_comm_amount,
                lz.is_cancelled, lz.plate_no, lz.endorsement_no as latest_endorsement_no,
                lz.issued_at, lz.insured_name,
                i.name as insurance_name, i.code as insurance_code, i.branch_group as insurance_branch_group,
                co.name as company_name, b.name as branch_name,
                lz.is_cancelled as latest_is_cancelled,
                lz.expires_at as effective_expires_at,
                (SELECT COALESCE(SUM(CASE WHEN z3.is_cancelled = 1 AND z3.gross_premium > 0 THEN -z3.gross_premium ELSE z3.gross_premium END), 0) FROM policies z3 WHERE z3.policy_no = p.policy_no AND z3.deleted_at IS NULL) as total_gross_premium,
                (SELECT COALESCE(SUM(CASE WHEN z4.is_cancelled = 1 AND z4.net_premium > 0 THEN -z4.net_premium ELSE z4.net_premium END), 0) FROM policies z4 WHERE z4.policy_no = p.policy_no AND z4.deleted_at IS NULL) as total_net_premium,
                (SELECT GREATEST(COUNT(*) - 1, 0) FROM policies z5 WHERE z5.policy_no = p.policy_no AND z5.deleted_at IS NULL) as zeyil_count
             FROM policies p
             INNER JOIN policies lz ON lz.id = (
                SELECT z.id FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL ORDER BY z.endorsement_no DESC LIMIT 1
             )
             LEFT JOIN insurance_types i ON lz.insurance_type_id = i.id
             LEFT JOIN companies co ON lz.company_id = co.id
             LEFT JOIN branches b ON lz.branch_id = b.id
             WHERE p.customer_id = ? AND p.parent_id IS NULL AND p.deleted_at IS NULL
             AND p.id = (SELECT px.id FROM policies px WHERE px.policy_no = p.policy_no AND px.parent_id IS NULL AND px.deleted_at IS NULL ORDER BY px.endorsement_no DESC, px.id DESC LIMIT 1)
             ORDER BY p.created_at DESC",
            [$id]
        );

        // Istatistikler - ana policeler uzerinden hesapla
        $today = date('Y-m-d');
        $activePolicies = 0;
        $expiredPolicies = 0;
        $cancelledPolicies = 0;
        $totalPremium = 0;

        $formattedPolicies = [];
        foreach ($policies as $p) {
            $fp = $this->formatPolicy($p);
            $formattedPolicies[] = $fp;

            if ($fp['status'] === 'ACTIVE') {
                $activePolicies++;
                $totalPremium += $fp['totalGrossPremium'] ?? $fp['grossPremium'];
            } elseif ($fp['status'] === 'EXPIRED') {
                $expiredPolicies++;
            } elseif ($fp['status'] === 'CANCELLED') {
                $cancelledPolicies++;
            }
        }

        // total_gross hesapla ve formatCustomer'a aktar
        $customer['total_gross'] = $totalPremium;
        $customer['active_policies'] = $activePolicies;
        $formatted = $this->formatCustomer($customer);

        $formatted['policies'] = $formattedPolicies;
        $formatted['stats'] = [
            'totalPolicies' => count($policies),
            'activePolicies' => $activePolicies,
            'expiredPolicies' => $expiredPolicies,
            'cancelledPolicies' => $cancelledPolicies,
            'totalPremium' => $totalPremium,
        ];

        Response::success($formatted);
    }

    public function store(array $user, array $input): void
    {
        Permission::require($user, 'customers.manage');
        $validator = new Validator();
        if (!$validator->validate($input, [
            'customerType' => 'required|in:INDIVIDUAL,CORPORATE',
            'name' => 'required|min:2',
            'identityNo' => 'required',
            'phone' => 'required',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        // Aynı identity_no ile mevcut musteri var mı? (TRIM ile, eski kayitlarda bosluk olabilir)
        $identityNo = trim((string) $input['identityNo']);
        if ($identityNo !== '') {
            $duplicate = Database::fetch(
                "SELECT id, name FROM customers WHERE TRIM(identity_no) = ? AND deleted_at IS NULL LIMIT 1",
                [$identityNo]
            );
            if ($duplicate) {
                Response::error(
                    'Bu kimlik/vergi no ile kayıtlı müşteri zaten var: ' . trim($duplicate['name']) . ' (#' . $duplicate['id'] . ')',
                    409
                );
            }
        }

        $data = [
            'customer_type' => $input['customerType'],
            'name' => trim($input['name']),
            'identity_no' => trim((string) $input['identityNo']),
            'tax_office' => $input['taxOffice'] ?? null,
            'birth_date' => $input['birthDate'] ?? null,
            'phone' => $input['phone'],
            'email' => $input['email'] ?? null,
            'contact_person' => $input['contactPerson'] ?? null,
            'phone_alt' => $input['phoneAlt'] ?? null,
            'marital_status' => $input['maritalStatus'] ?? null,
            'job' => $input['job'] ?? null,
            'dependents_count' => $input['dependentsCount'] ?? null,
            'sector' => $input['sector'] ?? null,
            'country_id' => $input['countryId'] ?? null,
            'city_id' => $input['city'] ?? null,
            'district_id' => $input['district'] ?? null,
            'address' => $input['address'] ?? null,
            'note' => $input['note'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $id = Database::insert('customers', $data);
        Response::success(['id' => $id], 'Musteri olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        Permission::require($user, 'customers.manage');
        $existing = Database::fetch("SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Musteri bulunamadi', 404);
        }

        // identityNo degisiyorsa baska musteride var mi kontrol et (TRIM ile)
        if (!empty($input['identityNo'])) {
            $identityNo = trim((string) $input['identityNo']);
            if ($identityNo !== '') {
                $duplicate = Database::fetch(
                    "SELECT id, name FROM customers WHERE TRIM(identity_no) = ? AND id != ? AND deleted_at IS NULL LIMIT 1",
                    [$identityNo, $id]
                );
                if ($duplicate) {
                    Response::error(
                        'Bu kimlik/vergi no ile başka bir müşteri kayıtlı: ' . trim($duplicate['name']) . ' (#' . $duplicate['id'] . ')',
                        409
                    );
                }
            }
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        $fieldMap = [
            'customerType' => 'customer_type', 'name' => 'name', 'identityNo' => 'identity_no',
            'taxOffice' => 'tax_office', 'birthDate' => 'birth_date', 'phone' => 'phone',
            'email' => 'email', 'contactPerson' => 'contact_person',
            'phoneAlt' => 'phone_alt', 'maritalStatus' => 'marital_status',
            'job' => 'job', 'dependentsCount' => 'dependents_count',
            'sector' => 'sector', 'countryId' => 'country_id',
            'city' => 'city_id', 'district' => 'district_id', 'address' => 'address', 'note' => 'note',
        ];

        $trimFields = ['name', 'identity_no', 'tax_office', 'contact_person'];
        foreach ($fieldMap as $camel => $snake) {
            if (array_key_exists($camel, $input)) {
                $val = $input[$camel];
                $data[$snake] = (in_array($snake, $trimFields) && is_string($val)) ? trim($val) : $val;
            }
        }

        Database::update('customers', $data, 'id = ?', [$id]);
        Response::success(null, 'Musteri guncellendi');
    }

    public function listAll(array $user, array $query = []): void
    {
        $where = "deleted_at IS NULL";
        if (!empty($query['hasPhone'])) {
            $where .= " AND phone IS NOT NULL AND phone != ''";
        }
        $rows = Database::fetchAll(
            "SELECT id, name, identity_no, phone FROM customers WHERE $where ORDER BY TRIM(name) ASC"
        );
        $data = array_map(function ($c) {
            return ['id' => (int) $c['id'], 'name' => $c['name'], 'identityNo' => $c['identity_no'] ?? '', 'phone' => $c['phone'] ?? ''];
        }, $rows);
        Response::success($data);
    }

    public function listPaged(array $user, array $query): void
    {
        $page  = max(1, (int) ($query['page']  ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $rows = Database::fetchAll(
            "SELECT id, name, identity_no FROM customers WHERE deleted_at IS NULL ORDER BY TRIM(name) ASC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
        $data = array_map(function ($c) {
            return ['id' => (int) $c['id'], 'name' => $c['name'], 'identityNo' => $c['identity_no'] ?? ''];
        }, $rows);
        Response::success($data);
    }

    public function searchByName(array $user, array $query): void
    {
        $q = trim($query['q'] ?? '');
        if (mb_strlen($q) < 2) {
            Response::success([]);
            return;
        }
        $limit  = min(50, max(1, (int) ($query['limit'] ?? 30)));
        $search = '%' . $q . '%';
        $rows = Database::fetchAll(
            "SELECT id, name, identity_no FROM customers WHERE deleted_at IS NULL AND (name LIKE ? OR identity_no LIKE ?) ORDER BY TRIM(name) ASC LIMIT ?",
            [$search, $search, $limit]
        );
        $data = array_map(function ($c) {
            return ['id' => (int) $c['id'], 'name' => $c['name'], 'identityNo' => $c['identity_no'] ?? ''];
        }, $rows);
        Response::success($data);
    }

    public function destroy(array $user, int $id): void
    {
        Permission::require($user, 'customers.delete');

        // Aktif policesi olan musteri silinemez
        $activePolicy = Database::fetch(
            "SELECT p.id FROM policies p
             WHERE p.customer_id = ? AND p.deleted_at IS NULL AND p.is_cancelled = 0
             AND p.id = (
                 SELECT z.id FROM policies z
                 WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL
                 ORDER BY z.endorsement_no DESC LIMIT 1
             )
             AND p.expires_at >= CURDATE()
             LIMIT 1",
            [$id]
        );

        if ($activePolicy) {
            Response::error('Aktif policesi olan musteri silinemez. Once policeleri iptal edin veya surelerinin dolmasini bekleyin.', 422);
            return;
        }

        Database::softDelete('customers', $id);
        Response::success(null, 'Musteri silindi');
    }

    private static $categoriesCache = null;

    private function getAllCategories(): array
    {
        if (self::$categoriesCache === null) {
            self::$categoriesCache = Database::fetchAll(
                "SELECT id, min_amount, max_amount, is_default FROM customer_categories WHERE deleted_at IS NULL ORDER BY min_amount ASC"
            );
        }
        return self::$categoriesCache;
    }

    private function getCategoryByAmount(float $totalGross): ?int
    {
        $categories = $this->getAllCategories();
        foreach ($categories as $cat) {
            $min = $cat['min_amount'] !== null ? (float) $cat['min_amount'] : null;
            $max = $cat['max_amount'] !== null ? (float) $cat['max_amount'] : null;
            if ($min !== null && $totalGross < $min) continue;
            if ($max !== null && $totalGross >= $max) continue;
            return (int) $cat['id'];
        }
        return null;
    }

    private function formatCustomer(array $c): array
    {
        $totalGross = (float) ($c['total_gross'] ?? 0);
        $categoryId = $this->getCategoryByAmount($totalGross);

        return [
            'id' => (int) $c['id'],
            'customerType' => $c['customer_type'],
            'name' => trim($c['name']),
            'identityNo' => $c['identity_no'],
            'taxOffice' => $c['tax_office'],
            'birthDate' => $c['birth_date'],
            'phone' => $c['phone'],
            'phoneAlt' => $c['phone_alt'],
            'email' => $c['email'],
            'contactPerson' => $c['contact_person'],
            'maritalStatus' => $c['marital_status'],
            'job' => $c['job'],
            'dependentsCount' => $c['dependents_count'] ? (int) $c['dependents_count'] : null,
            'sector' => $c['sector'],
            'countryId' => $c['country_id'] ? (int) $c['country_id'] : null,
            'cityId' => $c['city_id'] ? (int) $c['city_id'] : null,
            'districtId' => $c['district_id'] ? (int) $c['district_id'] : null,
            'address' => $c['address'],
            'note' => $c['note'],
            'hasVehicle' => $c['has_vehicle'] ?? 'UNKNOWN',
            'hasVehicleCheckedAt' => $c['has_vehicle_checked_at'] ?? null,
            'categoryId' => $categoryId,
            'activePolicies' => (int) ($c['active_policies'] ?? 0),
            'totalGross' => (float) ($c['total_gross'] ?? 0),
            'createdAt' => $c['created_at'],
        ];
    }

    private function formatPolicy(array $p): array
    {
        $status = $this->calculatePolicyStatus($p);
        $effectiveExpiresAt = $p['effective_expires_at'] ?? $p['expires_at'];

        // Iptal ve pozitifse - yap (tekil)
        $isCancel = ((int) ($p['is_cancelled'] ?? 0)) === 1;
        $rawGross = (float) $p['gross_premium'];
        $rawNet = (float) $p['net_premium'];
        $signedGross = ($isCancel && $rawGross > 0) ? -$rawGross : $rawGross;
        $signedNet = ($isCancel && $rawNet > 0) ? -$rawNet : $rawNet;

        $totalGross = isset($p['total_gross_premium']) ? (float) $p['total_gross_premium'] : $signedGross;
        $totalNet = isset($p['total_net_premium']) ? (float) $p['total_net_premium'] : $signedNet;

        return [
            'id' => (int) $p['id'],
            'productionType' => $p['production_type'],
            'policyNo' => $p['policy_no'],
            'insuranceName' => $p['insurance_name'] ?? '',
            'insuranceCode' => $p['insurance_code'] ?? null,
            'companyName' => $p['company_name'] ?? '',
            'branchName' => $p['branch_name'] ?? '',
            'plateNo' => $p['plate_no'] ?? null,
            'startsAt' => $p['starts_at'],
            'expiresAt' => $p['expires_at'],
            'effectiveExpiresAt' => $effectiveExpiresAt,
            'grossPremium' => $signedGross,
            'netPremium' => $signedNet,
            'totalGrossPremium' => $totalGross,
            'totalNetPremium' => $totalNet,
            'companyCommRate' => (float) ($p['company_comm_rate'] ?? 0),
            'branchCommRate' => (float) ($p['branch_comm_rate'] ?? 0),
            'companyCommAmount' => isset($p['company_comm_amount']) && $p['company_comm_amount'] !== null ? (float) $p['company_comm_amount'] : null,
            'branchCommAmount' => isset($p['branch_comm_amount']) && $p['branch_comm_amount'] !== null ? (float) $p['branch_comm_amount'] : null,
            'isCancelled' => (bool) $p['is_cancelled'],
            'endorsementNo' => (int) ($p['endorsement_no'] ?? $p['latest_endorsement_no'] ?? 0),
            'status' => $status,
            'zeyilCount' => isset($p['zeyil_count']) ? (int) $p['zeyil_count'] : 0,
            'branchGroup' => $p['insurance_branch_group'] ?? null,
        ];
    }

    private function calculatePolicyStatus(array $p): string
    {
        if ((int) ($p['is_cancelled'] ?? 0) === 1) return 'CANCELLED';
        if (isset($p['latest_is_cancelled']) && (int) $p['latest_is_cancelled'] === 1) return 'CANCELLED';
        $effectiveExpires = $p['effective_expires_at'] ?? $p['expires_at'];
        if ($effectiveExpires && strtotime($effectiveExpires) < strtotime(date('Y-m-d'))) return 'EXPIRED';
        return 'ACTIVE';
    }

    public function updateNote(array $user, int $id, array $input): void
    {
        $note = trim($input['note'] ?? '');
        Database::update('customers', ['note' => $note, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        Response::success(null, 'Not kaydedildi');
    }

    /**
     * GET /api/customers/{id}/vehicle-status - Müşteri araç durumu kontrolü
     */
    public function vehicleStatus(array $user, int $id): void
    {
        $customer = Database::fetch("SELECT has_vehicle FROM customers WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$customer) {
            Response::error('Müşteri bulunamadı', 404);
        }

        $hasPlatedPolicy = Database::fetch(
            "SELECT COUNT(*) as cnt FROM policies WHERE customer_id = ? AND plate_no IS NOT NULL AND plate_no != '' AND deleted_at IS NULL",
            [$id]
        );

        Response::success([
            'hasPlatedPolicy' => (int) ($hasPlatedPolicy['cnt'] ?? 0) > 0,
            'hasVehicle' => $customer['has_vehicle'] ?? 'UNKNOWN',
        ]);
    }

    /**
     * GET /api/customers/{id}/notes - Müşteri notlarını listele
     */
    public function notes(array $user, int $id): void
    {
        $customer = Database::fetch("SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$customer) {
            Response::error('Müşteri bulunamadı', 404);
        }

        $notes = Database::fetchAll(
            "SELECT cn.id, cn.customer_id, cn.type, cn.note, cn.task_id, cn.policy_id,
                    cn.created_by, cn.created_at, u.name as created_by_name,
                    p.policy_no, i.name as insurance_name,
                    NULL as task_type, p.plate_no,
                    NULL as assigned_to_name, NULL as task_status, NULL as task_result,
                    NULL as stage_label
             FROM customer_notes cn
             LEFT JOIN users u ON cn.created_by = u.id
             LEFT JOIN policies p ON cn.policy_id = p.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE cn.customer_id = ?

             UNION ALL

             SELECT tn.id, t.customer_id, 'TASK' as type, tn.note, t.id as task_id, t.policy_id,
                    tn.created_by, tn.created_at, u2.name as created_by_name,
                    p2.policy_no,
                    COALESCE(i2.name, JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.insuranceName'))) as insurance_name,
                    t.type as task_type, p2.plate_no,
                    ua.name as assigned_to_name, t.status as task_status, t.result as task_result,
                    JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stageLabel')) as stage_label
             FROM task_notes tn
             JOIN tasks t ON tn.task_id = t.id AND t.deleted_at IS NULL
             LEFT JOIN users u2 ON tn.created_by = u2.id
             LEFT JOIN users ua ON t.assigned_to = ua.id
             LEFT JOIN policies p2 ON t.policy_id = p2.id
             LEFT JOIN insurance_types i2 ON p2.insurance_type_id = i2.id
             WHERE t.customer_id = ?

             ORDER BY created_at DESC",
            [$id, $id]
        );

        $data = array_map(function ($n) {
            return [
                'id' => (int) $n['id'],
                'customerId' => (int) $n['customer_id'],
                'type' => $n['type'],
                'note' => $n['note'],
                'taskId' => $n['task_id'] ? (int) $n['task_id'] : null,
                'policyId' => $n['policy_id'] ? (int) $n['policy_id'] : null,
                'policyNo' => $n['policy_no'] ?? null,
                'insuranceName' => $n['insurance_name'] ?? null,
                'taskType' => $n['task_type'] ?? null,
                'plateNo' => $n['plate_no'] ?? null,
                'assignedToName' => $n['assigned_to_name'] ?? null,
                'taskStatus' => $n['task_status'] ?? null,
                'taskResult' => $n['task_result'] ?? null,
                'stageLabel' => $n['stage_label'] ?? null,
                'createdBy' => (int) $n['created_by'],
                'createdByName' => $n['created_by_name'] ?? null,
                'createdAt' => $n['created_at'],
            ];
        }, $notes);

        Response::success($data);
    }

    /**
     * POST /api/customers/{id}/notes - Manuel müşteri notu ekle
     */
    public function addNote(array $user, int $id, array $input): void
    {
        $customer = Database::fetch("SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$customer) {
            Response::error('Müşteri bulunamadı', 404);
        }

        $note = trim($input['note'] ?? '');
        if ($note === '') {
            Response::error('Not boş olamaz', 422);
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("INSERT INTO customer_notes (customer_id, type, note, created_by, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id, 'NOTE', $note, $user['userId'], date('Y-m-d H:i:s')]);

        Response::success(['id' => (int) $pdo->lastInsertId()], 'Not eklendi');
    }

    /**
     * Müşteri listesini XLSX olarak export et
     * GET /api/customers/export?search=&type=&categoryId=
     */
    public function export(array $user, array $query): void
    {
        Permission::require($user, 'customers.export');

        $where = ["c.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $search = '%' . $query['search'] . '%';
            $where[] = "(c.name LIKE ? OR c.identity_no LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
            $params = array_merge($params, [$search, $search, $search, $search]);
        }

        if (!empty($query['type'])) {
            $where[] = "c.customer_type = ?";
            $params[] = $query['type'];
        }

        if (!empty($query['categoryId'])) {
            $catId = (int) $query['categoryId'];
            $cat = Database::fetch("SELECT min_amount, max_amount FROM customer_categories WHERE id = ? AND deleted_at IS NULL", [$catId]);
            if ($cat) {
                $totalGrossSub2 = "(SELECT COALESCE(SUM(CASE WHEN p3.is_cancelled = 1 AND p3.gross_premium > 0 THEN -p3.gross_premium ELSE p3.gross_premium END), 0) FROM policies p3 WHERE p3.customer_id = c.id AND p3.deleted_at IS NULL)";
                if ($cat['min_amount'] !== null) {
                    $where[] = "$totalGrossSub2 >= ?";
                    $params[] = (float) $cat['min_amount'];
                }
                if ($cat['max_amount'] !== null) {
                    $where[] = "$totalGrossSub2 < ?";
                    $params[] = (float) $cat['max_amount'];
                }
            }
        }

        $whereSql = implode(' AND ', $where);

        $activePolicySub = "(SELECT COUNT(*) FROM policies p WHERE p.customer_id = c.id AND p.parent_id IS NULL AND p.deleted_at IS NULL
            AND p.is_cancelled = 0
            AND (SELECT z.is_cancelled FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL ORDER BY z.endorsement_no DESC LIMIT 1) = 0
            AND (SELECT z2.expires_at FROM policies z2 WHERE z2.policy_no = p.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1) >= CURDATE())";
        $totalGrossSub = "(SELECT COALESCE(SUM(CASE WHEN zp.is_cancelled = 1 AND zp.gross_premium > 0 THEN -zp.gross_premium ELSE zp.gross_premium END), 0)
            FROM policies zp
            INNER JOIN policies p2 ON p2.policy_no = zp.policy_no AND p2.parent_id IS NULL
            WHERE p2.customer_id = c.id
              AND p2.deleted_at IS NULL
              AND p2.is_cancelled = 0
              AND zp.deleted_at IS NULL
              AND (SELECT z.is_cancelled FROM policies z WHERE z.policy_no = p2.policy_no AND z.deleted_at IS NULL ORDER BY z.endorsement_no DESC LIMIT 1) = 0
              AND (SELECT z2.expires_at FROM policies z2 WHERE z2.policy_no = p2.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1) >= CURDATE())";

        $sql = "SELECT c.id, c.name, c.identity_no, c.phone, c.email, c.birth_date, c.customer_type,
                       $activePolicySub as active_policies,
                       $totalGrossSub as total_gross
                FROM customers c
                WHERE $whereSql
                ORDER BY c.created_at DESC";

        $rows = Database::fetchAll($sql, $params);

        // Müşteri tipi etiketlerini dönüştür
        foreach ($rows as &$r) {
            $r['total_gross'] = (float) $r['total_gross'];
            $r['active_policies'] = (int) $r['active_policies'];
            $r['customer_type_label'] = $r['customer_type'] === 'INDIVIDUAL' ? 'Bireysel' : 'Kurumsal';
        }
        unset($r);

        $columns = [
            ['key' => 'id',                  'label' => 'ID',              'type' => Response::COL_NUMBER],
            ['key' => 'name',                'label' => 'Müşteri Adı'],
            ['key' => 'identity_no',         'label' => 'TC/Vergi No',    'type' => Response::COL_IDENTIFIER],
            ['key' => 'phone',               'label' => 'Telefon',        'type' => Response::COL_IDENTIFIER],
            ['key' => 'email',               'label' => 'E-posta'],
            ['key' => 'birth_date',          'label' => 'Doğum Tarihi',   'type' => Response::COL_DATE],
            ['key' => 'total_gross',         'label' => 'Toplam Prim',    'type' => Response::COL_CURRENCY],
            ['key' => 'active_policies',     'label' => 'Aktif Poliçe',   'type' => Response::COL_NUMBER],
            ['key' => 'customer_type_label', 'label' => 'Müşteri Tipi'],
        ];

        Response::xlsx($rows, $columns, 'musteriler_' . date('Y-m-d') . '.xlsx');
    }

}
