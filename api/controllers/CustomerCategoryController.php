<?php

class CustomerCategoryController
{
    public function index(array $user, array $query): void
    {
        $subQuery = "(SELECT COUNT(*) FROM customers c WHERE c.deleted_at IS NULL
            AND (SELECT COALESCE(SUM(CASE WHEN p.is_cancelled = 1 AND p.gross_premium > 0 THEN -p.gross_premium ELSE p.gross_premium END), 0) FROM policies p WHERE p.customer_id = c.id AND p.deleted_at IS NULL)
                >= COALESCE(cc.min_amount, 0)
            AND (cc.max_amount IS NULL OR (SELECT COALESCE(SUM(CASE WHEN p2.is_cancelled = 1 AND p2.gross_premium > 0 THEN -p2.gross_premium ELSE p2.gross_premium END), 0) FROM policies p2 WHERE p2.customer_id = c.id AND p2.deleted_at IS NULL)
                < cc.max_amount))";

        // Sidebar/system istegi: tum kategoriler, varsayilan sira, sayfalama yok
        if (!empty($query['system'])) {
            $categories = Database::fetchAll(
                "SELECT cc.*, $subQuery as customer_count FROM customer_categories cc WHERE cc.deleted_at IS NULL ORDER BY cc.is_default DESC, cc.name ASC"
            );
            $totalCustomers = (int) Database::fetch("SELECT COUNT(*) as total FROM customers WHERE deleted_at IS NULL")['total'];
            $result = array_map([$this, 'format'], $categories);
            Response::success(['categories' => $result, 'totalCustomers' => $totalCustomers]);
            return;
        }

        // Tablo istegi: sayfalama, arama, siralama
        $where = ["cc.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $search = '%' . $query['search'] . '%';
            $where[] = "(cc.name LIKE ? OR cc.description LIKE ?)";
            $params[] = $search;
            $params[] = $search;
        }

        $whereSql = implode(' AND ', $where);

        $allowedSorts = ['name', 'created_at', 'customer_count'];
        $sort = in_array($query['sort'] ?? '', $allowedSorts) ? $query['sort'] : 'name';
        $order = ($query['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = min(100, max(1, (int)($query['limit'] ?? ITEMS_PER_PAGE)));

        $orderCol = $sort === 'customer_count' ? 'customer_count' : "cc.$sort";
        $sql = "SELECT cc.*, $subQuery as customer_count
                FROM customer_categories cc
                WHERE $whereSql
                ORDER BY $orderCol $order";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $cat = Database::fetch(
            "SELECT * FROM customer_categories WHERE id = ? AND deleted_at IS NULL",
        [$id]
        );

        if (!$cat) {
            Response::error('Kategori bulunamadi', 404);
        }

        Response::success($this->format($cat));
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

        $isDefault = !empty($input['isDefault']) ? 1 : 0;

        // If setting as default, unset others
        if ($isDefault) {
            Database::query("UPDATE customer_categories SET is_default = 0 WHERE deleted_at IS NULL");
        }

        $id = Database::insert('customer_categories', [
            'name' => $input['name'],
            'color' => $input['color'] ?? '#3B82F6',
            'description' => $input['description'] ?? null,
            'min_amount' => isset($input['minAmount']) ? (float) $input['minAmount'] : null,
            'max_amount' => isset($input['maxAmount']) ? (float) $input['maxAmount'] : null,
            'is_default' => $isDefault,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Kategori olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $existing = Database::fetch("SELECT id FROM customer_categories WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Kategori bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($input['name']))
            $data['name'] = $input['name'];
        if (isset($input['color']))
            $data['color'] = $input['color'];
        if (isset($input['description']))
            $data['description'] = $input['description'];

        if (array_key_exists('minAmount', $input))
            $data['min_amount'] = $input['minAmount'] !== null && $input['minAmount'] !== '' ? (float) $input['minAmount'] : null;
        if (array_key_exists('maxAmount', $input))
            $data['max_amount'] = $input['maxAmount'] !== null && $input['maxAmount'] !== '' ? (float) $input['maxAmount'] : null;

        if (isset($input['isDefault']) && $input['isDefault']) {
            Database::query("UPDATE customer_categories SET is_default = 0 WHERE deleted_at IS NULL");
            $data['is_default'] = 1;
        }

        Database::update('customer_categories', $data, 'id = ?', [$id]);
        Response::success(null, 'Kategori guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);

        $cat = Database::fetch("SELECT is_default FROM customer_categories WHERE id = ? AND deleted_at IS NULL", [$id]);
        if ($cat && (int)$cat['is_default'] === 1) {
            Response::error('Varsayilan kategori silinemez', 400);
        }

        Database::softDelete('customer_categories', $id);
        Response::success(null, 'Kategori silindi');
    }

    private static $colorMap = [
        'primary' => '#3B82F6',
        'warning' => '#F59E0B',
        'info' => '#3B82F6',
        'success' => '#22C55E',
        'error' => '#EF4444',
        'neutral' => '#64748B',
    ];

    private function resolveColor(?string $color): string
    {
        if (!$color)
            return '#3B82F6';
        if (str_starts_with($color, '#'))
            return $color;
        return self::$colorMap[$color] ?? '#3B82F6';
    }

    private function format(array $c): array
    {
        return [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'color' => $this->resolveColor($c['color']),
            'description' => $c['description'],
            'minAmount' => $c['min_amount'] !== null ? (float)$c['min_amount'] : null,
            'maxAmount' => $c['max_amount'] !== null ? (float)$c['max_amount'] : null,
            'isDefault' => (bool)$c['is_default'],
            'customerCount' => (int)($c['customer_count'] ?? 0),
            'createdAt' => $c['created_at'],
        ];
    }
}