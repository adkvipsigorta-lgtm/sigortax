<?php

class InsuranceController
{
    public function index(array $user, array $query): void
    {
        // ?all=1 veya ?hierarchical=1: dropdown/form icin tam liste (sayfalama yok)
        if (!empty($query['all']) || !empty($query['hierarchical'])) {
            $insurances = Database::fetchAll(
                "SELECT * FROM insurance_types WHERE deleted_at IS NULL ORDER BY level, name"
            );
            $result = array_map([$this, 'format'], $insurances);
            if (!empty($query['hierarchical'])) {
                $result = $this->buildHierarchy($result);
            }
            Response::success($result);
            return;
        }

        // Sayfalamali liste (sadece subcategory)
        $where = ["deleted_at IS NULL", "level = 'subcategory'"];
        $params = [];

        if (!empty($query['search'])) {
            $where[] = "(name LIKE ? OR branch_group LIKE ?)";
            $s = '%' . $query['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = implode(' AND ', $where);

        $allowedSorts = ['name', 'branch_group', 'default_comm_rate', 'renewal_days', 'is_active', 'created_at'];
        $sort = in_array($query['sort'] ?? '', $allowedSorts) ? $query['sort'] : 'name';
        $order = ($query['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = min(100, max(1, (int)($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT * FROM insurance_types WHERE $whereSql ORDER BY $sort $order";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $insurance = Database::fetch(
            "SELECT * FROM insurance_types WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$insurance) {
            Response::error('Sigorta turu bulunamadi', 404);
        }

        Response::success($this->format($insurance));
    }

    public function store(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $validator = new Validator();
        if (!$validator->validate($input, [
            'name' => 'required|min:2',
            'level' => 'required|in:branch,category,subcategory',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $data = [
            'name' => $input['name'],
            'code' => $input['code'] ?? 'OTHER',
            'color' => $input['color'] ?? 'primary',
            'default_comm_rate' => $input['defaultCommRate'] ?? 0,
            'extra_comm_rate' => $input['extraCommRate'] ?? 0,
            'level' => $input['level'],
            'parent_id' => $input['parentId'] ?? null,
            'is_active' => isset($input['isActive']) ? ((bool) $input['isActive'] ? 1 : 0) : 1,
            'show_in_charts' => isset($input['showInCharts']) ? ((bool) $input['showInCharts'] ? 1 : 0) : 0,
            'is_renewable' => isset($input['isRenewable']) ? ((bool) $input['isRenewable'] ? 1 : 0) : 1,
            'branch_group' => $input['branchGroup'] ?? null,
            'renewal_days' => $input['renewalDays'] ?? 15,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $id = Database::insert('insurance_types', $data);
        Response::success(['id' => $id], 'Sigorta turu olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $existing = Database::fetch("SELECT id FROM insurance_types WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Sigorta turu bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];

        $fields = [
            'name' => 'name',
            'code' => 'code',
            'color' => 'color',
            'defaultCommRate' => 'default_comm_rate',
            'extraCommRate' => 'extra_comm_rate',
            'level' => 'level',
            'parentId' => 'parent_id',
            'isActive' => 'is_active',
            'showInCharts' => 'show_in_charts',
            'isRenewable' => 'is_renewable',
            'branchGroup' => 'branch_group',
            'renewalDays' => 'renewal_days',
        ];
        $boolFields = ['is_active', 'show_in_charts', 'is_renewable'];
        foreach ($fields as $inputKey => $dbCol) {
            if (array_key_exists($inputKey, $input)) {
                $val = $input[$inputKey];
                if (in_array($dbCol, $boolFields)) {
                    $val = $val ? 1 : 0;
                }
                $data[$dbCol] = $val;
            }
        }

        Database::update('insurance_types', $data, 'id = ?', [$id]);
        Response::success(null, 'Sigorta turu guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);
        Database::softDelete('insurance_types', $id);
        Response::success(null, 'Sigorta turu silindi');
    }

    private function format(array $i): array
    {
        return [
            'id' => (int) $i['id'],
            'name' => $i['name'],
            'code' => $i['code'],
            'color' => $i['color'],
            'defaultCommRate' => (float) $i['default_comm_rate'],
            'extraCommRate' => $i['extra_comm_rate'] ? (float) $i['extra_comm_rate'] : null,
            'level' => $i['level'],
            'parentId' => $i['parent_id'] ? (int) $i['parent_id'] : null,
            'isActive' => (bool) $i['is_active'],
            'showInCharts' => (bool) ($i['show_in_charts'] ?? false),
            'isRenewable' => (bool) ($i['is_renewable'] ?? true),
            'branchGroup' => $i['branch_group'],
            'renewalDays' => (int) ($i['renewal_days'] ?? 15),
            'externalCode' => $i['external_code'] ?? null,
        ];
    }

    private function buildHierarchy(array $items): array
    {
        $branches = array_filter($items, fn($i) => $i['level'] === 'branch');
        $categories = array_filter($items, fn($i) => $i['level'] === 'category');
        $subcategories = array_filter($items, fn($i) => $i['level'] === 'subcategory');

        foreach ($categories as &$cat) {
            $cat['children'] = array_values(array_filter($subcategories, fn($s) => $s['parentId'] === $cat['id']));
        }

        foreach ($branches as &$branch) {
            $branch['children'] = array_values(array_filter($categories, fn($c) => $c['parentId'] === $branch['id']));
        }

        return array_values($branches);
    }
}
