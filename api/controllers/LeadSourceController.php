<?php

class LeadSourceController
{
    public function index(array $user, array $query): void
    {
        Permission::require($user, 'leads.view');

        $where = ["deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $where[] = "name LIKE ?";
            $params[] = '%' . $query['search'] . '%';
        }

        if (!empty($query['all'])) {
            $rows = Database::fetchAll(
                "SELECT * FROM lead_sources WHERE " . implode(' AND ', $where) . " ORDER BY name ASC",
                $params
            );
            Response::success(array_map([$this, 'format'], $rows));
            return;
        }

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = min(100, max(1, (int)($query['limit'] ?? 50)));

        $sql = "SELECT * FROM lead_sources WHERE " . implode(' AND ', $where) . " ORDER BY name ASC";
        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    public function store(array $user, array $input): void
    {
        Permission::require($user, 'leads.settings');

        $validator = new Validator();
        if (!$validator->validate($input, ['name' => 'required|min:2'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $id = Database::insert('lead_sources', [
            'name' => $input['name'],
            'color' => $input['color'] ?? null,
            'is_active' => isset($input['isActive']) ? (int)$input['isActive'] : 1,
            'auto_assign_to' => !empty($input['autoAssignEnabled']) ? 1 : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Lead kaynagi olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        Permission::require($user, 'leads.settings');

        $existing = Database::fetch("SELECT id FROM lead_sources WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) Response::error('Kaynak bulunamadi', 404);

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($input['name'])) $data['name'] = $input['name'];
        if (isset($input['color'])) $data['color'] = $input['color'];
        if (isset($input['isActive'])) $data['is_active'] = (int)$input['isActive'];
        if (isset($input['autoAssignEnabled'])) $data['auto_assign_to'] = !empty($input['autoAssignEnabled']) ? 1 : null;

        Database::update('lead_sources', $data, 'id = ?', [$id]);
        Response::success(null, 'Lead kaynagi guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        Permission::require($user, 'leads.settings');
        Database::softDelete('lead_sources', $id);
        Response::success(null, 'Lead kaynagi silindi');
    }

    private function format(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'color' => $r['color'],
            'isActive' => (bool)$r['is_active'],
            'autoAssignEnabled' => !empty($r['auto_assign_to']),
            'createdAt' => $r['created_at'],
        ];
    }
}
