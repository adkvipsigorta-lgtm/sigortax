<?php

class ReminderController
{
    public function index(array $user, array $query): void
    {
        $where = ["r.deleted_at IS NULL", "r.is_dismissed = 0"];
        $params = [];

        // User's own reminders or admin sees all
        if ((int) $user['role'] !== 1) {
            $where[] = "r.user_id = ?";
            $params[] = $user['userId'];
        }

        if (!empty($query['type'])) {
            $where[] = "r.type = ?";
            $params[] = $query['type'];
        }

        if (!empty($query['date'])) {
            $where[] = "r.remind_date = ?";
            $params[] = $query['date'];
        }

        if (!empty($query['upcoming'])) {
            $where[] = "r.remind_date >= CURDATE()";
        }

        if (!empty($query['overdue'])) {
            $where[] = "r.remind_date < CURDATE()";
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT r.*, u.name as user_name, cu.name as customer_name
                FROM reminders r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN customers cu ON r.customer_id = cu.id
                WHERE $whereSql
                ORDER BY r.remind_date ASC, r.remind_time ASC";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        // Counts
        $countParams = [];
        $countWhere = "r.deleted_at IS NULL AND r.is_dismissed = 0";
        if ((int) $user['role'] !== 1) {
            $countWhere .= " AND r.user_id = ?";
            $countParams[] = $user['userId'];
        }

        $counts = Database::fetch(
            "SELECT
                COUNT(CASE WHEN r.remind_date = CURDATE() THEN 1 END) as today,
                COUNT(CASE WHEN r.remind_date > CURDATE() THEN 1 END) as upcoming,
                COUNT(CASE WHEN r.remind_date < CURDATE() THEN 1 END) as overdue
             FROM reminders r WHERE $countWhere",
            $countParams
        );

        Response::json([
            'success' => true,
            'data' => $result['data'],
            'pagination' => $result['pagination'],
            'counts' => [
                'today' => (int) $counts['today'],
                'upcoming' => (int) $counts['upcoming'],
                'overdue' => (int) $counts['overdue'],
            ],
        ]);
    }

    public function show(array $user, int $id): void
    {
        $r = Database::fetch(
            "SELECT r.*, u.name as user_name, cu.name as customer_name
             FROM reminders r
             LEFT JOIN users u ON r.user_id = u.id
             LEFT JOIN customers cu ON r.customer_id = cu.id
             WHERE r.id = ? AND r.deleted_at IS NULL",
            [$id]
        );

        if (!$r) {
            Response::error('Hatirlatici bulunamadi', 404);
        }

        Response::success($this->format($r));
    }

    public function store(array $user, array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, [
            'title' => 'required|min:2',
            'type' => 'required|in:policy_renewal,follow_up,meeting,custom',
            'remindDate' => 'required|date',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $id = Database::insert('reminders', [
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'type' => $input['type'],
            'remind_date' => $input['remindDate'],
            'remind_time' => $input['remindTime'] ?? null,
            'user_id' => $user['userId'],
            'customer_id' => $input['customerId'] ?? null,
            'policy_id' => $input['policyId'] ?? null,
            'is_dismissed' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Hatirlatici olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        $existing = Database::fetch("SELECT id FROM reminders WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Hatirlatici bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        $fields = ['title', 'description', 'type'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) {
                $data[$f] = $input[$f];
            }
        }
        if (array_key_exists('remindDate', $input)) $data['remind_date'] = $input['remindDate'];
        if (array_key_exists('remindTime', $input)) $data['remind_time'] = $input['remindTime'];
        if (array_key_exists('customerId', $input)) $data['customer_id'] = $input['customerId'];
        if (array_key_exists('policyId', $input)) $data['policy_id'] = $input['policyId'];

        Database::update('reminders', $data, 'id = ?', [$id]);
        Response::success(null, 'Hatirlatici guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        Database::softDelete('reminders', $id);
        Response::success(null, 'Hatirlatici silindi');
    }

    // Dismiss a reminder
    public function dismiss(array $user, int $id): void
    {
        Database::update('reminders', [
            'is_dismissed' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ? AND deleted_at IS NULL', [$id]);

        Response::success(null, 'Hatirlatici kapatildi');
    }

    // Snooze - postpone by X days
    public function snooze(array $user, int $id, array $input): void
    {
        $days = (int) ($input['days'] ?? 1);

        $existing = Database::fetch("SELECT remind_date FROM reminders WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Hatirlatici bulunamadi', 404);
        }

        $newDate = date('Y-m-d', strtotime($existing['remind_date'] . " + $days days"));

        Database::update('reminders', [
            'remind_date' => $newDate,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        Response::success(['newDate' => $newDate], "Hatirlatici $days gun ertelendi");
    }

    private function format(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'description' => $r['description'],
            'type' => $r['type'],
            'remindDate' => $r['remind_date'],
            'remindTime' => $r['remind_time'],
            'isDismissed' => (bool) $r['is_dismissed'],
            'userId' => $r['user_id'] ? (int) $r['user_id'] : null,
            'userName' => $r['user_name'] ?? null,
            'customerId' => $r['customer_id'] ? (int) $r['customer_id'] : null,
            'customerName' => $r['customer_name'] ?? null,
            'policyId' => $r['policy_id'] ? (int) $r['policy_id'] : null,
            'createdAt' => $r['created_at'],
        ];
    }
}
