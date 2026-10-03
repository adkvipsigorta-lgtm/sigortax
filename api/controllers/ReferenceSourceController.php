<?php

class ReferenceSourceController
{
    public function index(array $user, array $query): void
    {
        $where = ["deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $where[] = "name LIKE ?";
            $params[] = '%' . $query['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        // Simple list (no pagination) for dropdowns
        if (!empty($query['all'])) {
            $rows = Database::fetchAll(
                "SELECT * FROM reference_sources WHERE $whereSql ORDER BY name ASC",
                $params
            );
            Response::success(array_map([$this, 'format'], $rows));
            return;
        }

        $allowedSorts = ['name', 'commission_rate', 'created_at'];
        $sort = in_array($query['sort'] ?? '', $allowedSorts) ? $query['sort'] : 'name';
        $order = ($query['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = min(100, max(1, (int)($query['limit'] ?? ITEMS_PER_PAGE)));

        // Count policies per reference source
        $sql = "SELECT rs.*,
                    (SELECT COUNT(*) FROM policies p WHERE p.reference_source = rs.id AND p.deleted_at IS NULL) as policy_count
                FROM reference_sources rs
                WHERE rs.$whereSql
                ORDER BY rs.$sort $order";

        // Fix: rs. prefix only for rs columns
        $sql = "SELECT rs.*,
                    (SELECT COUNT(*) FROM policies p WHERE p.reference_source = rs.id AND p.deleted_at IS NULL) as policy_count
                FROM reference_sources rs
                WHERE rs.deleted_at IS NULL" .
                (!empty($query['search']) ? " AND rs.name LIKE ?" : "") .
                " ORDER BY rs.$sort $order";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $row = Database::fetch(
            "SELECT * FROM reference_sources WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$row) {
            Response::error('Referans kaynagi bulunamadi', 404);
        }

        Response::success($this->format($row));
    }

    public function store(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $validator = new Validator();
        if (!$validator->validate($input, [
            'name' => 'required|min:2',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $id = Database::insert('reference_sources', [
            'name' => $input['name'],
            'commission_rate' => (float)($input['commissionRate'] ?? 0),
            'business_type' => in_array($input['businessType'] ?? '', ['NEW', 'RENEWAL']) ? $input['businessType'] : null,
            'is_active' => isset($input['isActive']) ? (int)$input['isActive'] : 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Referans kaynagi olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $existing = Database::fetch("SELECT id FROM reference_sources WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Referans kaynagi bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($input['name'])) $data['name'] = $input['name'];
        if (isset($input['commissionRate'])) $data['commission_rate'] = (float)$input['commissionRate'];
        if (isset($input['isActive'])) $data['is_active'] = (int)$input['isActive'];
        if (isset($input['businessType'])) $data['business_type'] = in_array($input['businessType'], ['NEW', 'RENEWAL']) ? $input['businessType'] : null;

        Database::update('reference_sources', $data, 'id = ?', [$id]);
        Response::success(null, 'Referans kaynagi guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);
        Database::softDelete('reference_sources', $id);
        Response::success(null, 'Referans kaynagi silindi');
    }

    private function format(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'commissionRate' => (float)$r['commission_rate'],
            'businessType' => $r['business_type'] ?? null,
            'isActive' => (bool)$r['is_active'],
            'policyCount' => (int)($r['policy_count'] ?? 0),
            'createdAt' => $r['created_at'],
        ];
    }
}
