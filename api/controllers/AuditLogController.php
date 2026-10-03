<?php

class AuditLogController
{
    public function index(array $user, array $query): void
    {
        AuthMiddleware::requireAdmin($user);

        $where = ["1=1"];
        $params = [];

        if (!empty($query['action'])) {
            $where[] = "a.action = ?";
            $params[] = $query['action'];
        }

        if (!empty($query['entity'])) {
            $where[] = "a.entity = ?";
            $params[] = $query['entity'];
        }

        if (!empty($query['userId'])) {
            $where[] = "a.user_id = ?";
            $params[] = (int) $query['userId'];
        }

        if (!empty($query['startsAt'])) {
            $where[] = "a.created_at >= ?";
            $params[] = $query['startsAt'] . ' 00:00:00';
        }

        if (!empty($query['endDate'])) {
            $where[] = "a.created_at <= ?";
            $params[] = $query['endDate'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT a.*, u.name as user_name
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE $whereSql
                ORDER BY a.created_at DESC";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    public function store(array $user, array $input): void
    {
        // Internal use - log an action
        $id = self::log(
            $input['action'] ?? 'update',
            $input['entity'] ?? 'unknown',
            $input['entityId'] ?? null,
            $input['oldData'] ?? null,
            $input['newData'] ?? null,
            $user['userId']
        );

        Response::success(['id' => $id], 'Log kaydedildi', 201);
    }

    public static function log(string $action, string $entity, ?int $entityId, ?string $oldData, ?string $newData, int $userId): int
    {
        return Database::insert('audit_logs', [
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'old_data' => $oldData,
            'new_data' => $newData,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function format(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'action' => $a['action'],
            'entity' => $a['entity'],
            'entityId' => $a['entity_id'] ? (int) $a['entity_id'] : null,
            'oldData' => $a['old_data'],
            'newData' => $a['new_data'],
            'userId' => $a['user_id'] ? (int) $a['user_id'] : null,
            'userName' => $a['user_name'] ?? null,
            'ipAddress' => $a['ip_address'],
            'createdAt' => $a['created_at'],
        ];
    }
}
