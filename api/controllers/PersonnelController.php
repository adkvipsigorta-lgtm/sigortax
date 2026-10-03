<?php

class PersonnelController
{
    /**
     * Personel listesi (ozet bilgiler)
     */
    public function index(array $user, array $query): void
    {
        Permission::require($user, 'personnel.view');

        $where = ["u.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.tc_no LIKE ?)";
            $s = '%' . $query['search'] . '%';
            $params = array_merge($params, [$s, $s, $s]);
        }

        if (isset($query['isActive'])) {
            $where[] = "u.is_active = ?";
            $params[] = (int) $query['isActive'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT u.id, u.name, u.email, u.phone, u.tc_no, u.role, u.is_active,
                       u.hire_date, u.termination_date, u.position, u.branch_id,
                       u.birth_date, u.created_at,
                       (SELECT COUNT(*) FROM leave_requests lr
                        WHERE lr.user_id = u.id AND lr.status = 'APPROVED'
                        AND lr.start_date <= CURDATE() AND lr.end_date >= CURDATE()
                        AND lr.deleted_at IS NULL) as is_on_leave
                FROM users u
                WHERE $whereSql
                ORDER BY u.is_active DESC, u.name ASC";

        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
        $result = Database::paginate($sql, $params, $page, $limit);

        $roleMap = [1 => 'admin', 0 => 'kullanici', 2 => 'acente'];
        $result['data'] = array_map(function ($u) use ($roleMap) {
            return [
                'id' => (int) $u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'phone' => $u['phone'],
                'tcNo' => $u['tc_no'],
                'role' => $roleMap[(int) $u['role']] ?? 'kullanici',
                'isActive' => (bool) $u['is_active'],
                'hireDate' => $u['hire_date'],
                'terminationDate' => $u['termination_date'],
                'position' => $u['position'],
                'branchId' => $u['branch_id'] ? (int) $u['branch_id'] : null,
                'birthDate' => $u['birth_date'],
                'createdAt' => $u['created_at'],
                'isOnLeave' => (bool) $u['is_on_leave'],
            ];
        }, $result['data']);

        Response::paginated($result);
    }

    /**
     * Personel detay (ozluk bilgileri)
     */
    public function show(array $user, int $id): void
    {
        AuthMiddleware::requireAdminOrSelf($user, $id);

        $u = Database::fetch(
            "SELECT u.*, b.name as branch_name
             FROM users u
             LEFT JOIN branches b ON u.branch_id = b.id
             WHERE u.id = ? AND u.deleted_at IS NULL",
            [$id]
        );

        if (!$u) {
            Response::error('Personel bulunamadi', 404);
        }

        $roleMap = [1 => 'admin', 0 => 'kullanici', 2 => 'acente'];

        // Kidem hesapla
        $seniorityYears = null;
        if ($u['hire_date']) {
            $hire = new DateTime($u['hire_date']);
            $now = new DateTime();
            $seniorityYears = $hire->diff($now)->y;
        }

        Response::success([
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'phone' => $u['phone'],
            'tcNo' => $u['tc_no'],
            'birthDate' => $u['birth_date'],
            'role' => $roleMap[(int) $u['role']] ?? 'kullanici',
            'isActive' => (bool) $u['is_active'],
            'branchId' => $u['branch_id'] ? (int) $u['branch_id'] : null,
            'branchName' => $u['branch_name'],
            'hireDate' => $u['hire_date'],
            'terminationDate' => $u['termination_date'],
            'position' => $u['position'],
            'address' => $u['address'],
            'countryId' => $u['country_id'] ? (int) $u['country_id'] : null,
            'cityId' => $u['city_id'] ? (int) $u['city_id'] : null,
            'districtId' => $u['district_id'] ? (int) $u['district_id'] : null,
            'emergencyContactName' => $u['emergency_contact_name'],
            'emergencyContactPhone' => $u['emergency_contact_phone'],
            'emergencyContactRelation' => $u['emergency_contact_relation'],
            'seniorityYears' => $seniorityYears,
            'createdAt' => $u['created_at'],
        ]);
    }

    /**
     * Personel ozluk bilgileri guncelle
     */
    public function update(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $existing = Database::fetch("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Personel bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];

        $fields = [
            'phone' => 'phone',
            'tcNo' => 'tc_no',
            'birthDate' => 'birth_date',
            'hireDate' => 'hire_date',
            'terminationDate' => 'termination_date',
            'position' => 'position',
            'address' => 'address',
            'countryId' => 'country_id',
            'cityId' => 'city_id',
            'districtId' => 'district_id',
            'emergencyContactName' => 'emergency_contact_name',
            'emergencyContactPhone' => 'emergency_contact_phone',
            'emergencyContactRelation' => 'emergency_contact_relation',
        ];

        foreach ($fields as $inputKey => $dbKey) {
            if (array_key_exists($inputKey, $input)) {
                $data[$dbKey] = $input[$inputKey] ?: null;
            }
        }

        Database::update('users', $data, 'id = ?', [$id]);

        Response::success(null, 'Personel bilgileri guncellendi');
    }

    /**
     * Dashboard istatistikleri
     */
    public function stats(array $user, array $query): void
    {
        AuthMiddleware::requireAdmin($user);

        $totalActive = Database::fetch(
            "SELECT COUNT(*) as cnt FROM users WHERE is_active = 1 AND deleted_at IS NULL"
        )['cnt'];

        $totalInactive = Database::fetch(
            "SELECT COUNT(*) as cnt FROM users WHERE is_active = 0 AND deleted_at IS NULL"
        )['cnt'];

        $onLeave = Database::fetch(
            "SELECT COUNT(DISTINCT lr.user_id) as cnt FROM leave_requests lr
             JOIN users u ON lr.user_id = u.id
             WHERE lr.status = 'APPROVED' AND lr.start_date <= CURDATE() AND lr.end_date >= CURDATE()
             AND lr.deleted_at IS NULL AND u.deleted_at IS NULL"
        )['cnt'];

        $pendingLeaves = Database::fetch(
            "SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'PENDING' AND deleted_at IS NULL"
        )['cnt'];

        Response::success([
            'totalActive' => (int) $totalActive,
            'totalInactive' => (int) $totalInactive,
            'onLeave' => (int) $onLeave,
            'pendingLeaves' => (int) $pendingLeaves,
        ]);
    }

    // ==================== IZIN YONETIMI ====================

    /**
     * Izin turleri listesi
     */
    public function leaveTypes(array $user): void
    {
        $types = Database::fetchAll("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY sort_order ASC");
        $result = array_map(function ($t) {
            return [
                'id' => (int) $t['id'],
                'name' => $t['name'],
                'defaultDays' => (int) $t['default_days'],
                'isPaid' => (bool) $t['is_paid'],
            ];
        }, $types);
        Response::success($result);
    }

    /**
     * Kullanicinin izin bakiyeleri
     */
    public function leaveBalances(array $user, int $userId, array $query): void
    {
        AuthMiddleware::requireAdminOrSelf($user, $userId);

        $year = (int) ($query['year'] ?? date('Y'));

        // Otomatik olustur: yoksa default_days ile ekle
        $types = Database::fetchAll("SELECT id, default_days FROM leave_types WHERE is_active = 1");
        foreach ($types as $t) {
            $exists = Database::fetch(
                "SELECT id FROM leave_balances WHERE user_id = ? AND leave_type_id = ? AND year = ?",
                [$userId, $t['id'], $year]
            );
            if (!$exists) {
                Database::insert('leave_balances', [
                    'user_id' => $userId,
                    'leave_type_id' => $t['id'],
                    'year' => $year,
                    'total_days' => $t['default_days'],
                    'used_days' => 0,
                ]);
            }
        }

        $balances = Database::fetchAll(
            "SELECT lb.*, lt.name as type_name, lt.is_paid
             FROM leave_balances lb
             JOIN leave_types lt ON lb.leave_type_id = lt.id
             WHERE lb.user_id = ? AND lb.year = ?
             ORDER BY lt.sort_order ASC",
            [$userId, $year]
        );

        $result = array_map(function ($b) {
            return [
                'id' => (int) $b['id'],
                'leaveTypeId' => (int) $b['leave_type_id'],
                'typeName' => $b['type_name'],
                'isPaid' => (bool) $b['is_paid'],
                'year' => (int) $b['year'],
                'totalDays' => (float) $b['total_days'],
                'usedDays' => (float) $b['used_days'],
                'remainingDays' => (float) $b['total_days'] - (float) $b['used_days'],
            ];
        }, $balances);

        Response::success($result);
    }

    /**
     * Izin bakiyesi guncelle (admin)
     */
    public function updateLeaveBalance(array $user, int $userId, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        if (empty($input['leaveTypeId']) || !isset($input['totalDays'])) {
            Response::error('Izin turu ve toplam gun gerekli', 422);
        }

        $year = (int) ($input['year'] ?? date('Y'));

        $existing = Database::fetch(
            "SELECT id FROM leave_balances WHERE user_id = ? AND leave_type_id = ? AND year = ?",
            [$userId, $input['leaveTypeId'], $year]
        );

        if ($existing) {
            Database::update('leave_balances', [
                'total_days' => (float) $input['totalDays'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$existing['id']]);
        } else {
            Database::insert('leave_balances', [
                'user_id' => $userId,
                'leave_type_id' => $input['leaveTypeId'],
                'year' => $year,
                'total_days' => (float) $input['totalDays'],
                'used_days' => 0,
            ]);
        }

        Response::success(null, 'Izin bakiyesi guncellendi');
    }

    /**
     * Izin talepleri listesi
     */
    public function leaveRequests(array $user, array $query): void
    {
        $where = ["lr.deleted_at IS NULL"];
        $params = [];

        // Admin degilse sadece kendi izinlerini gorsun
        if ((int) $user['role'] !== 1) {
            $where[] = "lr.user_id = ?";
            $params[] = (int) $user['userId'];
        } elseif (!empty($query['userId'])) {
            $where[] = "lr.user_id = ?";
            $params[] = (int) $query['userId'];
        }

        if (!empty($query['status'])) {
            $where[] = "lr.status = ?";
            $params[] = $query['status'];
        }

        if (!empty($query['year'])) {
            $where[] = "YEAR(lr.start_date) = ?";
            $params[] = (int) $query['year'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT lr.*, u.name as user_name, lt.name as type_name,
                       au.name as approved_by_name
                FROM leave_requests lr
                JOIN users u ON lr.user_id = u.id
                JOIN leave_types lt ON lr.leave_type_id = lt.id
                LEFT JOIN users au ON lr.approved_by = au.id
                WHERE $whereSql
                ORDER BY lr.created_at DESC";

        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
        $result = Database::paginate($sql, $params, $page, $limit);

        $result['data'] = array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'userId' => (int) $r['user_id'],
                'userName' => $r['user_name'],
                'leaveTypeId' => (int) $r['leave_type_id'],
                'typeName' => $r['type_name'],
                'startDate' => $r['start_date'],
                'endDate' => $r['end_date'],
                'days' => (float) $r['days'],
                'note' => $r['note'],
                'status' => $r['status'],
                'approvedBy' => $r['approved_by'] ? (int) $r['approved_by'] : null,
                'approvedByName' => $r['approved_by_name'],
                'approvedAt' => $r['approved_at'],
                'rejectionNote' => $r['rejection_note'],
                'createdAt' => $r['created_at'],
            ];
        }, $result['data']);

        Response::paginated($result);
    }

    /**
     * Izin talebi olustur
     */
    public function createLeaveRequest(array $user, array $input): void
    {
        $userId = (int) ($input['userId'] ?? $user['userId']);

        // Admin baskasi icin olusturabilir, diger kullanicilar sadece kendileri icin
        if ((int) $user['role'] !== 1 && $userId !== (int) $user['userId']) {
            Response::error('Yetkiniz yok', 403);
        }

        if (empty($input['leaveTypeId']) || empty($input['startDate']) || empty($input['endDate'])) {
            Response::error('Izin turu, baslangic ve bitis tarihi gerekli', 422);
        }

        $start = new DateTime($input['startDate']);
        $end = new DateTime($input['endDate']);

        if ($end < $start) {
            Response::error('Bitis tarihi baslangic tarihinden once olamaz', 422);
        }

        // Is gunu hesapla (hafta sonu haric)
        $days = 0;
        $current = clone $start;
        while ($current <= $end) {
            $dow = (int) $current->format('N');
            if ($dow <= 5) $days++;
            $current->modify('+1 day');
        }

        if ($days <= 0) {
            Response::error('Gecerli bir tarih araligi secin', 422);
        }

        // Cakisma kontrolu
        $overlap = Database::fetch(
            "SELECT id FROM leave_requests
             WHERE user_id = ? AND status IN ('PENDING','APPROVED')
             AND deleted_at IS NULL
             AND start_date <= ? AND end_date >= ?",
            [$userId, $input['endDate'], $input['startDate']]
        );

        if ($overlap) {
            Response::error('Bu tarih araliginda zaten bir izin talebi var', 422);
        }

        // Bakiye kontrolu
        $year = (int) $start->format('Y');
        $balance = Database::fetch(
            "SELECT total_days, used_days FROM leave_balances
             WHERE user_id = ? AND leave_type_id = ? AND year = ?",
            [$userId, $input['leaveTypeId'], $year]
        );

        if ($balance) {
            $remaining = (float) $balance['total_days'] - (float) $balance['used_days'];
            if ($days > $remaining && (float) $balance['total_days'] > 0) {
                Response::error("Yeterli izin hakkiniz yok. Kalan: $remaining gun", 422);
            }
        }

        $id = Database::insert('leave_requests', [
            'user_id' => $userId,
            'leave_type_id' => (int) $input['leaveTypeId'],
            'start_date' => $input['startDate'],
            'end_date' => $input['endDate'],
            'days' => $days,
            'note' => $input['note'] ?? null,
            'status' => 'PENDING',
        ]);

        Response::success(['id' => $id], 'Izin talebi olusturuldu', 201);
    }

    /**
     * Izin talebi onayla/reddet (admin)
     */
    public function updateLeaveRequest(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $request = Database::fetch(
            "SELECT * FROM leave_requests WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$request) {
            Response::error('Izin talebi bulunamadi', 404);
        }

        $status = $input['status'] ?? null;
        if (!in_array($status, ['APPROVED', 'REJECTED', 'CANCELLED'])) {
            Response::error('Gecersiz durum', 422);
        }

        $data = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($status === 'APPROVED') {
            $data['approved_by'] = (int) $user['userId'];
            $data['approved_at'] = date('Y-m-d H:i:s');

            // Bakiyeden dus
            $year = (int) (new DateTime($request['start_date']))->format('Y');
            Database::query(
                "UPDATE leave_balances SET used_days = used_days + ?, updated_at = NOW()
                 WHERE user_id = ? AND leave_type_id = ? AND year = ?",
                [(float) $request['days'], (int) $request['user_id'], (int) $request['leave_type_id'], $year]
            );
        }

        if ($status === 'REJECTED') {
            $data['rejection_note'] = $input['rejectionNote'] ?? null;
        }

        if ($status === 'CANCELLED' && $request['status'] === 'APPROVED') {
            // Onaylanmis izin iptal edilirse bakiyeyi geri ver
            $year = (int) (new DateTime($request['start_date']))->format('Y');
            Database::query(
                "UPDATE leave_balances SET used_days = GREATEST(0, used_days - ?), updated_at = NOW()
                 WHERE user_id = ? AND leave_type_id = ? AND year = ?",
                [(float) $request['days'], (int) $request['user_id'], (int) $request['leave_type_id'], $year]
            );
        }

        Database::update('leave_requests', $data, 'id = ?', [$id]);

        Response::success(null, 'Izin talebi guncellendi');
    }

    /**
     * Izin talebi sil (sadece PENDING durumunda)
     */
    public function deleteLeaveRequest(array $user, int $id): void
    {
        $request = Database::fetch(
            "SELECT * FROM leave_requests WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$request) {
            Response::error('Izin talebi bulunamadi', 404);
        }

        // Kendi talebi mi veya admin mi
        if ((int) $user['role'] !== 1 && (int) $request['user_id'] !== (int) $user['userId']) {
            Response::error('Yetkiniz yok', 403);
        }

        if ($request['status'] !== 'PENDING') {
            Response::error('Sadece bekleyen talepler silinebilir', 422);
        }

        Database::softDelete('leave_requests', $id);
        Response::success(null, 'Izin talebi silindi');
    }

    /**
     * Personel performans ozeti
     */
    public function performance(array $user, int $userId, array $query): void
    {
        AuthMiddleware::requireAdminOrSelf($user, $userId);

        $year = (int) ($query['year'] ?? date('Y'));
        $month = !empty($query['month']) ? (int) $query['month'] : null;

        $dateFilter = "YEAR(p.starts_at) = ?";
        $params = [$userId, $year];

        if ($month) {
            $dateFilter .= " AND MONTH(p.starts_at) = ?";
            $params[] = $month;
        }

        // Police sayisi ve prim toplami
        $policySummary = Database::fetch(
            "SELECT COUNT(*) as total_policies,
                    COALESCE(SUM(p.gross_premium), 0) as total_premium,
                    COALESCE(SUM(p.net_premium), 0) as total_net_premium
             FROM policies p
             WHERE p.representative_id = ? AND $dateFilter
               AND p.deleted_at IS NULL AND p.is_cancelled = 0",
            $params
        );

        // Gorev istatistikleri
        $taskParams = [$userId, $year];
        $taskDateFilter = "YEAR(t.created_at) = ?";
        if ($month) {
            $taskDateFilter .= " AND MONTH(t.created_at) = ?";
            $taskParams[] = $month;
        }

        $taskSummary = Database::fetch(
            "SELECT COUNT(*) as total_tasks,
                    SUM(CASE WHEN t.status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_tasks,
                    SUM(CASE WHEN t.status IN ('PENDING','IN_PROGRESS') THEN 1 ELSE 0 END) as active_tasks
             FROM tasks t
             WHERE t.assigned_to = ? AND $taskDateFilter
               AND t.deleted_at IS NULL",
            $taskParams
        );

        // Aylik kirilim
        $monthlyData = Database::fetchAll(
            "SELECT MONTH(p.starts_at) as month,
                    COUNT(*) as policies,
                    COALESCE(SUM(p.gross_premium), 0) as premium
             FROM policies p
             WHERE p.representative_id = ? AND YEAR(p.starts_at) = ?
               AND p.deleted_at IS NULL AND p.is_cancelled = 0
             GROUP BY MONTH(p.starts_at)
             ORDER BY MONTH(p.starts_at) ASC",
            [$userId, $year]
        );

        Response::success([
            'totalPolicies' => (int) $policySummary['total_policies'],
            'totalPremium' => (float) $policySummary['total_premium'],
            'totalNetPremium' => (float) $policySummary['total_net_premium'],
            'totalTasks' => (int) $taskSummary['total_tasks'],
            'completedTasks' => (int) $taskSummary['completed_tasks'],
            'activeTasks' => (int) $taskSummary['active_tasks'],
            'completionRate' => (int) $taskSummary['total_tasks'] > 0
                ? round((int) $taskSummary['completed_tasks'] / (int) $taskSummary['total_tasks'] * 100, 1)
                : 0,
            'monthlyData' => array_map(function ($m) {
                return [
                    'month' => (int) $m['month'],
                    'policies' => (int) $m['policies'],
                    'premium' => (float) $m['premium'],
                ];
            }, $monthlyData),
        ]);
    }
}
