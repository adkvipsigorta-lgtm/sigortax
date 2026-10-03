<?php

class ActivityLogController
{
    public function index(array $user, array $query): void
    {
        $where = ["a.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['customerId'])) {
            $where[] = "a.customer_id = ?";
            $params[] = (int) $query['customerId'];
        }

        if (!empty($query['type'])) {
            $where[] = "a.type = ?";
            $params[] = $query['type'];
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT a.*, u.name as user_name
                FROM activity_log a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE $whereSql
                ORDER BY a.date DESC, a.created_at DESC";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $log = Database::fetch(
            "SELECT a.*, u.name as user_name
             FROM activity_log a
             LEFT JOIN users u ON a.user_id = u.id
             WHERE a.id = ? AND a.deleted_at IS NULL",
            [$id]
        );

        if (!$log) {
            Response::error('Aktivite bulunamadi', 404);
        }

        Response::success($this->format($log));
    }

    public function store(array $user, array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, [
            'customerId' => 'required|numeric',
            'type' => 'required|in:call,email,meeting,note,sms,policy,offer',
            'title' => 'required|min:2',
            'date' => 'required|date',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $id = Database::insert('activity_log', [
            'customer_id' => $input['customerId'],
            'type' => $input['type'],
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'date' => $input['date'],
            'user_id' => $user['userId'],
            'policy_id' => $input['policyId'] ?? null,
            'policy_number' => $input['policyNo'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Aktivite eklendi', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        $existing = Database::fetch("SELECT id FROM activity_log WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Aktivite bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        $fields = ['type', 'title', 'description', 'date'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) {
                $data[$f] = $input[$f];
            }
        }
        if (array_key_exists('customerId', $input)) $data['customer_id'] = $input['customerId'];
        if (array_key_exists('policyId', $input)) $data['policy_id'] = $input['policyId'];
        if (array_key_exists('policyNo', $input)) $data['policy_number'] = $input['policyNo'];

        Database::update('activity_log', $data, 'id = ?', [$id]);
        Response::success(null, 'Aktivite guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        Database::softDelete('activity_log', $id);
        Response::success(null, 'Aktivite silindi');
    }

    private function format(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'customerId' => (int) $a['customer_id'],
            'type' => $a['type'],
            'title' => $a['title'],
            'description' => $a['description'],
            'date' => $a['date'],
            'userId' => $a['user_id'] ? (int) $a['user_id'] : null,
            'userName' => $a['user_name'] ?? null,
            'policyId' => $a['policy_id'] ? (int) $a['policy_id'] : null,
            'policyNo' => $a['policy_number'],
            'createdAt' => $a['created_at'],
        ];
    }
}
