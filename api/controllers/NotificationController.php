<?php

class NotificationController
{
    public function index(array $user, array $query): void
    {
        $where = ["n.user_id = ?"];
        $params = [$user['userId']];

        if (isset($query['unread']) && $query['unread'] === '1') {
            $where[] = "n.is_read = 0";
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(50, max(1, (int) ($query['limit'] ?? 20)));

        $sql = "SELECT n.* FROM notifications n WHERE $whereSql ORDER BY n.created_at DESC";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        // Unread count
        $unreadCount = Database::fetch(
            "SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0",
            [$user['userId']]
        );

        Response::json([
            'success' => true,
            'data' => $result['data'],
            'pagination' => $result['pagination'],
            'unreadCount' => (int) $unreadCount['cnt'],
        ]);
    }

    public function show(array $user, int $id): void
    {
        $n = Database::fetch(
            "SELECT * FROM notifications WHERE id = ? AND user_id = ?",
            [$id, $user['userId']]
        );

        if (!$n) {
            Response::error('Bildirim bulunamadi', 404);
        }

        // Mark as read when viewed
        if (!(bool) $n['is_read']) {
            Database::update('notifications', ['is_read' => 1], 'id = ?', [$id]);
        }

        Response::success($this->format($n));
    }

    // Create notification (internal/admin use)
    public function store(array $user, array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, [
            'title' => 'required|min:2',
            'message' => 'required|min:2',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $targetUserId = $input['userId'] ?? $user['userId'];

        // If sending to all users
        if (!empty($input['broadcast'])) {
            AuthMiddleware::requireAdmin($user);
            $users = Database::fetchAll("SELECT id FROM users WHERE deleted_at IS NULL AND is_active = 1");
            foreach ($users as $u) {
                self::create($u['id'], $input['title'], $input['message'], $input['data'] ?? null, $input['type'] ?? 'info');
            }
            Response::success(null, count($users) . ' kullaniciya bildirim gonderildi', 201);
            return;
        }

        $id = self::create($targetUserId, $input['title'], $input['message'], $input['data'] ?? null, $input['type'] ?? 'info');
        Response::success(['id' => $id], 'Bildirim olusturuldu', 201);
    }

    public static function create(int $userId, string $title, string $message, ?string $data = null, string $type = 'info'): int
    {
        $id = Database::insert('notifications', [
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'type' => $type,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Socket.IO ile anlik bildirim — 'type' ismi event type ile cakisirdi, 'notifType' kullaniyoruz
        SocketEmitter::notifyUser($userId, [
            'id' => $id,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'notifType' => $type,
        ]);

        return $id;
    }

    // Mark all as read
    public function update(array $user, int $id, array $input): void
    {
        // If id = 0 => mark all as read
        if ($id === 0 || !empty($input['markAllRead'])) {
            Database::query(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
                [$user['userId']]
            );
            Response::success(null, 'Tum bildirimler okundu olarak isaretlendi');
            return;
        }

        // Mark single as read
        Database::update('notifications', [
            'is_read' => 1,
        ], 'id = ? AND user_id = ?', [$id, $user['userId']]);

        Response::success(null, 'Bildirim okundu olarak isaretlendi');
    }

    public function destroy(array $user, int $id): void
    {
        // Notifications table has no deleted_at, so hard delete
        Database::query("DELETE FROM notifications WHERE id = ? AND user_id = ?", [$id, $user['userId']]);
        Response::success(null, 'Bildirim silindi');
    }

    private function format(array $n): array
    {
        return [
            'id' => (int) $n['id'],
            'title' => $n['title'],
            'message' => $n['message'],
            'data' => $n['data'] ?? null,
            'type' => $n['type'] ?? 'info',
            'unread' => !(bool) $n['is_read'],
            'date' => $n['created_at'],
        ];
    }
}
