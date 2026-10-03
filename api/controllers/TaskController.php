<?php

require_once __DIR__ . '/NotificationController.php';

class TaskController
{
    /**
     * Adminlere bildirim gonder (aksiyonu yapan kisi haric)
     */
    private function notifyAdmins(int $excludeUserId, string $title, string $message, string $data = '/gorevler'): void
    {
        $admins = Database::fetchAll("SELECT id FROM users WHERE role = 1 AND deleted_at IS NULL AND is_active = 1 AND id != ?", [$excludeUserId]);
        foreach ($admins as $admin) {
            NotificationController::create((int) $admin['id'], $title, $message, $data, 'info');
        }
    }

    /**
     * Parse date from various formats (DD/MM/YYYY, YYYY-MM-DD, datetime-local) to YYYY-MM-DD HH:MM:SS
     */
    private function parseDeadline(?string $dateStr): ?string
    {
        if (!$dateStr) return null;
        // DD/MM/YYYY
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $dateStr, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1] . ' 00:00:00';
        }
        // YYYY-MM-DDTHH:MM (datetime-local)
        if (preg_match('#^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}#', $dateStr)) {
            return str_replace('T', ' ', $dateStr) . ':00';
        }
        // YYYY-MM-DD
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $dateStr)) {
            return $dateStr . ' 00:00:00';
        }
        // YYYY-MM-DD HH:MM:SS already
        if (preg_match('#^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$#', $dateStr)) {
            return $dateStr;
        }
        return null;
    }

    public function index(array $user, array $query): void
    {
        Permission::require($user, 'tasks.view');
        $where = ["t.deleted_at IS NULL"];
        $params = [];

        // Non-admin users can only see tasks assigned to them
        if ((int) $user['role'] !== 1) {
            $where[] = "t.assigned_to = ?";
            $params[] = $user['userId'];
        }

        if (!empty($query['status'])) {
            $where[] = "t.status = ?";
            $params[] = $query['status'];
        }

        if (!empty($query['type'])) {
            $where[] = "t.type = ?";
            $params[] = $query['type'];
        }

        $assignedToFilter = $query['assignedTo'] ?? $query['assigned_to'] ?? null;
        if (!empty($assignedToFilter)) {
            $where[] = "t.assigned_to = ?";
            $params[] = $assignedToFilter;
        }

        if (!empty($query['result'])) {
            $where[] = "t.result = ?";
            $params[] = $query['result'];
        }

        if (!empty($query['customerId'])) {
            $where[] = "t.customer_id = ?";
            $params[] = (int) $query['customerId'];
        }

        if (!empty($query['search'])) {
            $search = '%' . $query['search'] . '%';
            $searchNoSpace = '%' . str_replace(' ', '', $query['search']) . '%';
            $where[] = "(t.title LIKE ? OR t.description LIKE ? OR cu.name LIKE ? OR cu.identity_no LIKE ? OR REPLACE(p.plate_no, ' ', '') LIKE ? OR p.plate_no LIKE ? OR p.policy_no LIKE ?)";
            $params = array_merge($params, [$search, $search, $search, $search, $searchNoSpace, $search, $search]);
        }

        // Gorev tipine gore dogru tarih alani
        $dateExpr = "CASE
            WHEN t.type = 'RENEWAL' THEN p.expires_at
            WHEN t.type IN ('OFFER', 'CROSS_SELL') THEN COALESCE(t.offer_expires_at, t.deadline)
            ELSE t.deadline
        END";

        if (!empty($query['dateFrom'])) {
            $where[] = "($dateExpr) >= ?";
            $params[] = $query['dateFrom'];
        }
        if (!empty($query['dateTo'])) {
            $where[] = "($dateExpr) <= ?";
            $params[] = $query['dateTo'];
        }

        // is_renewable=0 olan sigorta turlerinin sadece RENEWAL gorevlerini gizle
        $where[] = "(t.type != 'RENEWAL' OR EXISTS (SELECT 1 FROM policies pp INNER JOIN insurance_types it ON it.id = pp.insurance_type_id AND it.is_renewable = 1 WHERE pp.id = t.policy_id))";

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? ITEMS_PER_PAGE)));

        // Kalan gun (SQL ile): hep bitis tarihine gore.
        //   OFFER  → t.offer_expires_at (ayri sutun, index'li)
        //   RENEWAL → bagli police p.expires_at
        //   Diger (OTHER) → t.deadline
        //   COMPLETED / CANCELLED → NULL
        $daysExpr = "CASE
            WHEN t.status IN ('COMPLETED','CANCELLED') THEN NULL
            WHEN t.type = 'RENEWAL' AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
            WHEN t.type = 'RENEWAL' AND p.expires_at IS NOT NULL THEN DATEDIFF(p.expires_at, CURDATE())
            WHEN t.type NOT IN ('OFFER','CROSS_SELL') AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
            WHEN t.offer_expires_at IS NOT NULL THEN DATEDIFF(t.offer_expires_at, CURDATE())
            WHEN t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
            ELSE NULL
        END";

        $sql = "SELECT t.*, u.name as assigned_to_name, cu.name as customer_name, cu.identity_no as customer_identity,
                       p.policy_no, p.plate_no, p.registration_no, p.expires_at as policy_expires_at, p.production_type,
                       i.name as insurance_name, i.color as insurance_color, co.name as company_name,
                       $daysExpr AS days_remaining
                FROM tasks t
                LEFT JOIN users u ON t.assigned_to = u.id AND u.is_active = 1 AND u.deleted_at IS NULL
                LEFT JOIN customers cu ON t.customer_id = cu.id
                LEFT JOIN policies p ON t.policy_id = p.id
                LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
                LEFT JOIN companies co ON p.company_id = co.id
                WHERE $whereSql
                ORDER BY CASE WHEN ($daysExpr) IS NULL THEN 2 WHEN ($daysExpr) < 0 THEN 1 ELSE 0 END ASC,
                         ($daysExpr) ASC,
                         CASE WHEN t.status IN ('COMPLETED','CANCELLED') THEN t.completed_at END DESC,
                         cu.name ASC, t.id ASC";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'formatTask'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $task = Database::fetch(
            "SELECT t.*, u.name as assigned_to_name, cu.name as customer_name,
                    p.policy_no, p.gross_premium, p.expires_at as policy_expires_at,
                    p.plate_no, p.registration_no,
                    i.name as insurance_name, i.color as insurance_color, co.name as company_name,
                    CASE
                        WHEN t.status IN ('COMPLETED','CANCELLED') THEN NULL
                        WHEN t.type NOT IN ('OFFER','CROSS_SELL') AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                        WHEN t.offer_expires_at IS NOT NULL THEN DATEDIFF(t.offer_expires_at, CURDATE())
                        WHEN t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                        ELSE NULL
                    END AS days_remaining
             FROM tasks t
             LEFT JOIN users u ON t.assigned_to = u.id AND u.is_active = 1 AND u.deleted_at IS NULL
             LEFT JOIN customers cu ON t.customer_id = cu.id
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN companies co ON p.company_id = co.id
             WHERE t.id = ? AND t.deleted_at IS NULL",
            [$id]
        );

        if (!$task) {
            Response::error('Gorev bulunamadi', 404);
        }

        // Non-admin can only see their own tasks
        if ((int) $user['role'] !== 1 && (int) $task['assigned_to'] !== (int) $user['userId']) {
            Response::error('Yetkiniz yok', 403);
        }

        // Get task logs
        $logs = Database::fetchAll(
            "SELECT tl.*, fu.name as from_user_name, tu.name as to_user_name
             FROM task_logs tl
             LEFT JOIN users fu ON tl.from_user_id = fu.id
             LEFT JOIN users tu ON tl.to_user_id = tu.id
             WHERE tl.task_id = ?
             ORDER BY tl.created_at ASC",
            [$id]
        );

        $formatted = $this->formatTask($task);

        // Extra policy details for show
        $formatted['policyDetails'] = $task['policy_id'] ? [
            'grossPremium' => $task['gross_premium'] ? (float) $task['gross_premium'] : null,
            'expiresAt' => $task['policy_expires_at'] ?? null,
            'plateNo' => $task['plate_no'] ?? null,
            'registrationNo' => $task['registration_no'] ?? null,
        ] : null;

        $formatted['logs'] = array_map(function ($log) {
            return [
                'id' => (int) $log['id'],
                'action' => $log['action'],
                'fromUserId' => $log['from_user_id'] ? (int) $log['from_user_id'] : null,
                'fromUserName' => $log['from_user_name'] ?? null,
                'toUserId' => $log['to_user_id'] ? (int) $log['to_user_id'] : null,
                'toUserName' => $log['to_user_name'] ?? null,
                'note' => $log['note'],
                'createdAt' => $log['created_at'],
            ];
        }, $logs);

        Response::success($formatted);
    }

    public function store(array $user, array $input): void
    {
        Permission::require($user, 'tasks.manage');
        $type = $input['type'] ?? '';

        // CROSS_SELL herkes olusturabilir, diger tipler admin gerektirir
        if ($type !== 'CROSS_SELL') {
            AuthMiddleware::requireAdmin($user);
        }
        $now = date('Y-m-d H:i:s');

        // Type-specific validation and data preparation
        if ($type === 'RENEWAL') {
            if (empty($input['policyId'])) {
                Response::error('Yenileme gorevi icin police secimi zorunludur', 422);
            }

            $policy = Database::fetch(
                "SELECT p.id, p.policy_no, p.expires_at, p.customer_id, cu.name as customer_name
                 FROM policies p
                 LEFT JOIN customers cu ON p.customer_id = cu.id
                 WHERE p.id = ? AND p.deleted_at IS NULL",
                [$input['policyId']]
            );

            if (!$policy) {
                Response::error('Police bulunamadi', 404);
            }

            // Ayni police icin aktif RENEWAL gorevi var mi?
            $existingTask = Database::fetch(
                "SELECT id FROM tasks WHERE type = 'RENEWAL' AND policy_id = ? AND status IN ('PENDING','IN_PROGRESS') AND deleted_at IS NULL",
                [$input['policyId']]
            );
            if ($existingTask) {
                Response::error('Bu poliçe için zaten aktif bir yenileme görevi var', 422);
            }

            $title = 'Yenileme: ' . ($policy['customer_name'] ?? '') . ' - ' . $policy['policy_no'];
            $customerId = $policy['customer_id'] ? (int) $policy['customer_id'] : null;
            // Ozel tarih varsa kullan, yoksa expires_at + 1 yil hesapla
            if (!empty($input['expiresAtOverride'])) {
                $renewalExpiresAt = $input['expiresAtOverride'];
            } else {
                $renewalExpiresAt = $policy['expires_at']
                    ? date('Y-m-d', strtotime($policy['expires_at'] . ' +1 year'))
                    : null;
            }
            $dueDate = $this->parseDeadline($renewalExpiresAt);

        } elseif ($type === 'OFFER') {
            // Offer: store offer details as JSON in offer_data column
            if (empty($input['customerId']) || empty($input['insuranceId'])) {
                Response::error('Musteri ve sigorta turu zorunludur', 422);
            }

            // Fetch customer & insurance names for title
            $customer = Database::fetch("SELECT name FROM customers WHERE id = ?", [$input['customerId']]);
            $insurance = Database::fetch("SELECT name FROM insurance_types WHERE id = ?", [$input['insuranceId']]);
            $company = !empty($input['companyId']) ? Database::fetch("SELECT name FROM companies WHERE id = ?", [$input['companyId']]) : null;

            $customerName = $customer ? $customer['name'] : '';
            $insuranceName = $insurance ? $insurance['name'] : '';
            $companyName = $company ? $company['name'] : '';

            $offerNumber = $input['offerNumber'] ?? '';
            $title = 'Teklif: ' . $customerName . ($offerNumber ? ' - ' . $offerNumber : '') . ' (' . $insuranceName . ')';
            $customerId = (int) $input['customerId'];

            // Store offer form data as JSON
            $offerData = json_encode([
                'customerId' => (int) $input['customerId'],
                'customerName' => $customerName,
                'insuranceId' => (int) $input['insuranceId'],
                'insuranceName' => $insuranceName,
                'companyId' => !empty($input['companyId']) ? (int) $input['companyId'] : null,
                'companyName' => $companyName,
                'offerNumber' => $offerNumber,
                'expiresAt' => $input['expiresAt'] ?? null,
                'plateNo' => $input['plateNo'] ?? null,
                'registrationNo' => $input['registrationNo'] ?? null,
                'vehicleBrand' => $input['vehicleBrand'] ?? null,
                'vehicleModel' => $input['vehicleModel'] ?? null,
                'vehicleYear' => $input['vehicleYear'] ?? null,
                'uavtCode' => $input['uavtCode'] ?? null,
                'network' => $input['network'] ?? null,
                'additionalInsureds' => $input['additionalInsureds'] ?? null,
                'note' => $input['offerNote'] ?? null,
            ], JSON_UNESCAPED_UNICODE);

            // Mükerrer teklif kontrolü: aynı müşteri + sigorta türü + plaka
            $plateNo = !empty($input['plateNo']) ? str_replace(' ', '', trim($input['plateNo'])) : null;
            $dupSql = "SELECT id FROM tasks WHERE type = 'OFFER' AND customer_id = ? AND deleted_at IS NULL AND status NOT IN ('COMPLETED','CANCELLED')
                       AND JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.insuranceId')) = ?";
            $dupParams = [$input['customerId'], (string) $input['insuranceId']];
            if ($plateNo) {
                $dupSql .= " AND REPLACE(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.plateNo')), ''), ' ', '') = ?";
                $dupParams[] = $plateNo;
            } else {
                $dupSql .= " AND (JSON_EXTRACT(offer_data, '$.plateNo') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.plateNo')) = '')";
            }
            $existingOffer = Database::fetch($dupSql, $dupParams);
            if ($existingOffer) {
                Response::error('Bu müşteri için aynı sigorta türü' . ($plateNo ? ' ve plaka' : '') . ' ile zaten aktif bir teklif var (Görev #' . $existingOffer['id'] . ')', 422);
            }

            // Due date: dogrudan expiresAt (takip suresi yok)
            $dueDate = $this->parseDeadline($input['expiresAt'] ?? $input['deadline'] ?? null);

        } elseif ($type === 'CROSS_SELL') {
            if (empty($input['customerId']) || empty($input['insuranceId'])) {
                Response::error('Musteri ve sigorta turu zorunludur', 422);
            }

            $customer = Database::fetch("SELECT name FROM customers WHERE id = ?", [$input['customerId']]);
            $insurance = Database::fetch("SELECT name FROM insurance_types WHERE id = ?", [$input['insuranceId']]);

            $customerName = $customer ? $customer['name'] : '';
            $insuranceName = $insurance ? $insurance['name'] : '';

            // Ayni musteri + ayni sigorta turu icin aktif capraz satis gorevi var mi?
            $existing = Database::fetch(
                "SELECT id FROM tasks WHERE type = 'CROSS_SELL' AND customer_id = ? AND deleted_at IS NULL AND status NOT IN ('COMPLETED','CANCELLED')
                 AND JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.insuranceId')) = ?",
                [$input['customerId'], (string) $input['insuranceId']]
            );
            if ($existing) {
                Response::error('Bu musteri icin zaten aktif bir capraz satis gorevi var', 422);
            }

            $title = 'Capraz Satis: ' . $customerName . ' (' . $insuranceName . ')';
            $customerId = (int) $input['customerId'];

            $offerData = json_encode([
                'customerId' => (int) $input['customerId'],
                'customerName' => $customerName,
                'insuranceId' => (int) $input['insuranceId'],
                'insuranceName' => $insuranceName,
                'policyNo' => $input['policyNo'] ?? null,
            ], JSON_UNESCAPED_UNICODE);

            $deadlineDays = (int) ($input['deadlineDays'] ?? 14);
            $dueDate = $this->parseDeadline($input['deadline'] ?? date('Y-m-d', strtotime($now . " + {$deadlineDays} days")));

        } elseif ($type === 'REFERENCE') {
            if (empty($input['refName'])) {
                Response::error('Ad Soyad zorunludur', 422);
            }

            $refIdentityNo = trim($input['refIdentityNo'] ?? '');
            $refName = trim($input['refName']);
            $refPhone = trim($input['refPhone'] ?? '');
            $refBirthDate = $input['refBirthDate'] ?? null;

            // Doğum tarihi formatını YYYY-MM-DD'ye çevir (GG/AA/YYYY, GG.AA.YYYY, GG-AA-YYYY)
            if ($refBirthDate) {
                if (preg_match('#^(\d{2})[/.\-](\d{2})[/.\-](\d{4})$#', $refBirthDate, $dm)) {
                    $refBirthDate = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
                }
            }

            // Telefon numarasını +90 formatına normalize et
            if ($refPhone) {
                $digits = preg_replace('/\D/', '', $refPhone);
                if (strlen($digits) === 10) {
                    $refPhone = '+90 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2);
                } elseif (strlen($digits) === 11 && $digits[0] === '0') {
                    $digits = substr($digits, 1);
                    $refPhone = '+90 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2);
                } elseif (strlen($digits) === 12 && substr($digits, 0, 2) === '90') {
                    $digits = substr($digits, 2);
                    $refPhone = '+90 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2);
                }
            }
            $refProduct = $input['refProduct'] ?? '';
            $refSourceId = !empty($input['refSourceId']) ? (int) $input['refSourceId'] : null;

            // TC ile mevcut müşteri ara
            $customerId = null;
            $customerAction = null;
            if ($refIdentityNo !== '') {
                $existing = Database::fetch(
                    "SELECT id, name FROM customers WHERE TRIM(identity_no) = ? AND deleted_at IS NULL LIMIT 1",
                    [$refIdentityNo]
                );
                if ($existing) {
                    $customerId = (int) $existing['id'];
                    $customerAction = 'matched';
                }
            }

            // Müşteri bulunamadıysa yeni oluştur
            if (!$customerId) {
                $custData = [
                    'customer_type' => 'INDIVIDUAL',
                    'name' => $refName,
                    'identity_no' => $refIdentityNo ?: null,
                    'phone' => $refPhone ?: null,
                    'birth_date' => $refBirthDate ?: null,
                    'note' => 'Referans ile eklendi',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $customerId = Database::insert('customers', $custData);
                $customerAction = 'created';
            }

            // Referans kaynağı adını al
            $refSourceName = '';
            if ($refSourceId) {
                $src = Database::fetch("SELECT name FROM reference_sources WHERE id = ? AND deleted_at IS NULL", [$refSourceId]);
                $refSourceName = $src ? $src['name'] : '';
            }

            $title = 'Referans: ' . $refName;

            // Detayları description'a yaz
            $descParts = [];
            $descParts[] = 'Ad Soyad: ' . $refName;
            if ($refIdentityNo) $descParts[] = 'TC Kimlik: ' . $refIdentityNo;
            if ($refBirthDate) $descParts[] = 'Doğum Tarihi: ' . $refBirthDate;
            if ($refPhone) $descParts[] = 'Telefon: ' . $refPhone;
            if ($refProduct) $descParts[] = 'Ürün: ' . $refProduct;
            if ($refSourceName) $descParts[] = 'Referans Kaynağı: ' . $refSourceName;
            if (!empty($input['description'])) $descParts[] = 'Açıklama: ' . $input['description'];
            if ($customerAction === 'created') $descParts[] = '(Yeni müşteri kaydı oluşturuldu)';
            elseif ($customerAction === 'matched') $descParts[] = '(Mevcut müşteri eşleştirildi)';

            $input['description'] = implode("\n", $descParts);
            $type = 'REFERENCE';
            $dueDate = $this->parseDeadline($input['deadline'] ?? null);

        } elseif ($type === 'FOLLOW_UP_CALL') {
            if (empty($input['policyId'])) {
                Response::error('Takip araması için poliçe seçimi zorunludur', 422);
            }

            $policy = Database::fetch(
                "SELECT p.id, p.policy_no, p.customer_id FROM policies p WHERE p.id = ? AND p.deleted_at IS NULL",
                [$input['policyId']]
            );
            if (!$policy) {
                Response::error('Poliçe bulunamadı', 404);
            }

            // Aynı (policy_id, stage) için aktif görev var mı?
            $stageKey = trim($input['stage'] ?? '');
            if ($stageKey !== '') {
                $dupTask = Database::fetch(
                    "SELECT id FROM tasks
                     WHERE type = 'FOLLOW_UP_CALL' AND policy_id = ?
                       AND status IN ('PENDING','IN_PROGRESS') AND deleted_at IS NULL
                       AND JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.stage')) = ?",
                    [(int) $input['policyId'], $stageKey]
                );
                if ($dupTask) {
                    Response::error('Bu poliçe ve aşama için zaten aktif bir takip görevi var', 422);
                }
            }

            $title = $input['title'] ?? ('Takip Araması: ' . $policy['policy_no']);
            $customerId = $policy['customer_id'] ? (int) $policy['customer_id'] : null;
            $dueDate = $this->parseDeadline($input['deadline'] ?? null);

            if ($stageKey !== '') {
                $offerData = json_encode(['stage' => $stageKey], JSON_UNESCAPED_UNICODE);
            }

        } elseif ($type === 'OTHER') {
            if (empty($input['title'])) {
                Response::error('Baslik zorunludur', 422);
            }
            $title = $input['title'];
            $customerId = !empty($input['customerId']) ? (int) $input['customerId'] : null;
            $dueDate = $this->parseDeadline($input['deadline'] ?? null);
        } else {
            Response::error('Gecersiz gorev tipi', 422);
        }

        $taskData = [
            'type' => $type,
            'title' => $title,
            'description' => $input['description'] ?? null,
            'policy_id' => $input['policyId'] ?? null,
            'customer_id' => $customerId,
            'assigned_to' => $input['assignedTo'] ?? null,
            'assigned_by' => !empty($input['assignedTo']) ? $user['userId'] : null,
            'created_by' => $user['userId'],
            'status' => 'PENDING',
            'priority' => $input['priority'] ?? 'MEDIUM',
            'deadline' => $dueDate,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // REFERENCE: offer_data JSON'a referans bilgilerini kaydet
        if ($type === 'REFERENCE') {
            $taskData['offer_data'] = json_encode([
                'refName' => $refName ?? null,
                'refIdentityNo' => $refIdentityNo ?? null,
                'refPhone' => $refPhone ?? null,
                'refBirthDate' => $refBirthDate ?? null,
                'refProduct' => $refProduct ?? null,
                'refSourceId' => $refSourceId ?? null,
                'refSourceName' => $refSourceName ?? null,
                'insuranceName' => $refProduct ?? null,
            ], JSON_UNESCAPED_UNICODE);
            if ($dueDate) {
                $taskData['offer_expires_at'] = date('Y-m-d', strtotime($dueDate));
            }
        }

        // OFFER/CROSS_SELL/FOLLOW_UP_CALL type: offer_data JSON + offer_expires_at sutunu
        if (in_array($type, ['OFFER', 'CROSS_SELL', 'FOLLOW_UP_CALL']) && isset($offerData)) {
            $taskData['offer_data'] = $offerData;
            if (!empty($input['expiresAt'])) {
                $taskData['offer_expires_at'] = $input['expiresAt'];
            }
        }
        // RENEWAL: policy.expires_at'i offer_expires_at sutununa kopyala (ayni mantik)
        if ($type === 'RENEWAL' && isset($renewalExpiresAt)) {
            $taskData['offer_expires_at'] = $renewalExpiresAt;
        }

        $id = Database::insert('tasks', $taskData);

        // Log CREATED
        Database::insert('task_logs', [
            'task_id' => $id,
            'action' => 'CREATED',
            'from_user_id' => $user['userId'],
            'note' => 'Gorev olusturuldu',
            'created_at' => $now,
        ]);

        // If assigned, also log ASSIGNED + send notification
        if (!empty($input['assignedTo'])) {
            Database::insert('task_logs', [
                'task_id' => $id,
                'action' => 'ASSIGNED',
                'from_user_id' => $user['userId'],
                'to_user_id' => $input['assignedTo'],
                'note' => 'Gorev atandi',
                'created_at' => $now,
            ]);

            $assignerName = Database::fetch("SELECT name FROM users WHERE id = ?", [$user['userId']]);
            NotificationController::create(
                (int) $input['assignedTo'],
                'Yeni Gorev Atandi',
                ($assignerName['name'] ?? 'Yonetici') . ' size bir gorev atadi: ' . $title,
                '/gorevler?taskId=' . $id,
                'info'
            );
        }

        Response::success(['id' => $id], 'Gorev olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        $existing = Database::fetch("SELECT * FROM tasks WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Gorev bulunamadi', 404);
        }

        $isAdmin = (int) $user['role'] === 1;
        $isAssigned = (int) $existing['assigned_to'] === (int) $user['userId'];

        if (!$isAdmin && !$isAssigned) {
            Response::error('Yetkiniz yok', 403);
        }

        $now = date('Y-m-d H:i:s');
        $data = ['updated_at' => $now];

        if ($isAdmin) {
            $fieldMap = [
                'title' => 'title',
                'description' => 'description',
                'type' => 'type',
                'priority' => 'priority',
                'deadline' => 'deadline',
                'assignedTo' => 'assigned_to',
                'status' => 'status',
                'customerId' => 'customer_id',
                'policyId' => 'policy_id',
            ];
            foreach ($fieldMap as $camel => $snake) {
                if (array_key_exists($camel, $input)) {
                    $data[$snake] = $camel === 'deadline' ? $this->parseDeadline($input[$camel]) : $input[$camel];
                }
            }
        } else {
            if (array_key_exists('status', $input)) {
                $data['status'] = $input['status'];
            }
        }

        // OFFER güncelleme: offer_data yeniden oluştur
        if ($isAdmin && $existing['type'] === 'OFFER' && array_key_exists('insuranceId', $input)) {
            $custId = $input['customerId'] ?? $existing['customer_id'];
            $customer = Database::fetch("SELECT name FROM customers WHERE id = ?", [$custId]);
            $insurance = Database::fetch("SELECT name FROM insurance_types WHERE id = ?", [$input['insuranceId']]);
            $company = !empty($input['companyId']) ? Database::fetch("SELECT name FROM companies WHERE id = ?", [$input['companyId']]) : null;

            $data['offer_data'] = json_encode([
                'customerId' => (int) $custId,
                'customerName' => $customer ? $customer['name'] : '',
                'insuranceId' => (int) $input['insuranceId'],
                'insuranceName' => $insurance ? $insurance['name'] : '',
                'companyId' => !empty($input['companyId']) ? (int) $input['companyId'] : null,
                'companyName' => $company ? $company['name'] : '',
                'offerNumber' => $input['offerNumber'] ?? '',
                'expiresAt' => $input['expiresAt'] ?? null,
                'plateNo' => $input['plateNo'] ?? null,
                'registrationNo' => $input['registrationNo'] ?? null,
                'vehicleBrand' => $input['vehicleBrand'] ?? null,
                'vehicleModel' => $input['vehicleModel'] ?? null,
                'vehicleYear' => $input['vehicleYear'] ?? null,
                'uavtCode' => $input['uavtCode'] ?? null,
                'network' => $input['network'] ?? null,
                'additionalInsureds' => $input['additionalInsureds'] ?? null,
                'note' => $input['offerNote'] ?? null,
            ], JSON_UNESCAPED_UNICODE);

            $customerName = $customer ? $customer['name'] : '';
            $insuranceName = $insurance ? $insurance['name'] : '';
            $data['title'] = 'Teklif: ' . $customerName . ' (' . $insuranceName . ')';
            $data['customer_id'] = (int) $custId;

            if (!empty($input['expiresAt'])) {
                $data['deadline'] = $this->parseDeadline($input['expiresAt']);
                $data['offer_expires_at'] = $input['expiresAt'];
            }
        }

        // Log status change
        if (!empty($data['status']) && $data['status'] !== $existing['status']) {
            Database::insert('task_logs', [
                'task_id' => $id,
                'action' => 'STATUS_CHANGED',
                'from_user_id' => $user['userId'],
                'note' => $existing['status'] . ' -> ' . $data['status'],
                'created_at' => $now,
            ]);
        }

        Database::update('tasks', $data, 'id = ?', [$id]);

        // Status degistiginde adminlere bildirim (kullanici aksiyonu ise)
        if (!$isAdmin && !empty($data['status']) && $data['status'] !== $existing['status']) {
            $userName = Database::fetch("SELECT name FROM users WHERE id = ?", [$user['userId']]);
            $statusLabels = ['IN_PROGRESS' => 'baslatti', 'CANCELLED' => 'iptal etti'];
            $actionLabel = $statusLabels[$data['status']] ?? 'guncelledi';
            $this->notifyAdmins(
                (int) $user['userId'],
                'Gorev Guncellendi',
                ($userName['name'] ?? 'Kullanici') . ' gorevi ' . $actionLabel . ': ' . $existing['title'],
                '/gorevler?taskId=' . $id
            );
        }

        Response::success(null, 'Gorev guncellendi');
    }

    public function assign(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $validator = new Validator();
        if (!$validator->validate($input, [
            'assignedTo' => 'required|numeric',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $existing = Database::fetch("SELECT * FROM tasks WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Gorev bulunamadi', 404);
        }

        $now = date('Y-m-d H:i:s');

        if (!empty($existing['assigned_to'])) {
            Database::insert('task_logs', [
                'task_id' => $id,
                'action' => 'REASSIGNED',
                'from_user_id' => (int) $existing['assigned_to'],
                'to_user_id' => (int) $input['assignedTo'],
                'note' => 'Gorev yeniden atandi',
                'created_at' => $now,
            ]);
        } else {
            Database::insert('task_logs', [
                'task_id' => $id,
                'action' => 'ASSIGNED',
                'from_user_id' => $user['userId'],
                'to_user_id' => (int) $input['assignedTo'],
                'note' => 'Gorev atandi',
                'created_at' => $now,
            ]);
        }

        $updateData = [
            'assigned_to' => (int) $input['assignedTo'],
            'status' => 'PENDING',
            'updated_at' => $now,
        ];

        // Allow deadline override on assign
        if (!empty($input['deadline'])) {
            $updateData['deadline'] = $this->parseDeadline($input['deadline']);
        }

        Database::update('tasks', $updateData, 'id = ?', [$id]);

        // Bildirim gonder
        $assignerName = Database::fetch("SELECT name FROM users WHERE id = ?", [$user['userId']]);
        NotificationController::create(
            (int) $input['assignedTo'],
            'Yeni Gorev Atandi',
            ($assignerName['name'] ?? 'Yonetici') . ' size bir gorev atadi: ' . $existing['title'],
            '/gorevler?taskId=' . $id,
            'info'
        );

        Response::success(null, 'Gorev atandi');
    }

    public function complete(array $user, int $id, array $input = []): void
    {
        $existing = Database::fetch(
            "SELECT * FROM tasks WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if (!$existing) {
            Response::error('Gorev bulunamadi', 404);
        }

        $isAdmin = (int) $user['role'] === 1;
        $isAssigned = (int) $existing['assigned_to'] === (int) $user['userId'];

        if (!$isAdmin && !$isAssigned) {
            Response::error('Yetkiniz yok', 403);
        }

        $result = $input['result'] ?? null;
        $type = $existing['type'];

        // Validate result by type
        if ($type === 'RENEWAL') {
            if (!in_array($result, ['RENEWED', 'NOT_RENEWED'])) {
                Response::error('Yenileme sonucu secimi zorunludur (RENEWED veya NOT_RENEWED)', 422);
            }
        } elseif ($type === 'OFFER') {
            if (!in_array($result, ['OFFER_APPROVED', 'OFFER_REJECTED'])) {
                Response::error('Teklif sonucu secimi zorunludur (OFFER_APPROVED veya OFFER_REJECTED)', 422);
            }
        } elseif ($type === 'FOLLOW_UP_CALL') {
            if (!in_array($result, ['CALLED', 'NOT_REACHED', 'NOT_AVAILABLE'])) {
                Response::error('Arama sonucu seçimi zorunludur', 422);
            }
        } elseif (in_array($type, ['CROSS_SELL', 'REFERENCE'])) {
            if (!in_array($result, ['DONE', 'FAILED'])) {
                Response::error('Sonuç seçimi zorunludur (DONE veya FAILED)', 422);
            }
        } else {
            $result = 'DONE';
        }

        // Görüşme notu: ayara göre zorunlu veya opsiyonel (varsayılan: kapalı)
        $resultNoteInput = trim($input['resultNote'] ?? '');
        $requireNoteSetting = Database::fetch("SELECT value FROM settings WHERE `key` = 'require_task_note'");
        $requireNote = $requireNoteSetting && $requireNoteSetting['value'] === '1';
        if ($requireNote && strlen($resultNoteInput) < 10) {
            Response::error('Görüşme notu zorunludur ve en az 10 karakter olmalıdır', 422);
        }

        $now = date('Y-m-d H:i:s');
        $resultNote = '';

        // Build note
        $resultLabels = [
            'RENEWED' => 'Yenilendi',
            'NOT_RENEWED' => 'Yenilenmedi',
            'OFFER_APPROVED' => 'Teklif onaylandi, police yapildi',
            'OFFER_REJECTED' => 'Teklif onaylanmadi',
            'DONE' => 'Satış Yapıldı',
            'FAILED' => 'Satış Yapılamadı',
            'CALLED' => 'Arandı - Ulaşıldı',
            'NOT_REACHED' => 'Ulaşılamadı - Ertelendi',
            'NOT_AVAILABLE' => 'Müsait Değil - Ertelendi',
        ];
        $resultNote = $resultLabels[$result] ?? $result;
        if (!empty($input['resultReason'])) {
            $resultNote .= ' - Sebep: ' . $input['resultReason'];
        }
        if ($resultNoteInput !== '') {
            $resultNote .= ' - Not: ' . $resultNoteInput;
        }

        // FOLLOW_UP_CALL: NOT_REACHED / NOT_AVAILABLE → görev kapanmaz, ertelenir
        if ($type === 'FOLLOW_UP_CALL' && in_array($result, ['NOT_REACHED', 'NOT_AVAILABLE'])) {
            $postponeDays = ($result === 'NOT_REACHED') ? 1 : 3;
            $newDeadline = $this->nextBusinessDay($now, $postponeDays);

            Database::update('tasks', [
                'deadline' => $newDeadline,
                'status' => 'IN_PROGRESS',
                'updated_at' => $now,
            ], 'id = ?', [$id]);

            Database::insert('task_logs', [
                'task_id' => $id,
                'action' => 'STATUS_CHANGED',
                'from_user_id' => $user['userId'],
                'note' => $resultNote . ' → ' . date('d.m.Y', strtotime($newDeadline)) . ' tarihine ertelendi',
                'created_at' => $now,
            ]);

            // Müşteri sayfasına erteleme notu düş
            if ($existing['customer_id']) {
                $callerName = Database::fetch("SELECT name FROM users WHERE id = ?", [$user['userId']]);
                $callNote = date('d.m.Y H:i') . ' - ' . ($callerName['name'] ?? 'Kullanıcı') . ': ';
                $callNote .= ($resultLabels[$result] ?? $result);
                $callNote .= ' → ' . date('d.m.Y', strtotime($newDeadline)) . ' tarihine ertelendi';
                if ($resultNoteInput !== '') {
                    $callNote .= ' - ' . $resultNoteInput;
                }

                $pdo = Database::getInstance();
                $stmt = $pdo->prepare("INSERT INTO customer_notes (customer_id, type, note, task_id, policy_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    (int) $existing['customer_id'],
                    'CALL',
                    $callNote,
                    $id,
                    $existing['policy_id'] ? (int) $existing['policy_id'] : null,
                    $user['userId'],
                    $now,
                ]);
            }

            Response::success(null, $resultNote . ' - ' . date('d.m.Y', strtotime($newDeadline)) . ' tarihine ertelendi');
            return;
        }

        // Update task with actual result columns
        Database::update('tasks', [
            'status' => 'COMPLETED',
            'result' => $result,
            'result_reason' => $input['resultReason'] ?? null,
            'result_note' => $resultNoteInput,
            'completed_at' => $now,
            'completed_by' => $user['userId'],
            'updated_at' => $now,
        ], 'id = ?', [$id]);

        // Log
        Database::insert('task_logs', [
            'task_id' => $id,
            'action' => 'COMPLETED',
            'from_user_id' => $user['userId'],
            'note' => $resultNote,
            'created_at' => $now,
        ]);

        // Adminlere bildirim (kullanici tamamladiysa)
        if ((int) $user['role'] !== 1) {
            $userName = Database::fetch("SELECT name FROM users WHERE id = ?", [$user['userId']]);
            $this->notifyAdmins(
                (int) $user['userId'],
                'Gorev Tamamlandi',
                ($userName['name'] ?? 'Kullanici') . ' gorevi tamamladi: ' . $existing['title'] . ' - ' . $resultNote,
                '/gorevler?taskId=' . $id
            );
        }

        // Tüm task tipleri → müşteri profiline aktivite notu düş
        if ($existing['customer_id']) {
            $actorName = Database::fetch("SELECT name FROM users WHERE id = ?", [$user['userId']]);
            $typeLabels = [
                'RENEWAL'        => 'Yenileme Görüşmesi',
                'OFFER'          => 'Teklif Görüşmesi',
                'FOLLOW_UP_CALL' => 'Takip Araması',
                'CROSS_SELL'     => 'Çapraz Satış Görüşmesi',
                'REFERENCE'      => 'Referans Görüşmesi',
                'OTHER'          => 'Görev',
            ];
            $typeLabel = $typeLabels[$type] ?? $type;

            $stageInfo = '';
            if ($type === 'FOLLOW_UP_CALL' && !empty($existing['offer_data'])) {
                $od = json_decode($existing['offer_data'], true);
                if (!empty($od['stageLabel'])) $stageInfo = ' (' . $od['stageLabel'] . ')';
            }

            $noteType = ($type === 'FOLLOW_UP_CALL') ? 'CALL' : 'TASK';
            $activityNote = date('d.m.Y H:i') . ' - ' . ($actorName['name'] ?? 'Kullanıcı') . ' tarafından ' . $typeLabel . ' tamamlandı' . $stageInfo . '. ';
            $activityNote .= 'Sonuç: ' . ($resultLabels[$result] ?? $result) . '. ';
            $activityNote .= 'Not: ' . $resultNoteInput;

            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("INSERT INTO customer_notes (customer_id, type, note, task_id, policy_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                (int) $existing['customer_id'],
                $noteType,
                $activityNote,
                $id,
                $existing['policy_id'] ? (int) $existing['policy_id'] : null,
                $user['userId'],
                $now,
            ]);
        }

        // NOT: Teklif onaylandiginda poliçe OLUSTURULMAZ — sadece sonuc kaydedilir.

        // --- ARAÇ TAKİBİ: FOLLOW_UP_CALL + CALLED durumunda ---
        if ($type === 'FOLLOW_UP_CALL' && $result === 'CALLED' && $existing['customer_id']) {
            $customerId = (int) $existing['customer_id'];
            $hasVehicleInput = $input['hasVehicle'] ?? null;       // YES / NO / null
            $registrationReceived = $input['registrationReceived'] ?? null; // YES / NO / null

            try {
                Database::beginTransaction();

                // Müşterinin plakalı poliçesi var mı kontrol et
                $hasPlatedPolicy = Database::fetch(
                    "SELECT COUNT(*) as cnt FROM policies WHERE customer_id = ? AND plate_no IS NOT NULL AND plate_no != '' AND deleted_at IS NULL",
                    [$customerId]
                );
                $hasPlate = $hasPlatedPolicy && (int) $hasPlatedPolicy['cnt'] > 0;

                // Plakalı poliçe varsa otomatik YES yap
                if ($hasPlate) {
                    Database::query(
                        "UPDATE customers SET has_vehicle = 'YES', has_vehicle_checked_at = ? WHERE id = ? AND has_vehicle != 'YES'",
                        [$now, $customerId]
                    );
                } elseif ($hasVehicleInput === 'NO') {
                    Database::query(
                        "UPDATE customers SET has_vehicle = 'NO', has_vehicle_checked_at = ? WHERE id = ?",
                        [$now, $customerId]
                    );
                } elseif ($hasVehicleInput === 'YES') {
                    Database::query(
                        "UPDATE customers SET has_vehicle = 'YES', has_vehicle_checked_at = ? WHERE id = ?",
                        [$now, $customerId]
                    );

                    // Ruhsat alınamadıysa 7 gün sonraya otomatik görev oluştur
                    if ($registrationReceived === 'NO') {
                        // Duplicate kontrol: aynı müşteri için açık "Ruhsat Takibi" görevi var mı?
                        $dupRuhsat = Database::fetch(
                            "SELECT id FROM tasks WHERE type = 'FOLLOW_UP_CALL' AND customer_id = ? AND title LIKE 'Ruhsat Takibi%' AND status IN ('PENDING','IN_PROGRESS') AND deleted_at IS NULL",
                            [$customerId]
                        );

                        if (!$dupRuhsat) {
                            $customerName = Database::fetch("SELECT name FROM customers WHERE id = ?", [$customerId]);
                            $ruhsatDeadline = $this->nextBusinessDay($now, 7);

                            $ruhsatTaskId = Database::insert('tasks', [
                                'type'        => 'FOLLOW_UP_CALL',
                                'title'       => 'Ruhsat Takibi — ' . ($customerName['name'] ?? ''),
                                'description' => 'Müşterinin aracı var, ruhsat bilgileri henüz alınamadı. Tekrar isteyin.',
                                'customer_id' => $customerId,
                                'policy_id'   => $existing['policy_id'] ?? null,
                                'assigned_to' => $existing['assigned_to'] ?? null,
                                'assigned_by' => !empty($existing['assigned_to']) ? $user['userId'] : null,
                                'created_by'  => $user['userId'],
                                'status'      => !empty($existing['assigned_to']) ? 'IN_PROGRESS' : 'PENDING',
                                'priority'    => 'MEDIUM',
                                'deadline'    => $ruhsatDeadline,
                                'created_at'  => $now,
                                'updated_at'  => $now,
                            ]);

                            Database::insert('task_logs', [
                                'task_id'      => $ruhsatTaskId,
                                'action'       => 'CREATED',
                                'from_user_id' => $user['userId'],
                                'note'         => 'Ruhsat takip görevi otomatik oluşturuldu (araç var, ruhsat alınamadı)',
                                'created_at'   => $now,
                            ]);

                            if (!empty($existing['assigned_to'])) {
                                NotificationController::create(
                                    (int) $existing['assigned_to'],
                                    'Ruhsat Takip Görevi',
                                    ($customerName['name'] ?? 'Müşteri') . ' için ruhsat takip görevi oluşturuldu — ' . date('d.m.Y', strtotime($ruhsatDeadline)),
                                    '/gorevler?taskId=' . $ruhsatTaskId,
                                    'info'
                                );
                            }
                        }
                    }
                }

                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                error_log('[VehicleTracking] error=' . $e->getMessage() . ' customer_id=' . $customerId . ' task_id=' . $id);
                // Araç takip hatası görev tamamlamayı engellemez — görev zaten COMPLETED oldu
            }
        }

        // İleri Vadede Düşünüyor: belirtilen tarihte yeni/mevcut gorev olustur/guncelle
        $isIleriVade = !empty($input['ileriVadeDate']) && (
            ($type === 'OFFER' && $result === 'OFFER_REJECTED') ||
            ($type === 'RENEWAL' && $result === 'NOT_RENEWED')
        );
        if ($isIleriVade) {
            $ileriVadeDate = $input['ileriVadeDate'];
            // Tarih gecmis olamaz
            if ($ileriVadeDate > date('Y-m-d')) {
                $ileriVadeDeadline = $ileriVadeDate . ' 23:59:00';
                $customerId = $existing['customer_id'];

                if ($type === 'OFFER') {
                    // offer_data'dan insuranceId al
                    $offerData = !empty($existing['offer_data']) ? json_decode($existing['offer_data'], true) : [];
                    $insuranceId = $offerData['insuranceId'] ?? null;

                    $dupQuery = "SELECT id FROM tasks
                                 WHERE type = 'OFFER' AND customer_id = ? AND status IN ('PENDING','IN_PROGRESS')
                                   AND deleted_at IS NULL AND id != ?";
                    $dupParams = [$customerId, $id];
                    if ($insuranceId) {
                        $dupQuery .= " AND JSON_UNQUOTE(JSON_EXTRACT(offer_data, '$.insuranceId')) = ?";
                        $dupParams[] = (string) $insuranceId;
                    }
                    $dupTask = Database::fetch($dupQuery, $dupParams);

                    if ($dupTask) {
                        Database::update('tasks', [
                            'deadline' => $ileriVadeDeadline,
                            'offer_expires_at' => $ileriVadeDate,
                            'updated_at' => $now,
                        ], 'id = ?', [$dupTask['id']]);

                        Database::insert('task_logs', [
                            'task_id' => $dupTask['id'],
                            'action' => 'STATUS_CHANGED',
                            'from_user_id' => $user['userId'],
                            'note' => 'İleri vade takibi: tarih ' . date('d.m.Y', strtotime($ileriVadeDate)) . ' olarak güncellendi',
                            'created_at' => $now,
                        ]);
                    } else {
                        $newOfferData = $existing['offer_data'] ?? null;
                        if ($newOfferData) {
                            $od = json_decode($newOfferData, true);
                            if (is_array($od)) {
                                $od['expiresAt'] = $ileriVadeDate;
                                $newOfferData = json_encode($od, JSON_UNESCAPED_UNICODE);
                            }
                        }
                        $newTaskId = Database::insert('tasks', [
                            'type' => 'OFFER',
                            'title' => $existing['title'],
                            'description' => $existing['description'] ?? null,
                            'customer_id' => $customerId,
                            'policy_id' => $existing['policy_id'] ?? null,
                            'offer_data' => $newOfferData,
                            'offer_expires_at' => $ileriVadeDate,
                            'assigned_to' => $existing['assigned_to'] ?? null,
                            'assigned_by' => !empty($existing['assigned_to']) ? $user['userId'] : null,
                            'created_by' => $user['userId'],
                            'status' => 'PENDING',
                            'priority' => $existing['priority'] ?? 'MEDIUM',
                            'deadline' => $ileriVadeDeadline,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        Database::insert('task_logs', [
                            'task_id' => $newTaskId,
                            'action' => 'CREATED',
                            'from_user_id' => $user['userId'],
                            'note' => 'İleri vade takibi: ' . date('d.m.Y', strtotime($ileriVadeDate)) . ' tarihli Teklif görevi otomatik oluşturuldu',
                            'created_at' => $now,
                        ]);
                        if (!empty($existing['assigned_to'])) {
                            NotificationController::create(
                                (int) $existing['assigned_to'],
                                'İleri Vade Takip Görevi',
                                $existing['title'] . ' için ' . date('d.m.Y', strtotime($ileriVadeDate)) . ' tarihli takip görevi oluşturuldu',
                                '/gorevler?taskId=' . $newTaskId
                            );
                        }
                    }
                } elseif ($type === 'RENEWAL') {
                    // Ayni police icin aktif RENEWAL gorevi var mi?
                    if ($existing['policy_id']) {
                        $dupTask = Database::fetch(
                            "SELECT id FROM tasks WHERE type = 'RENEWAL' AND policy_id = ?
                              AND status IN ('PENDING','IN_PROGRESS') AND deleted_at IS NULL AND id != ?",
                            [$existing['policy_id'], $id]
                        );
                    } else {
                        $dupTask = Database::fetch(
                            "SELECT id FROM tasks WHERE type = 'RENEWAL' AND customer_id = ? AND title = ?
                              AND status IN ('PENDING','IN_PROGRESS') AND deleted_at IS NULL AND id != ?",
                            [$customerId, $existing['title'], $id]
                        );
                    }

                    if ($dupTask) {
                        Database::update('tasks', [
                            'deadline' => $ileriVadeDeadline,
                            'offer_expires_at' => $ileriVadeDate,
                            'updated_at' => $now,
                        ], 'id = ?', [$dupTask['id']]);

                        Database::insert('task_logs', [
                            'task_id' => $dupTask['id'],
                            'action' => 'STATUS_CHANGED',
                            'from_user_id' => $user['userId'],
                            'note' => 'İleri vade takibi: tarih ' . date('d.m.Y', strtotime($ileriVadeDate)) . ' olarak güncellendi',
                            'created_at' => $now,
                        ]);
                    } else {
                        $newTaskId = Database::insert('tasks', [
                            'type' => 'RENEWAL',
                            'title' => $existing['title'],
                            'description' => $existing['description'] ?? null,
                            'customer_id' => $customerId,
                            'policy_id' => $existing['policy_id'] ?? null,
                            'offer_data' => $existing['offer_data'] ?? null,
                            'offer_expires_at' => $ileriVadeDate,
                            'assigned_to' => $existing['assigned_to'] ?? null,
                            'assigned_by' => !empty($existing['assigned_to']) ? $user['userId'] : null,
                            'created_by' => $user['userId'],
                            'status' => 'PENDING',
                            'priority' => $existing['priority'] ?? 'MEDIUM',
                            'deadline' => $ileriVadeDeadline,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        Database::insert('task_logs', [
                            'task_id' => $newTaskId,
                            'action' => 'CREATED',
                            'from_user_id' => $user['userId'],
                            'note' => 'İleri vade takibi: ' . date('d.m.Y', strtotime($ileriVadeDate)) . ' tarihli Yenileme görevi otomatik oluşturuldu',
                            'created_at' => $now,
                        ]);
                        if (!empty($existing['assigned_to'])) {
                            NotificationController::create(
                                (int) $existing['assigned_to'],
                                'İleri Vade Takip Görevi',
                                $existing['title'] . ' için ' . date('d.m.Y', strtotime($ileriVadeDate)) . ' tarihli takip görevi oluşturuldu',
                                '/gorevler?taskId=' . $newTaskId
                            );
                        }
                    }
                }
            }
        }

        // Olumsuz sonuç + "Seneye Hatirlat" secili ise gelecek yil ayni gorevi olustur
        if (in_array($result, ['NOT_RENEWED', 'OFFER_REJECTED', 'FAILED'], true) && !empty($input['remindNextYear'])) {
            // Baz tarih: gorevin deadline'i, OFFER ise offer_data.expiresAt tercih edilir
            $baseDate = $existing['deadline'] ?: $now;
            if ($type === 'OFFER' && !empty($existing['offer_data'])) {
                $od = json_decode($existing['offer_data'], true);
                if (!empty($od['expiresAt'])) {
                    $baseDate = $od['expiresAt'];
                }
            }
            $nextDeadline = date('Y-m-d H:i:s', strtotime($baseDate . ' +1 year'));

            // Duplicate guard: ayni tip + (police veya musteri+baslik) icin
            // gelecekte acik bir takip gorevi zaten varsa tekrar olusturma
            if ($existing['policy_id']) {
                $dup = Database::fetch(
                    "SELECT id FROM tasks
                     WHERE type = ? AND policy_id = ? AND status IN ('PENDING','IN_PROGRESS')
                       AND deleted_at IS NULL AND deadline >= ?",
                    [$type, $existing['policy_id'], $now]
                );
            } else {
                $dup = Database::fetch(
                    "SELECT id FROM tasks
                     WHERE type = ? AND customer_id <=> ? AND title = ?
                       AND status IN ('PENDING','IN_PROGRESS') AND deleted_at IS NULL AND deadline >= ?",
                    [$type, $existing['customer_id'], $existing['title'], $now]
                );
            }

            // offer_data icindeki expiresAt'i de +1 yil yapilan tarihe guncelle
            // (dashboard renewals OFFER tasklarda bu alani okuyor; aksi halde eski tarih gosterir)
            $newOfferData = $existing['offer_data'] ?? null;
            if ($type === 'OFFER' && !empty($newOfferData)) {
                $od = json_decode($newOfferData, true);
                if (is_array($od)) {
                    $od['expiresAt'] = date('Y-m-d', strtotime($nextDeadline));
                    $newOfferData = json_encode($od, JSON_UNESCAPED_UNICODE);
                }
            }

            if (!$dup) {
                // RENEWAL ve OFFER gorevlerinde offer_expires_at tarih filtreleri icin gerekli
                $newOfferExpiresAt = null;
                if (in_array($type, ['RENEWAL', 'OFFER'])) {
                    $newOfferExpiresAt = date('Y-m-d', strtotime($nextDeadline));
                }

                $newTaskId = Database::insert('tasks', [
                    'type' => $type,
                    'title' => $existing['title'],
                    'description' => $existing['description'] ?? null,
                    'customer_id' => $existing['customer_id'],
                    'policy_id' => $existing['policy_id'] ?? null,
                    'offer_data' => $newOfferData,
                    'offer_expires_at' => $newOfferExpiresAt,
                    'assigned_to' => $existing['assigned_to'] ?? null,
                    'assigned_by' => !empty($existing['assigned_to']) ? $user['userId'] : null,
                    'created_by' => $user['userId'],
                    'status' => 'PENDING',
                    'priority' => $existing['priority'] ?? 'MEDIUM',
                    'deadline' => $nextDeadline,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                Database::insert('task_logs', [
                    'task_id' => $newTaskId,
                    'action' => 'CREATED',
                    'from_user_id' => $user['userId'],
                    'note' => 'Gelecek yil takip gorevi otomatik olusturuldu (' . $resultNote . ')',
                    'created_at' => $now,
                ]);

                // Atanan kisi varsa bildir
                if (!empty($existing['assigned_to'])) {
                    NotificationController::create(
                        (int) $existing['assigned_to'],
                        'Gelecek Yil Takip Gorevi',
                        $existing['title'] . ' - ' . date('d.m.Y', strtotime($nextDeadline)) . ' tarihine takip gorevi olusturuldu',
                        '/gorevler?taskId=' . $newTaskId,
                        'info'
                    );
                }
            }
        }

        Response::success(null, $resultNote);
    }

    public function destroy(array $user, int $id): void
    {
        Permission::require($user, 'tasks.manage');
        Database::softDelete('tasks', $id);
        Response::success(null, 'Gorev silindi');
    }

    public function stats(array $user, array $query = []): void
    {
        $isAdmin = (int) $user['role'] === 1;

        $where = ["t.deleted_at IS NULL"];
        $params = [];

        if (!$isAdmin) {
            $where[] = "t.assigned_to = ?";
            $params[] = $user['userId'];
        }

        $assignedToFilter = $query['assignedTo'] ?? $query['assigned_to'] ?? null;
        if (!empty($assignedToFilter)) {
            $where[] = "t.assigned_to = ?";
            $params[] = $assignedToFilter;
        }

        // is_renewable=0 olan sigorta turlerinin sadece RENEWAL gorevlerini gizle
        $where[] = "(t.type != 'RENEWAL' OR EXISTS (SELECT 1 FROM policies pp INNER JOIN insurance_types it ON it.id = pp.insurance_type_id AND it.is_renewable = 1 WHERE pp.id = t.policy_id))";

        // Gorev tipine gore dogru tarih alani
        $dateExpr = "CASE
            WHEN t.type = 'RENEWAL' THEN p.expires_at
            WHEN t.type IN ('OFFER', 'CROSS_SELL') THEN COALESCE(t.offer_expires_at, t.deadline)
            ELSE t.deadline
        END";

        // Geciken sorgusu icin tarih filtresi olmayan base WHERE
        $whereBaseSql = implode(' AND ', $where);
        $baseParams = $params;

        if (!empty($query['dateFrom'])) {
            $where[] = "($dateExpr) >= ?";
            $params[] = $query['dateFrom'];
        }
        if (!empty($query['dateTo'])) {
            $where[] = "($dateExpr) <= ?";
            $params[] = $query['dateTo'];
        }

        $whereSql = implode(' AND ', $where);

        $statusCounts = Database::fetch(
            "SELECT
                COUNT(CASE WHEN t.status != 'CANCELLED' THEN 1 END) as total,
                COUNT(CASE WHEN t.status = 'PENDING' THEN 1 END) as pending,
                COUNT(CASE WHEN t.status = 'IN_PROGRESS' THEN 1 END) as in_progress,
                COUNT(CASE WHEN t.status = 'COMPLETED' THEN 1 END) as completed,
                COUNT(CASE WHEN t.status = 'EXPIRED' THEN 1 END) as expired,
                COUNT(CASE WHEN t.status = 'CANCELLED' THEN 1 END) as cancelled
             FROM tasks t
             LEFT JOIN policies p ON t.policy_id = p.id
             WHERE $whereSql",
            $params
        );

        $typeCounts = Database::fetch(
            "SELECT
                COUNT(CASE WHEN t.type = 'RENEWAL'      AND t.status != 'CANCELLED' THEN 1 END) as renewal,
                COUNT(CASE WHEN t.type = 'OFFER'        AND t.status != 'CANCELLED' THEN 1 END) as offer,
                COUNT(CASE WHEN t.type = 'CROSS_SELL'   AND t.status != 'CANCELLED' THEN 1 END) as cross_sell,
                COUNT(CASE WHEN t.type = 'REFERENCE'    AND t.status != 'CANCELLED' THEN 1 END) as reference_count,
                COUNT(CASE WHEN t.type = 'FOLLOW_UP_CALL' AND t.status != 'CANCELLED' THEN 1 END) as follow_up_call,
                COUNT(CASE WHEN t.type = 'FOLLOW_UP_CALL' AND t.status = 'PENDING'   THEN 1 END) as follow_up_call_pending,
                COUNT(CASE WHEN t.type = 'OTHER'        AND t.status != 'CANCELLED' THEN 1 END) as other
             FROM tasks t
             LEFT JOIN policies p ON t.policy_id = p.id
             WHERE $whereSql",
            $params
        );

        // Overdue: tamamlanmamis/iptal edilmemis ve etkili bitis tarihi gecmis olanlar.
        // Tum zamanlardan birikimli — tarih filtresi uygulanmaz.
        $overdue = Database::fetch(
            "SELECT COUNT(*) as count
             FROM tasks t
             LEFT JOIN policies p ON t.policy_id = p.id
             WHERE $whereBaseSql
               AND t.status NOT IN ('COMPLETED','CANCELLED')
               AND (
                 CASE
                     WHEN t.type = 'RENEWAL' AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                     WHEN t.type = 'RENEWAL' AND p.expires_at IS NOT NULL THEN DATEDIFF(p.expires_at, CURDATE())
                     WHEN t.type NOT IN ('OFFER','CROSS_SELL') AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                     WHEN t.offer_expires_at IS NOT NULL THEN DATEDIFF(t.offer_expires_at, CURDATE())
                     WHEN t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                 END
               ) < 0",
            $baseParams
        );

        $resultCounts = Database::fetch(
            "SELECT
                COUNT(CASE WHEN t.result = 'RENEWED' THEN 1 END) as renewed,
                COUNT(CASE WHEN t.result = 'NOT_RENEWED' THEN 1 END) as not_renewed,
                COUNT(CASE WHEN t.result = 'OFFER_APPROVED' THEN 1 END) as offer_approved,
                COUNT(CASE WHEN t.result = 'OFFER_REJECTED' THEN 1 END) as offer_rejected
             FROM tasks t
             LEFT JOIN policies p ON t.policy_id = p.id
             WHERE $whereSql",
            $params
        );

        $data = [
            'total' => (int) $statusCounts['total'],
            'byStatus' => [
                'pending' => (int) $statusCounts['pending'],
                'inProgress' => (int) $statusCounts['in_progress'],
                'completed' => (int) $statusCounts['completed'],
                'expired' => (int) $statusCounts['expired'],
                'cancelled' => (int) $statusCounts['cancelled'],
            ],
            'byType' => [
                'renewal' => (int) $typeCounts['renewal'],
                'offer' => (int) $typeCounts['offer'],
                'crossSell' => (int) $typeCounts['cross_sell'],
                'reference' => (int) $typeCounts['reference_count'],
                'followUpCall' => (int) $typeCounts['follow_up_call'],
                'followUpCallPending' => (int) $typeCounts['follow_up_call_pending'],
                'other' => (int) $typeCounts['other'],
            ],
            'byResult' => [
                'renewed' => (int) $resultCounts['renewed'],
                'notRenewed' => (int) $resultCounts['not_renewed'],
                'offerApproved' => (int) $resultCounts['offer_approved'],
                'offerRejected' => (int) $resultCounts['offer_rejected'],
            ],
            'overdue' => (int) $overdue['count'],
        ];

        if ($isAdmin) {
            $userStats = Database::fetchAll(
                "SELECT t.assigned_to, u.name,
                    COUNT(*) as total,
                    COUNT(CASE WHEN t.status = 'PENDING' THEN 1 END) as pending,
                    COUNT(CASE WHEN t.status = 'IN_PROGRESS' THEN 1 END) as in_progress,
                    COUNT(CASE WHEN t.status = 'COMPLETED' THEN 1 END) as completed,
                    COUNT(CASE WHEN t.status IN ('PENDING', 'IN_PROGRESS') AND (
                        CASE
                            WHEN t.type = 'RENEWAL' AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                            WHEN t.type = 'RENEWAL' AND p.expires_at IS NOT NULL THEN DATEDIFF(p.expires_at, CURDATE())
                            WHEN t.type NOT IN ('OFFER','CROSS_SELL') AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                            WHEN t.offer_expires_at IS NOT NULL THEN DATEDIFF(t.offer_expires_at, CURDATE())
                            WHEN t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                        END
                    ) < 0 THEN 1 END) as overdue
                 FROM tasks t
                 LEFT JOIN policies p ON t.policy_id = p.id
                 LEFT JOIN users u ON t.assigned_to = u.id AND u.is_active = 1 AND u.deleted_at IS NULL
                 WHERE $whereSql AND t.assigned_to IS NOT NULL AND u.id IS NOT NULL AND t.type != 'FOLLOW_UP_CALL'
                 GROUP BY t.assigned_to, u.name",
                $params
            );

            $data['perUser'] = array_map(function ($s) {
                return [
                    'userId' => (int) $s['assigned_to'],
                    'fullName' => $s['name'],
                    'total' => (int) $s['total'],
                    'pending' => (int) $s['pending'],
                    'inProgress' => (int) $s['in_progress'],
                    'completed' => (int) $s['completed'],
                    'overdue' => (int) $s['overdue'],
                ];
            }, $userStats);
        }

        Response::success($data);
    }

    /**
     * İş günü hesaplama: Hafta sonu ve tatil günlerini atla.
     * $date: başlangıç tarih string (Y-m-d veya datetime)
     * $addDays: eklenecek iş günü sayısı (0 = sadece mevcut tarihi iş gününe çek)
     */
    private function nextBusinessDay(string $date, int $addDays = 0): string
    {
        $ts = strtotime($date);
        if ($ts === false) $ts = time();

        // Resmi tatiller (ay-gün formatında, her yıl tekrar eden)
        $holidays = $this->getHolidays(date('Y', $ts));

        // Önce mevcut tarihi iş gününe çek
        while ($this->isNonBusinessDay($ts, $holidays)) {
            $ts = strtotime('+1 day', $ts);
        }

        // Ardından iş günü ekle
        for ($i = 0; $i < $addDays; $i++) {
            $ts = strtotime('+1 day', $ts);
            // Yıl değişebilir
            $holidays = $this->getHolidays(date('Y', $ts));
            while ($this->isNonBusinessDay($ts, $holidays)) {
                $ts = strtotime('+1 day', $ts);
                $holidays = $this->getHolidays(date('Y', $ts));
            }
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function isNonBusinessDay(int $timestamp, array $holidays): bool
    {
        $dow = (int) date('N', $timestamp); // 6=Cumartesi, 7=Pazar
        if ($dow >= 6) return true;
        $md = date('m-d', $timestamp);
        return in_array($md, $holidays, true);
    }

    /**
     * Türkiye resmi tatil günleri (sabit tarihli).
     * Ramazan/Kurban bayramı gibi değişkenler settings'den okunabilir.
     */
    private function getHolidays(string $year): array
    {
        // Sabit resmi tatiller
        $fixed = [
            '01-01', // Yılbaşı
            '04-23', // Ulusal Egemenlik ve Çocuk Bayramı
            '05-01', // Emek ve Dayanışma Günü
            '05-19', // Atatürk'ü Anma, Gençlik ve Spor Bayramı
            '07-15', // Demokrasi ve Milli Birlik Günü
            '08-30', // Zafer Bayramı
            '10-29', // Cumhuriyet Bayramı
        ];

        // Settings'den ek tatiller (opsiyonel, admin tanımlayabilir)
        try {
            $extra = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'custom_holidays'");
            if ($extra) {
                $decoded = json_decode($extra['value'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $h) {
                        // Tam tarih (YYYY-MM-DD) veya tekrarlayan (MM-DD) formatı
                        if (preg_match('/^\d{4}-(\d{2}-\d{2})$/', $h, $m)) {
                            if (substr($h, 0, 4) === $year) $fixed[] = $m[1];
                        } elseif (preg_match('/^\d{2}-\d{2}$/', $h)) {
                            $fixed[] = $h;
                        }
                    }
                }
            }
        } catch (\Exception $e) {}

        return $fixed;
    }

    /**
     * Takip araması dönem tanımları.
     * $templateOverrides: settings'den gelen başlık/içerik şablonları (her biri key, titleTemplate, descriptionTemplate içerir)
     */
    private function getFollowUpStages(array $templateOverrides = []): array
    {
        $stages = [
            [
                'key' => '2ND_MONTH',
                'defaultDays' => 60,
                'maxDays' => 90,   // 60. günden sonra en fazla 30 gün tolerans
                'label' => '2. Ay',
                'priority' => 'MEDIUM',
                'deadlineDays' => 7,
                'titleTemplate' => '2. Ay Memnuniyet Araması - {customerName}',
                'descriptionTemplate' => "Müşterinin poliçeyi kullanıp kullanmadığını, mobil uygulamayı indirip indirmediğini sorun. Varsa bir şikayet veya ihtiyacını dinleyin.",
            ],
            [
                'key' => '6TH_MONTH',
                'defaultDays' => 180,
                'maxDays' => 210,  // 180. günden sonra en fazla 30 gün tolerans
                'label' => '6. Ay',
                'priority' => 'MEDIUM',
                'deadlineDays' => 7,
                'titleTemplate' => '6. Ay Yarı Yıl Kontrolü - {customerName}',
                'descriptionTemplate' => "6 aylık süreci değerlendirin. Anlaşmalı kurumlardan memnun mu, bir hasar/provizyon sıkıntısı yaşadı mı kontrol edin.",
            ],
            [
                'key' => '10TH_MONTH',
                'defaultDays' => 300,
                'maxDays' => 330,  // 300. günden sonra en fazla 30 gün tolerans
                'label' => '10. Ay',
                'priority' => 'HIGH',
                'deadlineDays' => 5,
                'titleTemplate' => '10. Ay Yenileme Öncesi Isıtma - {customerName}',
                'descriptionTemplate' => "2 ay sonra yenileme var. Güncel durumu yoklayın, hasar/prim oranını kontrol edin ve müşteriyi yeni dönem fiyat artışlarına psikolojik olarak hazırlayın.",
            ],
        ];

        // Ayarlardan gelen şablon override'larını uygula
        foreach ($stages as &$stage) {
            foreach ($templateOverrides as $override) {
                if (($override['key'] ?? '') === $stage['key']) {
                    if (!empty($override['titleTemplate'])) {
                        $stage['titleTemplate'] = $override['titleTemplate'];
                    }
                    if (!empty($override['descriptionTemplate'])) {
                        $stage['descriptionTemplate'] = $override['descriptionTemplate'];
                    }
                    break;
                }
            }
        }
        unset($stage);

        return $stages;
    }

    private function fillTemplate(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }
        return $template;
    }

    public function cron(array $query): void
    {
        if (empty($query['secret']) || $query['secret'] !== 'sigorta_cron_2024') {
            Response::error('Yetkisiz erisim', 401);
        }

        $result = $this->runCronJobs();

        Response::success([
            'created'               => $result['created'],
            'followUpCreated'       => $result['followUpCreated'],
            'expired'               => $result['expired'],
            'cancelledNonRenewable' => $result['cancelled'],
        ], 'Cron gorevi tamamlandi');
    }

    /**
     * Cron is mantigi — HTTP Response icermiyor, dogrudan cagirilabilir.
     * DashboardController::renewals() tarafindan pseudo-cron olarak kullanilir.
     */
    public function runCronJobs(): array
    {
        // MySQL advisory lock — aynı anda sadece 1 cron çalışabilir (race condition önlemi)
        $pdo = Database::getInstance();
        $lock = $pdo->query("SELECT GET_LOCK('sigorta_cron', 0) as locked")->fetch();
        if (!$lock || !$lock['locked']) {
            return ['created' => 0, 'followUpCreated' => 0, 'expired' => 0, 'cancelled' => 0];
        }

        try {
            return $this->executeAllCronJobs();
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('sigorta_cron')");
        }
    }

    private function executeAllCronJobs(): array
    {
        $now = date('Y-m-d H:i:s');
        $created = 0;
        $expired = 0;

        // --- Renewal tasks ---
        $renewalPolicies = Database::fetchAll(
            "SELECT p.id, p.policy_no, p.expires_at, p.customer_id, cu.name as customer_name, i.renewal_days
             FROM policies p
             INNER JOIN insurance_types i ON p.insurance_type_id = i.id AND i.is_renewable = 1
             LEFT JOIN customers cu ON p.customer_id = cu.id
             WHERE p.is_cancelled = '0' AND p.deleted_at IS NULL
               AND p.no_renewal_reminder = '0'
               AND p.id = (SELECT MAX(p2.id) FROM policies p2 WHERE p2.policy_no = p.policy_no AND p2.deleted_at IS NULL)
               AND DATEDIFF(p.expires_at, CURDATE()) <= i.renewal_days
               AND DATEDIFF(p.expires_at, CURDATE()) >= -1
               AND NOT EXISTS (
                   SELECT 1 FROM tasks t
                   INNER JOIN policies tp ON t.policy_id = tp.id
                   WHERE t.type = 'RENEWAL' AND tp.policy_no = p.policy_no AND t.deleted_at IS NULL
                     AND t.status IN ('PENDING', 'IN_PROGRESS', 'EXPIRED', 'COMPLETED')
               )"
        );

        foreach ($renewalPolicies as $policy) {
            $taskId = Database::insert('tasks', [
                'type' => 'RENEWAL',
                'title' => 'Yenileme: ' . ($policy['customer_name'] ?? '') . ' - ' . $policy['policy_no'],
                'policy_id' => (int) $policy['id'],
                'customer_id' => $policy['customer_id'] ? (int) $policy['customer_id'] : null,
                'assigned_to' => null,
                'status' => 'PENDING',
                'priority' => 'HIGH',
                'deadline' => $this->parseDeadline($policy['expires_at']),
                'offer_expires_at' => $policy['expires_at'], // RENEWAL ile OFFER ayni sutunu kullanir
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Database::insert('task_logs', [
                'task_id' => $taskId,
                'action' => 'CREATED',
                'note' => 'Otomatik yenileme gorevi olusturuldu',
                'created_at' => $now,
            ]);

            $created++;
        }

        // --- Expire overdue tasks (offer_expires_at veya deadline gecmis olanlar) ---
        $overdueTasks = Database::fetchAll(
            "SELECT t.id FROM tasks t
             WHERE t.status IN ('PENDING','IN_PROGRESS')
               AND t.deleted_at IS NULL
               AND (
                 (t.offer_expires_at IS NOT NULL AND DATEDIFF(t.offer_expires_at, CURDATE()) < 0)
                 OR (t.offer_expires_at IS NULL AND t.deadline IS NOT NULL AND DATEDIFF(DATE(t.deadline), CURDATE()) < 0)
               )"
        );

        foreach ($overdueTasks as $task) {
            Database::update('tasks', [
                'status' => 'EXPIRED',
                'updated_at' => $now,
            ], 'id = ?', [$task['id']]);

            Database::insert('task_logs', [
                'task_id' => (int) $task['id'],
                'action' => 'EXPIRED',
                'note' => 'Gorev suresi doldu',
                'created_at' => $now,
            ]);

            $expired++;
        }

        // --- Cancel tasks for non-renewable insurance types ---
        $nonRenewableTasks = Database::fetchAll(
            "SELECT t.id FROM tasks t
             INNER JOIN policies p ON t.policy_id = p.id
             INNER JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE t.type = 'RENEWAL'
               AND t.status IN ('PENDING', 'IN_PROGRESS')
               AND t.deleted_at IS NULL
               AND i.is_renewable = 0"
        );

        $cancelled = 0;
        foreach ($nonRenewableTasks as $task) {
            Database::update('tasks', [
                'status' => 'CANCELLED',
                'updated_at' => $now,
            ], 'id = ?', [$task['id']]);

            Database::insert('task_logs', [
                'task_id' => (int) $task['id'],
                'action' => 'CANCELLED',
                'note' => 'Yenileme takibi kapatildigi icin iptal edildi',
                'created_at' => $now,
            ]);

            $cancelled++;
        }

        // --- Follow-up call tasks (Aylık Görev Havuzu) ---
        // Mantık: Her çalışmada o ayın görevlerini oluşturur.
        //   - Pencere: DATE_ADD(starts_at, INTERVAL stageDays DAY) BETWEEN ay_basi AND ay_sonu
        //   - Geçmişe dönük oluşturma YOK: ay başı daima cari ay 1'i olduğu için
        //     önceki aylara ait aktivasyon tarihleri pencere dışında kalır.
        //   - scheduled_for: starts_at + stageDays (aktivasyon/görünme tarihi)
        //   - deadline     : starts_at + stageDays + deadlineDays (iş günü ayarlamalı)
        $followUpCreated = 0;
        $followUpConfig = Database::fetch("SELECT `value` FROM settings WHERE `key` = 'follow_up_call_config'");
        if ($followUpConfig) {
            $config = json_decode($followUpConfig['value'], true);
            if (is_array($config) && !empty($config['rules'])) {
                $stageTemplates = $config['stageTemplates'] ?? [];
                $stages = $this->getFollowUpStages($stageTemplates);

                // Cari ayın sınırları
                $monthStart = date('Y-m-01'); // Örn: 2026-07-01
                $monthEnd   = date('Y-m-t');  // Örn: 2026-07-31

                foreach ($config['rules'] as $rule) {
                    if (empty($rule['enabled']) || empty($rule['branchGroup'])) continue;
                    $branchGroup = $rule['branchGroup'];
                    $ruleStages = $rule['stages'] ?? null;

                    foreach ($stages as $stage) {
                        $stageKey = $stage['key'];

                        // Dönem aktif mi kontrol et (ayarlardan)
                        $stageEnabled = true;
                        $stageDays = $stage['defaultDays'];
                        if (is_array($ruleStages)) {
                            $found = false;
                            foreach ($ruleStages as $rs) {
                                if (($rs['key'] ?? '') === $stageKey) {
                                    $stageEnabled = !empty($rs['enabled']);
                                    $stageDays = (int) ($rs['days'] ?? $stage['defaultDays']);
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) $stageEnabled = false;
                        }
                        if (!$stageEnabled) continue;

                        // Aktivasyon tarihi (starts_at + stageDays) cari ay içinde olan
                        // aktif poliçeleri bul. NOT EXISTS → mükerrer görev oluşmaz.
                        $followUpPolicies = Database::fetchAll(
                            "SELECT p.id, p.policy_no, p.customer_id, p.starts_at, cu.name as customer_name,
                                    i.name as insurance_name, i.branch_group, cu.phone as customer_phone
                             FROM policies p
                             INNER JOIN insurance_types i ON p.insurance_type_id = i.id
                             LEFT JOIN customers cu ON p.customer_id = cu.id
                             WHERE p.is_cancelled = '0' AND p.deleted_at IS NULL
                               AND i.branch_group = ? AND i.is_active = 1 AND i.follow_up_call_eligible = 1
                               AND p.id = (SELECT MAX(p2.id) FROM policies p2 WHERE p2.policy_no = p.policy_no AND p2.deleted_at IS NULL)
                               AND DATE_ADD(DATE(p.starts_at), INTERVAL ? DAY) BETWEEN ? AND ?
                               AND NOT EXISTS (
                                   SELECT 1 FROM tasks t
                                   WHERE t.type = 'FOLLOW_UP_CALL' AND t.policy_id = p.id
                                     AND t.deleted_at IS NULL
                                     AND JSON_UNQUOTE(JSON_EXTRACT(t.offer_data, '$.stage')) = ?
                               )",
                            [$branchGroup, $stageDays, $monthStart, $monthEnd, $stageKey]
                        );

                        foreach ($followUpPolicies as $policy) {
                            $vars = [
                                'branchGroup'   => $branchGroup,
                                'customerName'  => $policy['customer_name'] ?? '',
                                'policyNo'      => $policy['policy_no'],
                                'startsAt'      => $policy['starts_at'] ? date('d.m.Y', strtotime($policy['starts_at'])) : '',
                                'insuranceName' => $policy['insurance_name'] ?? '',
                            ];

                            $title       = $this->fillTemplate($stage['titleTemplate'], $vars);
                            $description = $this->fillTemplate($stage['descriptionTemplate'], $vars);

                            // Aktivasyon tarihi: poliçe başlangıcı + stageDays
                            // Hafta sonu / tatile denk gelirse bir sonraki iş gününe kaydır
                            $scheduledForRaw = date('Y-m-d', strtotime($policy['starts_at'] . ' +' . $stageDays . ' days'));
                            $scheduledFor    = date('Y-m-d', strtotime($this->nextBusinessDay($scheduledForRaw . ' 00:00:00')));

                            // Deadline: aktivasyon tarihi + deadlineDays (iş günü ayarlamalı)
                            $naturalDeadline = date('Y-m-d', strtotime($policy['starts_at'] . ' +' . ($stageDays + $stage['deadlineDays']) . ' days'));
                            $deadline = $this->nextBusinessDay($naturalDeadline . ' 23:59:59');

                            $taskId = Database::insert('tasks', [
                                'type'          => 'FOLLOW_UP_CALL',
                                'title'         => $title,
                                'description'   => $description,
                                'policy_id'     => (int) $policy['id'],
                                'customer_id'   => $policy['customer_id'] ? (int) $policy['customer_id'] : null,
                                'assigned_to'   => null,
                                'status'        => 'PENDING',
                                'priority'      => $stage['priority'],
                                'scheduled_for' => $scheduledFor,
                                'deadline'      => $deadline,
                                'offer_data'    => json_encode([
                                    'stage'       => $stageKey,
                                    'stageLabel'  => $stage['label'],
                                    'branchGroup' => $branchGroup,
                                    'triggerDays' => $stageDays,
                                ]),
                                'created_at'    => $now,
                                'updated_at'    => $now,
                            ]);

                            Database::insert('task_logs', [
                                'task_id'    => $taskId,
                                'action'     => 'CREATED',
                                'note'       => 'Otomatik ' . $stage['label'] . ' takip araması (' . $branchGroup . ', ' . $stageDays . '. gün) - Aktivasyon: ' . date('d.m.Y', strtotime($scheduledFor)),
                                'created_at' => $now,
                            ]);

                            $followUpCreated++;
                        }
                    }
                }
            }
        }

        // Son calisma tarihini kaydet (pseudo-cron icin)
        $today = date('Y-m-d');
        $existing = Database::fetch("SELECT id FROM settings WHERE `key` = 'last_cron_run'");
        if ($existing) {
            Database::query("UPDATE `settings` SET `value` = ? WHERE `key` = 'last_cron_run'", [$today]);
        } else {
            Database::query("INSERT INTO `settings` (`key`, `value`) VALUES ('last_cron_run', ?)", [$today]);
        }

        return [
            'created'       => $created,
            'followUpCreated' => $followUpCreated,
            'expired'       => $expired,
            'cancelled'     => $cancelled,
        ];
    }

    public function export(array $user, array $query): void
    {
        Permission::require($user, 'tasks.export');
        $where = ["t.deleted_at IS NULL"];
        $params = [];

        if ((int) $user['role'] !== 1) {
            $where[] = "t.assigned_to = ?";
            $params[] = $user['userId'];
        }

        if (!empty($query['status'])) {
            $where[] = "t.status = ?";
            $params[] = $query['status'];
        }

        if (!empty($query['type'])) {
            $where[] = "t.type = ?";
            $params[] = $query['type'];
        }

        $assignedToFilter = $query['assignedTo'] ?? $query['assigned_to'] ?? null;
        if (!empty($assignedToFilter)) {
            $where[] = "t.assigned_to = ?";
            $params[] = $assignedToFilter;
        }

        if (!empty($query['customerId'])) {
            $where[] = "t.customer_id = ?";
            $params[] = (int) $query['customerId'];
        }

        if (!empty($query['search'])) {
            $search = '%' . $query['search'] . '%';
            $searchNoSpace = '%' . str_replace(' ', '', $query['search']) . '%';
            $where[] = "(t.title LIKE ? OR t.description LIKE ? OR cu.name LIKE ? OR cu.identity_no LIKE ? OR REPLACE(p.plate_no, ' ', '') LIKE ? OR p.plate_no LIKE ? OR p.policy_no LIKE ?)";
            $params = array_merge($params, [$search, $search, $search, $search, $searchNoSpace, $search, $search]);
        }

        // Gorev tipine gore dogru tarih alani
        $dateExpr = "CASE
            WHEN t.type = 'RENEWAL' THEN p.expires_at
            WHEN t.type IN ('OFFER', 'CROSS_SELL') THEN COALESCE(t.offer_expires_at, t.deadline)
            ELSE t.deadline
        END";

        if (!empty($query['dateFrom'])) {
            $where[] = "($dateExpr) >= ?";
            $params[] = $query['dateFrom'];
        }
        if (!empty($query['dateTo'])) {
            $where[] = "($dateExpr) <= ?";
            $params[] = $query['dateTo'];
        }

        // is_renewable=0 olan sigorta turlerinin sadece RENEWAL gorevlerini gizle
        $where[] = "(t.type != 'RENEWAL' OR EXISTS (SELECT 1 FROM policies pp INNER JOIN insurance_types it ON it.id = pp.insurance_type_id AND it.is_renewable = 1 WHERE pp.id = t.policy_id))";

        // CANCELLED gorevleri Excel'e dahil etme
        $where[] = "t.status != 'CANCELLED'";

        $whereSql = implode(' AND ', $where);

        $daysExpr = "CASE
            WHEN t.status IN ('COMPLETED','CANCELLED') THEN NULL
            WHEN t.type = 'RENEWAL' AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
            WHEN t.type = 'RENEWAL' AND p.expires_at IS NOT NULL THEN DATEDIFF(p.expires_at, CURDATE())
            WHEN t.type NOT IN ('OFFER','CROSS_SELL') AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
            WHEN t.offer_expires_at IS NOT NULL THEN DATEDIFF(t.offer_expires_at, CURDATE())
            WHEN t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
            ELSE NULL
        END";

        $rows = Database::fetchAll(
            "SELECT t.*, u.name as assigned_to_name, cu.name as customer_name, cu.identity_no as customer_identity,
                    p.policy_no, p.plate_no, p.registration_no, p.expires_at as policy_expires_at,
                    i.name as insurance_name, co.name as company_name,
                    $daysExpr AS days_remaining
             FROM tasks t
             LEFT JOIN users u ON t.assigned_to = u.id AND u.is_active = 1 AND u.deleted_at IS NULL
             LEFT JOIN customers cu ON t.customer_id = cu.id
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN companies co ON p.company_id = co.id
             WHERE $whereSql
             ORDER BY FIELD(t.status, 'IN_PROGRESS', 'PENDING', 'EXPIRED', 'COMPLETED', 'CANCELLED'),
                      ($daysExpr) ASC, cu.name ASC, t.id ASC",
            $params
        );

        $statusLabels = ['PENDING' => 'Bekleyen', 'IN_PROGRESS' => 'Devam Eden', 'COMPLETED' => 'Tamamlanan', 'EXPIRED' => 'Süresi Geçen', 'CANCELLED' => 'İptal'];
        $typeLabels = ['RENEWAL' => 'Yenileme', 'OFFER' => 'Teklif', 'CROSS_SELL' => 'Çapraz Satış', 'REFERENCE' => 'Referans', 'FOLLOW_UP_CALL' => 'Takip Araması', 'OTHER' => 'Diğer'];
        $resultLabels = ['RENEWED' => 'Yenilendi', 'NOT_RENEWED' => 'Yenilenmedi', 'OFFER_APPROVED' => 'Teklif Onaylandı', 'OFFER_REJECTED' => 'Teklif Reddedildi', 'DONE' => 'Olumlu', 'FAILED' => 'Olumsuz', 'CALLED' => 'Ulaşıldı', 'NOT_REACHED' => 'Ulaşılamadı', 'NOT_AVAILABLE' => 'Müsait Değil'];

        $exportRows = [];
        foreach ($rows as $r) {
            $offerData = !empty($r['offer_data']) ? json_decode($r['offer_data'], true) : null;

            // Bitiş tarihi
            $expiresAt = $r['policy_expires_at'] ?? ($offerData['expiresAt'] ?? ($r['offer_expires_at'] ?? ''));

            // TC / VKN
            $identity = $r['customer_identity'] ?? '';

            // Müşteri (50 karakterden uzunsa kısalt)
            $customerName = $r['customer_name'] ?? ($offerData['customerName'] ?? '');
            if (mb_strlen($customerName) > 50) {
                $customerName = mb_substr($customerName, 0, 47) . '...';
            }

            // Poliçe No
            $policyNo = $r['policy_no'] ?? '';

            // Sigorta Şirketi (50 karakterden uzunsa kısalt)
            $companyName = $r['company_name'] ?? ($offerData['companyName'] ?? '');
            if (mb_strlen($companyName) > 50) {
                $companyName = mb_substr($companyName, 0, 47) . '...';
            }

            // Poliçe Türü
            $insuranceName = $r['insurance_name'] ?? ($offerData['insuranceName'] ?? '');

            // Plaka
            $plateNo = $r['plate_no'] ?? ($offerData['plateNo'] ?? '');

            // Ruhsat
            $registrationNo = $r['registration_no'] ?? ($offerData['registrationNo'] ?? '');

            // Temsilci
            $assignedName = $r['assigned_to_name'] ?? '';

            // Görev Tarihi
            $deadlineRaw = (in_array($r['type'], ['OFFER', 'CROSS_SELL']) && !empty($r['offer_expires_at']))
                ? $r['offer_expires_at']
                : $r['deadline'];
            $taskDate = $deadlineRaw ?: '';

            // Durum
            $status = $statusLabels[$r['status']] ?? $r['status'];

            // Açıklama (görev türü + sonuç + not)
            if ($r['type'] === 'FOLLOW_UP_CALL' && !empty($r['offer_data'])) {
                $offerDataArr = json_decode($r['offer_data'], true);
                $stageLabel = $offerDataArr['stageLabel'] ?? null;
                $desc = $stageLabel ? $stageLabel . ' Takip Araması' : ($typeLabels[$r['type']] ?? $r['type']);
            } else {
                $desc = $typeLabels[$r['type']] ?? $r['type'];
            }
            if (!empty($r['result'])) {
                $desc .= ' - ' . ($resultLabels[$r['result']] ?? $r['result']);
            }
            if (!empty($r['result_note'])) {
                $desc .= ' - ' . $r['result_note'];
            }

            $exportRows[] = [
                'expires_at' => $expiresAt,
                'identity' => $identity,
                'customer_name' => $customerName,
                'policy_no' => $policyNo,
                'company_name' => $companyName,
                'insurance_name' => $insuranceName,
                'plate_no' => $plateNo,
                'registration_no' => $registrationNo,
                'assigned_to' => $assignedName,
                'task_date' => $taskDate,
                'status' => $status,
                'description' => $desc,
            ];
        }

        $columns = [
            ['key' => 'expires_at', 'label' => 'Bitiş Tarihi', 'type' => Response::COL_DATE],
            ['key' => 'identity', 'label' => 'TC Kimlik / VKN', 'type' => Response::COL_IDENTIFIER],
            ['key' => 'customer_name', 'label' => 'Müşteri Ad Soyad'],
            ['key' => 'policy_no', 'label' => 'Poliçe No', 'type' => Response::COL_IDENTIFIER],
            ['key' => 'company_name', 'label' => 'Sigorta Şirketi'],
            ['key' => 'insurance_name', 'label' => 'Poliçe Türü'],
            ['key' => 'plate_no', 'label' => 'Plaka No', 'type' => Response::COL_IDENTIFIER],
            ['key' => 'registration_no', 'label' => 'Ruhsat Seri No', 'type' => Response::COL_IDENTIFIER],
            ['key' => 'assigned_to', 'label' => 'Temsilci'],
            ['key' => 'task_date', 'label' => 'Görev Tarihi', 'type' => Response::COL_DATE],
            ['key' => 'status', 'label' => 'Durum'],
            ['key' => 'description', 'label' => 'Açıklama'],
        ];

        Response::xlsx($exportRows, $columns, 'gorevler_' . date('Y-m-d') . '.xlsx');
    }

    private function formatTask(array $t): array
    {
        $offerData = !empty($t['offer_data']) ? json_decode($t['offer_data'], true) : null;
        // SQL tarafindan gelen days_remaining (NULL olabilir)
        $daysRemaining = isset($t['days_remaining']) && $t['days_remaining'] !== null
            ? (int) $t['days_remaining']
            : null;

        return [
            'id' => (int) $t['id'],
            'type' => $t['type'],
            'title' => $t['title'],
            'description' => $t['description'],
            'policyId' => $t['policy_id'] ? (int) $t['policy_id'] : null,
            'customerId' => $t['customer_id'] ? (int) $t['customer_id'] : null,
            'customerName' => $t['customer_name'] ?? null,
            'assignedTo' => $t['assigned_to'] ? (int) $t['assigned_to'] : null,
            'assignedToName' => $t['assigned_to_name'] ?? null,
            'createdBy' => isset($t['created_by']) && $t['created_by'] ? (int) $t['created_by'] : null,
            'status' => $t['status'],
            'priority' => $t['priority'],
            'deadline' => (in_array($t['type'], ['OFFER', 'CROSS_SELL']) && !empty($t['offer_expires_at']))
                ? $t['offer_expires_at']
                : $t['deadline'],
            'daysRemaining' => $daysRemaining,
            'result' => $t['result'] ?? null,
            'resultReason' => $t['result_reason'] ?? null,
            'resultNote' => $t['result_note'] ?? null,
            'completedAt' => $t['completed_at'] ?? null,
            'completedBy' => isset($t['completed_by']) && $t['completed_by'] ? (int) $t['completed_by'] : null,
            'createdAt' => $t['created_at'],
            'policyNo' => $t['policy_no'] ?? null,
            'insuranceName' => $t['insurance_name'] ?? null,
            'insuranceColor' => $t['insurance_color'] ?? null,
            'companyName' => $t['company_name'] ?? null,
            'customerIdentity' => $t['customer_identity'] ?? null,
            'plateNo' => $t['plate_no'] ?? null,
            'registrationNo' => $t['registration_no'] ?? null,
            'policyExpiresAt' => $t['policy_expires_at'] ?? null,
            'productionType' => $t['production_type'] ?? null,
            'scheduledFor' => $t['scheduled_for'] ?? null,
            'offerData' => $offerData,
        ];
    }

    /**
     * Akıllı toplu kapatma - Geciken görevleri analiz eder
     * POST /api/tasks/bulk-smart-close
     * body: { preview: true } → önizleme, { preview: false } → uygula
     */
    public function bulkSmartClose(array $user, array $input): void
    {
        if ((int) $user['role'] !== 1) {
            Response::error('Yetkiniz yok', 403);
        }

        $preview = $input['preview'] ?? true;
        $now = date('Y-m-d H:i:s');

        // Geciken görevleri al: PENDING/IN_PROGRESS/EXPIRED ve efektif tarihi geçmiş
        $expiredTasks = Database::fetchAll(
            "SELECT t.id, t.type, t.policy_id, t.customer_id, t.title,
                    t.deadline, t.offer_expires_at,
                    p.policy_no, p.insurance_type_id, p.expires_at as policy_expires,
                    p.plate_no, p.uavt_code, p.starts_at as policy_starts,
                    i.branch_group,
                    cu.name as customer_name
             FROM tasks t
             LEFT JOIN policies p ON t.policy_id = p.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN customers cu ON t.customer_id = cu.id
             WHERE t.deleted_at IS NULL
               AND t.status NOT IN ('COMPLETED', 'CANCELLED')
               AND (
                 CASE
                     WHEN t.type = 'RENEWAL' AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                     WHEN t.type = 'RENEWAL' AND p.expires_at IS NOT NULL THEN DATEDIFF(p.expires_at, CURDATE())
                     WHEN t.type NOT IN ('OFFER','CROSS_SELL') AND t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                     WHEN t.offer_expires_at IS NOT NULL THEN DATEDIFF(t.offer_expires_at, CURDATE())
                     WHEN t.deadline IS NOT NULL THEN DATEDIFF(DATE(t.deadline), CURDATE())
                 END
               ) < 0"
        );

        $results = [
            'toComplete' => [],
            'toCancel' => [],
        ];

        foreach ($expiredTasks as $task) {
            $action = null;
            $reason = '';

            if ($task['type'] === 'RENEWAL') {
                // Poliçe yenilendi mi? Üç katmanlı kontrol:
                // 1) Klasik: aynı poliçe no, daha yeni kayıt
                // 2) TRAFİK/KASKO: aynı plaka + aynı müşteri + bitiş tarihine yakın yeni başlangıç
                // 3) KONUT/DASK: aynı UAVT kodu + aynı müşteri + bitiş tarihine yakın yeni başlangıç
                if ($task['policy_id']) {
                    $extraClause = '';
                    $params = [$task['policy_id']];

                    if (
                        !empty($task['plate_no']) &&
                        in_array($task['branch_group'], ['TRAFİK', 'KASKO'], true)
                    ) {
                        $extraClause = "OR (p2.plate_no = ? AND p2.plate_no IS NOT NULL
                                            AND p2.customer_id = p1.customer_id
                                            AND p2.starts_at BETWEEN DATE_SUB(p1.expires_at, INTERVAL 90 DAY)
                                                                  AND DATE_ADD(p1.expires_at, INTERVAL 30 DAY))";
                        $params[] = $task['plate_no'];
                    } elseif (
                        !empty($task['uavt_code']) &&
                        in_array($task['branch_group'], ['KONUT', 'DASK'], true)
                    ) {
                        $extraClause = "OR (p2.uavt_code = ? AND p2.uavt_code IS NOT NULL
                                            AND p2.customer_id = p1.customer_id
                                            AND p2.starts_at BETWEEN DATE_SUB(p1.expires_at, INTERVAL 90 DAY)
                                                                  AND DATE_ADD(p1.expires_at, INTERVAL 30 DAY))";
                        $params[] = $task['uavt_code'];
                    }

                    $renewed = Database::fetch(
                        "SELECT p2.id FROM policies p2
                         INNER JOIN policies p1 ON p1.id = ?
                         WHERE p2.deleted_at IS NULL AND p2.is_cancelled = 0
                           AND p2.id != p1.id
                           AND (
                             -- Klasik: aynı poliçe no, daha yeni kayıt
                             (p2.policy_no = p1.policy_no AND p2.issued_at > p1.issued_at)
                             -- TRAFİK/KASKO: aynı plaka | KONUT/DASK: aynı UAVT + tarih penceresi
                             $extraClause
                           )
                         LIMIT 1",
                        $params
                    );
                    if ($renewed) {
                        $action = 'COMPLETED';
                        $reason = 'Poliçe yenilenmiş (aynı poliçe no, plaka veya UAVT ile yeni poliçe mevcut)';
                    } else {
                        $action = 'CANCELLED';
                        $reason = 'Poliçe yenilenmemiş, süre geçmiş';
                    }
                } else {
                    $action = 'CANCELLED';
                    $reason = 'Bağlı poliçe bulunamadı';
                }

            } elseif ($task['type'] === 'OFFER') {
                // Müşteriye aynı branşta aktif poliçe yapılmış mı?
                if ($task['customer_id'] && $task['insurance_type_id']) {
                    $policyMade = Database::fetch(
                        "SELECT id FROM policies
                         WHERE customer_id = ?
                           AND insurance_type_id = ?
                           AND deleted_at IS NULL
                           AND is_cancelled = 0
                           AND issued_at >= COALESCE(?, ?)
                         LIMIT 1",
                        [
                            $task['customer_id'],
                            $task['insurance_type_id'],
                            $task['offer_expires_at'] ? date('Y-m-d', strtotime($task['offer_expires_at'] . ' -60 days')) : null,
                            $task['deadline'] ? date('Y-m-d', strtotime($task['deadline'] . ' -60 days')) : '2020-01-01',
                        ]
                    );
                    if ($policyMade) {
                        $action = 'COMPLETED';
                        $reason = 'Müşteriye bu branşta poliçe yapılmış';
                    } else {
                        $action = 'CANCELLED';
                        $reason = 'Teklif dönüşmemiş, süre geçmiş';
                    }
                } elseif ($task['customer_id']) {
                    // insurance_type_id yoksa sadece müşteriye herhangi poliçe yapılmış mı bak
                    $anyPolicy = Database::fetch(
                        "SELECT id FROM policies
                         WHERE customer_id = ?
                           AND deleted_at IS NULL
                           AND is_cancelled = 0
                           AND issued_at >= COALESCE(?, '2020-01-01')
                         LIMIT 1",
                        [
                            $task['customer_id'],
                            $task['deadline'] ? date('Y-m-d', strtotime($task['deadline'] . ' -60 days')) : null,
                        ]
                    );
                    if ($anyPolicy) {
                        $action = 'COMPLETED';
                        $reason = 'Müşteriye poliçe yapılmış';
                    } else {
                        $action = 'CANCELLED';
                        $reason = 'Teklif dönüşmemiş, süre geçmiş';
                    }
                } else {
                    $action = 'CANCELLED';
                    $reason = 'Müşteri bilgisi bulunamadı';
                }

            } else {
                // OTHER, CROSS_SELL, REFERENCE, FOLLOW_UP_CALL vb.
                $action = 'CANCELLED';
                $reason = 'Süre geçmiş görev';
            }

            $item = [
                'id' => (int) $task['id'],
                'type' => $task['type'],
                'title' => $task['title'],
                'customerName' => $task['customer_name'],
                'policyNo' => $task['policy_no'],
                'deadline' => $task['deadline'] ?? $task['offer_expires_at'],
                'action' => $action,
                'reason' => $reason,
            ];

            if ($action === 'COMPLETED') {
                $results['toComplete'][] = $item;
            } else {
                $results['toCancel'][] = $item;
            }
        }

        // Sadece önizleme istenmişse sonuçları dön
        if ($preview) {
            Response::success([
                'totalExpired' => count($expiredTasks),
                'toComplete' => count($results['toComplete']),
                'toCancel' => count($results['toCancel']),
                'details' => $results,
            ], 'Önizleme hazır');
            return;
        }

        // Uygula
        $completed = 0;
        $cancelled = 0;

        foreach ($results['toComplete'] as $item) {
            $result = $item['type'] === 'RENEWAL' ? 'RENEWED' : 'OFFER_APPROVED';
            Database::update('tasks', [
                'status' => 'COMPLETED',
                'result' => $result,
                'result_note' => 'Akıllı toplu kapatma: ' . $item['reason'],
                'completed_at' => $now,
                'completed_by' => $user['userId'],
                'updated_at' => $now,
            ], 'id = ?', [$item['id']]);

            Database::insert('task_logs', [
                'task_id' => $item['id'],
                'action' => 'COMPLETED',
                'from_user_id' => $user['userId'],
                'note' => 'Akıllı toplu kapatma: ' . $item['reason'],
                'created_at' => $now,
            ]);
            $completed++;
        }

        foreach ($results['toCancel'] as $item) {
            Database::update('tasks', [
                'status' => 'CANCELLED',
                'result_note' => 'Akıllı toplu kapatma: ' . $item['reason'],
                'updated_at' => $now,
            ], 'id = ?', [$item['id']]);

            Database::insert('task_logs', [
                'task_id' => $item['id'],
                'action' => 'CANCELLED',
                'from_user_id' => $user['userId'],
                'note' => 'Akıllı toplu kapatma: ' . $item['reason'],
                'created_at' => $now,
            ]);
            $cancelled++;
        }

        Response::success([
            'completed' => $completed,
            'cancelled' => $cancelled,
            'total' => $completed + $cancelled,
        ], $completed . ' görev tamamlandı, ' . $cancelled . ' görev iptal edildi');
    }
}
