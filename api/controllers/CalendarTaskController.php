<?php

class CalendarTaskController
{
    public function index(array $user, array $query): void
    {
        $where = ["t.deleted_at IS NULL"];
        $params = [];

        // User's own tasks or admin sees all
        if ((int) $user['role'] !== 1) {
            $where[] = "t.user_id = ?";
            $params[] = $user['userId'];
        }

        if (!empty($query['date'])) {
            $where[] = "t.date = ?";
            $params[] = $query['date'];
        }

        if (!empty($query['month'])) {
            $where[] = "DATE_FORMAT(t.date, '%Y-%m') = ?";
            $params[] = $query['month'];
        }

        if (!empty($query['status'])) {
            $where[] = "t.status = ?";
            $params[] = $query['status'];
        }

        if (!empty($query['priority'])) {
            $where[] = "t.priority = ?";
            $params[] = $query['priority'];
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT t.*, u.name as user_name
                FROM calendar_tasks t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE $whereSql
                ORDER BY t.date ASC, t.time ASC";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $task = Database::fetch(
            "SELECT t.*, u.name as user_name
             FROM calendar_tasks t
             LEFT JOIN users u ON t.user_id = u.id
             WHERE t.id = ? AND t.deleted_at IS NULL",
            [$id]
        );

        if (!$task) {
            Response::error('Gorev bulunamadi', 404);
        }

        Response::success($this->format($task));
    }

    public function store(array $user, array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, [
            'title' => 'required|min:2',
            'date' => 'required|date',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $id = Database::insert('calendar_tasks', [
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'date' => $input['date'],
            'time' => $input['time'] ?? null,
            'priority' => $input['priority'] ?? 'medium',
            'status' => 'pending',
            'user_id' => $user['userId'],
            'customer_id' => $input['customerId'] ?? null,
            'policy_id' => $input['policyId'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Gorev olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        $existing = Database::fetch("SELECT id FROM calendar_tasks WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Gorev bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        $fields = ['title', 'description', 'date', 'time', 'priority', 'status'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) {
                $data[$f] = $input[$f];
            }
        }
        if (array_key_exists('customerId', $input)) $data['customer_id'] = $input['customerId'];
        if (array_key_exists('policyId', $input)) $data['policy_id'] = $input['policyId'];

        Database::update('calendar_tasks', $data, 'id = ?', [$id]);
        Response::success(null, 'Gorev guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        Database::softDelete('calendar_tasks', $id);
        Response::success(null, 'Gorev silindi');
    }

    private function format(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'title' => $t['title'],
            'description' => $t['description'],
            'date' => $t['date'],
            'time' => $t['time'],
            'priority' => $t['priority'],
            'status' => $t['status'],
            'userId' => $t['user_id'] ? (int) $t['user_id'] : null,
            'userName' => $t['user_name'] ?? null,
            'customerId' => $t['customer_id'] ? (int) $t['customer_id'] : null,
            'policyId' => $t['policy_id'] ? (int) $t['policy_id'] : null,
            'createdAt' => $t['created_at'],
        ];
    }
}
