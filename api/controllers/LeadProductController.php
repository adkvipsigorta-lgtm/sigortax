<?php

class LeadProductController
{
    public function index(array $user, array $query): void
    {
        // all=1: dropdown/form listesi — herkese açık. Sayfa listesi: leads.view gerekli.
        if (empty($query['all'])) Permission::require($user, 'leads.view');

        $where = ["deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $where[] = "name LIKE ?";
            $params[] = '%' . $query['search'] . '%';
        }

        if (!empty($query['all'])) {
            $rows = Database::fetchAll(
                "SELECT * FROM lead_products WHERE " . implode(' AND ', $where) . " ORDER BY sort_order ASC, name ASC",
                $params
            );
            Response::success(array_map([$this, 'format'], $rows));
            return;
        }

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = min(100, max(1, (int)($query['limit'] ?? 50)));

        $sql = "SELECT * FROM lead_products WHERE " . implode(' AND ', $where) . " ORDER BY sort_order ASC, name ASC";
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

        $id = Database::insert('lead_products', [
            'name' => $input['name'],
            'color' => $input['color'] ?? null,
            'is_active' => isset($input['isActive']) ? (int)$input['isActive'] : 1,
            'sort_order' => (int)($input['sortOrder'] ?? 0),
            'requires_file' => isset($input['requiresFile']) ? (int)$input['requiresFile'] : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Lead urunu olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        Permission::require($user, 'leads.settings');

        $existing = Database::fetch("SELECT id FROM lead_products WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) Response::error('Urun bulunamadi', 404);

        $data = [];
        if (isset($input['name'])) $data['name'] = $input['name'];
        if (isset($input['color'])) $data['color'] = $input['color'];
        if (isset($input['isActive'])) $data['is_active'] = (int)$input['isActive'];
        if (isset($input['sortOrder'])) $data['sort_order'] = (int)$input['sortOrder'];
        if (isset($input['requiresFile'])) $data['requires_file'] = (int)$input['requiresFile'];

        Database::update('lead_products', $data, 'id = ?', [$id]);
        Response::success(null, 'Lead urunu guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        Permission::require($user, 'leads.settings');
        Database::softDelete('lead_products', $id);
        Response::success(null, 'Lead urunu silindi');
    }

    private function format(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'color' => $r['color'],
            'isActive' => (bool)$r['is_active'],
            'sortOrder' => (int)$r['sort_order'],
            'requiresFile' => (bool)($r['requires_file'] ?? 0),
            'createdAt' => $r['created_at'],
        ];
    }
}
