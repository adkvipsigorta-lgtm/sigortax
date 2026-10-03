<?php

class CompanyController
{
    public function index(array $user, array $query): void
    {
        // ?all=1: dropdown/form icin tum sirketler (sayfalama yok, hafif response)
        if (!empty($query['all'])) {
            $companies = Database::fetchAll(
                "SELECT * FROM companies WHERE deleted_at IS NULL ORDER BY name"
            );
            Response::success(array_map([$this, 'format'], $companies));
            return;
        }

        $policySub = "(SELECT COUNT(*) FROM policies p WHERE p.company_id = c.id AND p.parent_id IS NULL AND p.deleted_at IS NULL)";
        $activePolicySub = "(SELECT COUNT(*) FROM policies p WHERE p.company_id = c.id AND p.parent_id IS NULL AND p.deleted_at IS NULL
            AND p.is_cancelled = 0
            AND (SELECT z.is_cancelled FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL ORDER BY z.endorsement_no DESC LIMIT 1) = 0
            AND (SELECT z2.expires_at FROM policies z2 WHERE z2.policy_no = p.policy_no AND z2.deleted_at IS NULL ORDER BY z2.endorsement_no DESC LIMIT 1) >= CURDATE())";

        $where = ["c.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['search'])) {
            $search = '%' . $query['search'] . '%';
            $where[] = "(c.name LIKE ?)";
            $params[] = $search;
        }

        $whereSql = implode(' AND ', $where);

        $allowedSorts = ['name', 'policy_count', 'active_policy_count', 'created_at'];
        $sort = in_array($query['sort'] ?? '', $allowedSorts) ? $query['sort'] : 'name';
        $order = ($query['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $orderCol = match($sort) {
            'policy_count' => 'policy_count',
            'active_policy_count' => 'active_policy_count',
            default => "c.$sort",
        };

        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT c.*, $policySub as policy_count, $activePolicySub as active_policy_count
                FROM companies c
                WHERE $whereSql
                ORDER BY $orderCol $order";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $company = Database::fetch(
            "SELECT * FROM companies WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$company) {
            Response::error('Sirket bulunamadi', 404);
        }

        Response::success($this->format($company));
    }

    public function store(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $validator = new Validator();
        if (!$validator->validate($input, ['name' => 'required|min:2'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        // Logo upload
        $logoPath = $this->handleLogoUpload($input);

        $id = Database::insert('companies', [
            'name' => $input['name'],
            'color' => $input['color'] ?? 'primary',
            'logo_url' => $logoPath ?? ($input['logo'] ?? null),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Sirket olusturuldu', 201);
    }

    public function update(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $existing = Database::fetch("SELECT id FROM companies WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Sirket bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($input['name'])) $data['name'] = $input['name'];
        if (isset($input['color'])) $data['color'] = $input['color'];

        // Logo upload
        $logoPath = $this->handleLogoUpload($input);
        if ($logoPath) {
            $data['logo_url'] = $logoPath;
        } elseif (array_key_exists('logo', $input)) {
            $data['logo_url'] = $input['logo'];
        }

        Database::update('companies', $data, 'id = ?', [$id]);
        Response::success(null, 'Sirket guncellendi');
    }

    public function uploadLogo(array $user): void
    {
        AuthMiddleware::requireAdmin($user);

        if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Logo yuklenemedi', 400);
        }

        $file = $_FILES['logo'];
        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
        if (!in_array($file['type'], $allowed)) {
            Response::error('Gecersiz dosya turu. PNG, JPG, GIF, WebP veya SVG olmalidir.', 400);
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            Response::error('Dosya boyutu 2MB\'den buyuk olamaz.', 400);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'png';
        $filename = uniqid('logo_') . '.' . $ext;
        $uploadDir = __DIR__ . '/../uploads/companies/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $dest = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::error('Dosya kaydedilemedi', 500);
        }

        $url = '/api/uploads/companies/' . $filename;
        Response::success(['url' => $url], 'Logo yuklendi');
    }

    public function destroy(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);
        Database::softDelete('companies', $id);
        Response::success(null, 'Sirket silindi');
    }

    private function handleLogoUpload(array $input): ?string
    {
        // Base64 logo desteği
        if (!empty($input['logoBase64'])) {
            $data = $input['logoBase64'];
            if (preg_match('/^data:image\/(\w+);base64,/', $data, $matches)) {
                $ext = $matches[1] === 'svg+xml' ? 'svg' : $matches[1];
                $data = substr($data, strpos($data, ',') + 1);
                $decoded = base64_decode($data);
                if ($decoded === false) return null;

                $filename = uniqid('logo_') . '.' . $ext;
                $uploadDir = __DIR__ . '/../uploads/companies/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                file_put_contents($uploadDir . $filename, $decoded);
                return '/api/uploads/companies/' . $filename;
            }
        }
        return null;
    }

    private function format(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'name' => $c['name'],
            'color' => $c['color'] ?? 'primary',
            'logo' => $c['logo_url'] ?? null,
            'website' => $c['website'] ?? null,
            'policyCount' => (int) ($c['policy_count'] ?? 0),
            'activePolicyCount' => (int) ($c['active_policy_count'] ?? 0),
            'createdAt' => $c['created_at'] ?? null,
        ];
    }
}
