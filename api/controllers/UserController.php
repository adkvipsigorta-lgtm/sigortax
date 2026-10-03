<?php

class UserController
{
    public function index(array $user, array $query): void
    {
        // dropdown=1: sadece id+name (herkes erisebilir)
        if (!empty($query['dropdown'])) {
            $users = Database::fetchAll(
                "SELECT id, name FROM users WHERE is_active = 1 AND is_sales_rep = 1 AND deleted_at IS NULL ORDER BY name ASC"
            );
            $result = array_map(function ($u) {
                return ['id' => (int) $u['id'], 'name' => $u['name']];
            }, $users);
            Response::success($result);
            return;
        }

        AuthMiddleware::requireAdmin($user);

        $where = ["u.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
            $s = '%' . $query['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = implode(' AND ', $where);

        $allowedSorts = ['name', 'email', 'role', 'is_active', 'created_at'];
        $sort = in_array($query['sort'] ?? '', $allowedSorts) ? $query['sort'] : 'created_at';
        $order = ($query['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = min(100, max(1, (int)($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT u.id, u.name, u.email, u.is_active, u.is_sales_rep, u.role, u.branch_id, u.customer_ids, u.created_at
                FROM users u
                WHERE $whereSql
                ORDER BY u.$sort $order";

        $result = Database::paginate($sql, $params, $page, $limit);

        $roleMap = [1 => 'admin', 0 => 'kullanici', 2 => 'acente', 3 => 'musteri'];
        $result['data'] = array_map(function ($u) use ($roleMap) {
            return [
                'id' => (int) $u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'role' => $roleMap[(int) $u['role']] ?? 'kullanici',
                'branchId' => $u['branch_id'] ? (int) $u['branch_id'] : null,
                'isActive' => (bool) $u['is_active'],
                'isSalesRep' => (bool) ($u['is_sales_rep'] ?? 1),
                'customerIds' => $u['customer_ids'] ? json_decode($u['customer_ids'], true) : [],
                'createdAt' => $u['created_at'],
            ];
        }, $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        AuthMiddleware::requireAdminOrSelf($user, $id);

        $u = Database::fetch(
            "SELECT id, name, email, is_active, is_sales_rep, role, branch_id, created_at FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$u) {
            Response::error('Kullanici bulunamadi', 404);
        }

        $roleMap = [1 => 'admin', 0 => 'kullanici', 2 => 'acente', 3 => 'musteri'];

        Response::success([
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'role' => $roleMap[(int) $u['role']] ?? 'kullanici',
            'branchId' => $u['branch_id'] ? (int) $u['branch_id'] : null,
            'isActive' => (bool) $u['is_active'],
            'isSalesRep' => (bool) ($u['is_sales_rep'] ?? 1),
            'createdAt' => $u['created_at'],
        ]);
    }

    public function store(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $validator = new Validator();
        if (!$validator->validate($input, [
            'name' => 'required|min:2',
            'email' => 'required|email',
            'password' => 'required|min:6',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        // Check email uniqueness
        $exists = Database::fetch("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL", [$input['email']]);
        if ($exists) {
            Response::error('Bu e-posta adresi zaten kullaniliyor', 422);
        }

        $roleMap = ['admin' => 1, 'kullanici' => 0, 'acente' => 2, 'musteri' => 3];

        $insertData = [
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => password_hash($input['password'], PASSWORD_DEFAULT),
            'is_active' => isset($input['isActive']) ? (int) $input['isActive'] : 1,
            'role' => $roleMap[$input['role'] ?? 'kullanici'] ?? 0,
            'branch_id' => $input['branchId'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Musteri rolunde customer_ids zorunlu
        if (($input['role'] ?? '') === 'musteri' && !empty($input['customerIds'])) {
            $insertData['customer_ids'] = json_encode(array_map('intval', $input['customerIds']));
        }

        $id = Database::insert('users', $insertData);

        Response::success(['id' => $id], 'Kullanici olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdminOrSelf($user, $id);

        $existing = Database::fetch("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Kullanici bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($input['name'])) $data['name'] = $input['name'];
        if (isset($input['email'])) {
            $emailCheck = Database::fetch("SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL", [$input['email'], $id]);
            if ($emailCheck) {
                Response::error('Bu e-posta adresi zaten kullaniliyor', 422);
            }
            $data['email'] = $input['email'];
        }
        if (!empty($input['password'])) {
            $data['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
        }

        // Only admin can change role/status
        if ((int) $user['role'] === 1) {
            $roleMap = ['admin' => 1, 'kullanici' => 0, 'acente' => 2, 'musteri' => 3];
            if (isset($input['role'])) $data['role'] = $roleMap[$input['role']] ?? 0;
            if (isset($input['isActive'])) $data['is_active'] = (int) $input['isActive'];
            if (isset($input['isSalesRep'])) $data['is_sales_rep'] = (int) $input['isSalesRep'];
            if (array_key_exists('branchId', $input)) $data['branch_id'] = $input['branchId'];
            if (isset($input['customerIds'])) {
                $data['customer_ids'] = is_array($input['customerIds'])
                    ? json_encode(array_map('intval', $input['customerIds']))
                    : null;
            }
        }

        Database::update('users', $data, 'id = ?', [$id]);

        // Disable edildiyse tum oturumlarini revoke et ve sahipsiz gorevleri havuza al
        if (isset($data['is_active']) && (int) $data['is_active'] === 0) {
            Database::query(
                "UPDATE sessions SET is_revoked = 1 WHERE user_id = ? AND is_revoked = 0",
                [$id]
            );
            Database::query(
                "UPDATE tasks SET assigned_to = NULL, assigned_by = NULL, updated_at = ?
                 WHERE assigned_to = ? AND status IN ('PENDING', 'IN_PROGRESS') AND deleted_at IS NULL",
                [date('Y-m-d H:i:s'), $id]
            );
        }

        Response::success(null, 'Kullanici guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);

        if ((int) $user['userId'] === $id) {
            Response::error('Kendinizi silemezsiniz', 400);
        }

        // Tum oturumlarini revoke et
        Database::query(
            "UPDATE sessions SET is_revoked = 1 WHERE user_id = ? AND is_revoked = 0",
            [$id]
        );

        // Tamamlanmamis gorevleri havuza al
        Database::query(
            "UPDATE tasks SET assigned_to = NULL, assigned_by = NULL, updated_at = ?
             WHERE assigned_to = ? AND status IN ('PENDING', 'IN_PROGRESS') AND deleted_at IS NULL",
            [date('Y-m-d H:i:s'), $id]
        );

        Database::softDelete('users', $id);
        Response::success(null, 'Kullanici silindi');
    }

    /**
     * Kullanicinin izinlerini getir
     */
    public function getPermissions(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);

        $target = Database::fetch("SELECT id, role FROM users WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$target) {
            Response::error('Kullanici bulunamadi', 404);
        }

        $permissions = Permission::getAllForUser((int) $target['id'], (int) $target['role']);
        Response::success($permissions);
    }

    /**
     * Kullanicinin izinlerini guncelle
     */
    public function updatePermissions(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $target = Database::fetch("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$target) {
            Response::error('Kullanici bulunamadi', 404);
        }

        if (!isset($input['permissions']) || !is_array($input['permissions'])) {
            Response::error('Izin listesi gerekli', 422);
        }

        Permission::updateForUser((int) $id, $input['permissions']);
        Response::success(null, 'Izinler guncellendi');
    }

    /**
     * Giris yapan kullanicinin kendi izinlerini getir
     */
    public function myPermissions(array $user): void
    {
        $permissions = Permission::getAllForUser((int) $user['userId'], (int) $user['role']);

        $result = [];
        foreach ($permissions as $p) {
            $result[$p['key']] = $p['allowed'];
        }

        Response::success($result);
    }
}
