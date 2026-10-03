<?php

class TrashController
{
    private const TABLES = [
        'policies' => ['table' => 'policies', 'name_field' => 'policy_no'],
        'customers' => ['table' => 'customers', 'name_field' => 'name'],
        'insurances' => ['table' => 'insurance_types', 'name_field' => 'name'],
        'companies' => ['table' => 'companies', 'name_field' => 'name'],
        'branches' => ['table' => 'branches', 'name_field' => 'name'],
        'users' => ['table' => 'users', 'name_field' => 'name'],
    ];

    public function index(array $user, array $query): void
    {
        AuthMiddleware::requireAdmin($user);

        $typeFilter = $query['type'] ?? null;
        $items = [];

        foreach (self::TABLES as $type => $config) {
            if ($typeFilter && $typeFilter !== $type) continue;

            $rows = Database::fetchAll(
                "SELECT id, {$config['name_field']} as name, deleted_at
                 FROM {$config['table']}
                 WHERE deleted_at IS NOT NULL
                 ORDER BY deleted_at DESC"
            );

            foreach ($rows as $row) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => $type,
                    'name' => $row['name'],
                    'deletedAt' => $row['deleted_at'],
                ];
            }
        }

        // Sort by deleted_at desc
        usort($items, fn($a, $b) => strtotime($b['deletedAt']) - strtotime($a['deletedAt']));

        Response::success($items);
    }

    // Restore a soft-deleted item
    public function restore(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $type = $input['type'] ?? null;
        if (!$type || !isset(self::TABLES[$type])) {
            Response::error('Gecersiz tur', 400);
        }

        $table = self::TABLES[$type]['table'];

        $item = Database::fetch("SELECT id FROM $table WHERE id = ? AND deleted_at IS NOT NULL", [$id]);
        if (!$item) {
            Response::error('Kayit bulunamadi', 404);
        }

        Database::update($table, [
            'deleted_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        Response::success(null, 'Kayit geri yuklendi');
    }

    // Permanent delete
    public function permanentDelete(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $type = $input['type'] ?? null;
        if (!$type || !isset(self::TABLES[$type])) {
            Response::error('Gecersiz tur', 400);
        }

        $table = self::TABLES[$type]['table'];

        Database::query("DELETE FROM $table WHERE id = ? AND deleted_at IS NOT NULL", [$id]);
        Response::success(null, 'Kayit kalici olarak silindi');
    }

    // Empty trash for a type or all
    public function empty(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $type = $input['type'] ?? null;

        if ($type && isset(self::TABLES[$type])) {
            $table = self::TABLES[$type]['table'];
            Database::query("DELETE FROM $table WHERE deleted_at IS NOT NULL");
        } else {
            // Empty all
            foreach (self::TABLES as $t => $config) {
                Database::query("DELETE FROM {$config['table']} WHERE deleted_at IS NOT NULL");
            }
        }

        Response::success(null, 'Cop kutusu bosaltildi');
    }

    // CRUD stubs for router compatibility
    public function show(array $user, int $id): void
    {
        Response::error('Detay icin tur belirtin', 400);
    }

    public function store(array $user, array $input): void
    {
        // Restore action
        if (!empty($input['action']) && $input['action'] === 'restore' && !empty($input['id'])) {
            $this->restore($user, (int) $input['id'], $input);
            return;
        }
        // Permanent delete action
        if (!empty($input['action']) && $input['action'] === 'permanent_delete' && !empty($input['id'])) {
            $this->permanentDelete($user, (int) $input['id'], $input);
            return;
        }
        // Empty trash
        if (!empty($input['action']) && $input['action'] === 'empty') {
            $this->empty($user, $input);
            return;
        }

        Response::error('Gecersiz islem', 400);
    }

    public function update(array $user, int $id, array $input): void
    {
        // Restore via PUT
        $this->restore($user, $id, $input);
    }

    public function destroy(array $user, int $id): void
    {
        Response::error('Kalici silme icin POST /api/trash kullanin', 400);
    }
}
