<?php

class TaskNoteController
{
    /**
     * GET /api/task-notes?taskId=123  - Belirli gorev notlarini listele
     * GET /api/task-notes?counts=1    - Tum gorevlerin not sayilarini don
     */
    public function index(array $user, array $query): void
    {
        // Not sayilari modu
        if (!empty($query['counts'])) {
            $rows = Database::fetchAll(
                "SELECT task_id, COUNT(*) as cnt FROM task_notes GROUP BY task_id HAVING cnt > 0"
            );
            $map = [];
            foreach ($rows as $r) {
                $map[(int) $r['task_id']] = (int) $r['cnt'];
            }
            Response::success($map);
            return;
        }

        $taskId = (int) ($query['taskId'] ?? 0);
        if (!$taskId) {
            Response::error('taskId zorunludur', 422);
            return;
        }

        $notes = Database::fetchAll(
            "SELECT n.id, n.task_id, n.note, n.created_at, u.name as created_by_name
             FROM task_notes n
             LEFT JOIN users u ON n.created_by = u.id
             WHERE n.task_id = ?
             ORDER BY n.created_at DESC",
            [$taskId]
        );

        $data = array_map(function ($n) {
            return [
                'id' => (int) $n['id'],
                'taskId' => (int) $n['task_id'],
                'note' => $n['note'],
                'createdByName' => $n['created_by_name'],
                'createdAt' => $n['created_at'],
            ];
        }, $notes);

        Response::success($data);
    }

    /**
     * POST /api/task-notes - Not ekle
     */
    public function store(array $user, array $input): void
    {
        $taskId = (int) ($input['taskId'] ?? 0);
        $note = trim($input['note'] ?? '');

        if (!$taskId || $note === '') {
            Response::error('taskId ve note zorunludur', 422);
            return;
        }

        $task = Database::fetch("SELECT id FROM tasks WHERE id = ? AND deleted_at IS NULL", [$taskId]);
        if (!$task) {
            Response::error('Gorev bulunamadi', 404);
            return;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("INSERT INTO task_notes (task_id, note, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$taskId, $note, $user['userId']]);
        $id = (int) $pdo->lastInsertId();

        $userName = Database::fetch("SELECT name FROM users WHERE id = ?", [$user['userId']]);

        Response::success([
            'id' => $id,
            'taskId' => $taskId,
            'note' => $note,
            'createdByName' => $userName['name'] ?? '',
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * DELETE /api/task-notes/{id} - Not sil
     */
    public function destroy(array $user, int $id): void
    {
        $note = Database::fetch("SELECT id, task_id FROM task_notes WHERE id = ?", [$id]);
        if (!$note) {
            Response::error('Not bulunamadi', 404);
            return;
        }

        $pdo = Database::getInstance();
        $pdo->prepare("DELETE FROM task_notes WHERE id = ?")->execute([$id]);

        Response::success(['id' => $id, 'taskId' => (int) $note['task_id']]);
    }
}
