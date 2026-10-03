<?php

require_once __DIR__ . '/../helpers/DocumentCrypto.php';

class DocumentController
{
    private const MAX_BYTES = 20 * 1024 * 1024; // 20 MB

    /**
     * Uzanti → (resmi MIME, magic byte imzalari) eslesmesi.
     * Uzantiya göre tanimlariz, sonra magic byte ile dogrularız.
     */
    private const EXT_MAP = [
        'pdf'  => ['mime' => 'application/pdf',                                                           'magic' => ["\x25\x50\x44\x46"]],
        'jpg'  => ['mime' => 'image/jpeg',                                                                 'magic' => ["\xFF\xD8\xFF"]],
        'jpeg' => ['mime' => 'image/jpeg',                                                                 'magic' => ["\xFF\xD8\xFF"]],
        'png'  => ['mime' => 'image/png',                                                                  'magic' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"]],
        'webp' => ['mime' => 'image/webp',                                                                 'magic' => ["\x52\x49\x46\x46"]],
        'docx' => ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',    'magic' => ["\x50\x4B\x03\x04"]],
        'xlsx' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',          'magic' => ["\x50\x4B\x03\x04"]],
        'doc'  => ['mime' => 'application/msword',                                                         'magic' => ["\xD0\xCF\x11\xE0"]],
        'xls'  => ['mime' => 'application/vnd.ms-excel',                                                   'magic' => ["\xD0\xCF\x11\xE0"]],
    ];

    public function index(array $user, array $query): void
    {
        $where = ["d.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['customerId'])) {
            $where[] = "d.customer_id = ?";
            $params[] = (int) $query['customerId'];
        }
        if (!empty($query['policyId'])) {
            $where[] = "d.policy_id = ?";
            $params[] = (int) $query['policyId'];
        }

        $whereSql = implode(' AND ', $where);
        $docs = Database::fetchAll(
            "SELECT d.*, u.name as uploaded_by_name
             FROM documents d
             LEFT JOIN users u ON d.uploaded_by = u.id
             WHERE $whereSql
             ORDER BY d.created_at DESC",
            $params
        );

        $this->applyRowLevelAccess($user, $docs);

        Response::success(array_map([$this, 'format'], $docs));
    }

    public function show(array $user, int $id): void
    {
        $doc = $this->fetchDoc($id);
        if (!$doc) Response::error('Belge bulunamadi', 404);
        $this->requireAccess($user, $doc);
        Response::success($this->format($doc));
    }

    public function store(array $user, array $input): void
    {
        if (empty($_FILES['file'])) {
            Response::error('Dosya yuklenemedi', 400);
        }

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Dosya yukleme hatasi (kod ' . $file['error'] . ')', 400);
        }

        if ($file['size'] > self::MAX_BYTES) {
            Response::error('Dosya 20MB sinirini asiyor.', 400);
        }

        // Uzanti → beklenen MIME/magic (uzantiya "yetki" degil, sadece beklenti)
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!isset(self::EXT_MAP[$ext])) {
            Response::error('Izin verilmeyen dosya uzantisi: .' . $ext, 400);
        }
        $expected = self::EXT_MAP[$ext];
        $detectedMime = $expected['mime'];

        // Magic byte ile dosya icerigini dogrula (as-l gvenlik)
        if (!$this->verifyMagicBytes($file['tmp_name'], $expected['magic'])) {
            Response::error('Dosya icerigi .' . $ext . ' formatiyla uyusmuyor.', 400);
        }

        $customerId = !empty($_POST['customerId']) ? (int) $_POST['customerId'] : (!empty($input['customerId']) ? (int) $input['customerId'] : null);
        $policyId = !empty($_POST['policyId']) ? (int) $_POST['policyId'] : (!empty($input['policyId']) ? (int) $input['policyId'] : null);

        if (!$customerId && !$policyId) {
            Response::error('customerId veya policyId zorunlu.', 400);
        }

        // Yukleme hakki: admin her yerde; acente sadece kendi brancının poliçesine
        if ((int) $user['role'] === 2 && $policyId) {
            $policy = Database::fetch("SELECT branch_id FROM policies WHERE id = ? AND deleted_at IS NULL", [$policyId]);
            if (!$policy || (int) $policy['branch_id'] !== (int) $user['branch_id']) {
                Response::error('Bu poliçeye dosya yukleme yetkiniz yok.', 403);
            }
        }

        // Envelope encryption
        try {
            $dek = DocumentCrypto::generateDek();
            $hash = DocumentCrypto::newStorageHash();
            $target = DocumentCrypto::storagePathFromHash($hash);

            $fileMeta = DocumentCrypto::encryptFile($file['tmp_name'], $target, $dek);
            $dekEnc = DocumentCrypto::encryptDek($dek);

            $id = Database::insert('documents', [
                'name' => $this->sanitizeFilename($file['name']),
                'file_path' => '',                                  // legacy kolon - artik kullanilmiyor
                'storage_hash' => $hash,
                'mime_type' => $detectedMime,
                'file_size' => (int) $file['size'],
                'encrypted' => 1,
                'file_iv' => $fileMeta['iv'],
                'file_auth_tag' => $fileMeta['tag'],
                'dek_encrypted' => $dekEnc['ciphertext'],
                'dek_iv' => $dekEnc['iv'],
                'dek_auth_tag' => $dekEnc['tag'],
                'customer_id' => $customerId,
                'policy_id' => $policyId,
                'uploaded_by' => $user['userId'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Bellekteki DEK'i temizle
            $dek = str_repeat("\0", strlen($dek));

            Response::success(['id' => $id], 'Belge yuklendi', 201);
        } catch (\Throwable $e) {
            if (!empty($target) && is_file($target)) @unlink($target);
            Response::error('Sifreleme/kayit hatasi: ' . $e->getMessage(), 500);
        }
    }

    public function update(array $user, int $id, array $input): void
    {
        $doc = $this->fetchDoc($id);
        if (!$doc) Response::error('Belge bulunamadi', 404);
        $this->requireAccess($user, $doc);

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($input['name'])) $data['name'] = $this->sanitizeFilename($input['name']);
        Database::update('documents', $data, 'id = ?', [$id]);
        Response::success(null, 'Belge guncellendi');
    }

    public function destroy(array $user, int $id): void
    {
        $doc = $this->fetchDoc($id);
        if (!$doc) Response::error('Belge bulunamadi', 404);
        $this->requireAccess($user, $doc);

        Database::softDelete('documents', $id);
        Response::success(null, 'Belge silindi');
    }

    /**
     * Download endpoint — sifre cozup stream eder.
     */
    public function download(array $user, int $id): void
    {
        $doc = $this->fetchDoc($id);
        if (!$doc) Response::error('Belge bulunamadi', 404);
        $this->requireAccess($user, $doc);

        if (empty($doc['storage_hash']) || empty($doc['encrypted'])) {
            Response::error('Bu belge sifreli formatta degil.', 400);
        }

        $encPath = DocumentCrypto::storagePathFromHash($doc['storage_hash']);
        if (!is_file($encPath)) {
            Response::error('Sifreli dosya diskte bulunamadi.', 404);
        }

        try {
            $dek = DocumentCrypto::decryptDek($doc['dek_encrypted'], $doc['dek_iv'], $doc['dek_auth_tag']);
            // Headers
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
            header('Content-Length: ' . (int) $doc['file_size']);
            header('Content-Disposition: attachment; filename="' . rawurlencode($doc['name']) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');

            DocumentCrypto::decryptFileToOutput($encPath, $dek, $doc['file_iv'], $doc['file_auth_tag']);
            // Bellekteki DEK'i sil
            $dek = str_repeat("\0", strlen($dek));
            exit;
        } catch (\Throwable $e) {
            Response::error('Dosya cozulemedi: ' . $e->getMessage(), 500);
        }
    }

    // ===================================================================

    private function fetchDoc(int $id): ?array
    {
        return Database::fetch(
            "SELECT d.*, u.name as uploaded_by_name
             FROM documents d
             LEFT JOIN users u ON d.uploaded_by = u.id
             WHERE d.id = ? AND d.deleted_at IS NULL",
            [$id]
        );
    }

    private function requireAccess(array $user, array $doc): void
    {
        if ((int) $user['role'] === 1) return; // admin her sey
        // acente: sadece kendi brancının poliçesine bagli dosyalar
        if ((int) $user['role'] === 2) {
            if ($doc['policy_id']) {
                $p = Database::fetch("SELECT branch_id FROM policies WHERE id = ? AND deleted_at IS NULL", [$doc['policy_id']]);
                if ($p && (int) $p['branch_id'] === (int) $user['branch_id']) return;
            }
            // acente musteri dosyasi: poliçe zinciri uzerinden acenteye bagli mi?
            if ($doc['customer_id']) {
                $row = Database::fetch(
                    "SELECT 1 FROM policies WHERE customer_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1",
                    [$doc['customer_id'], $user['branch_id']]
                );
                if ($row) return;
            }
            Response::error('Bu belgeye erisim yetkiniz yok.', 403);
        }
        // kullanici (role 0): sadece kendi yukledigi
        if ((int) $doc['uploaded_by'] !== (int) $user['userId']) {
            Response::error('Bu belgeye erisim yetkiniz yok.', 403);
        }
    }

    private function applyRowLevelAccess(array $user, array &$docs): void
    {
        if ((int) $user['role'] === 1) return; // admin hepsi
        $docs = array_values(array_filter($docs, function ($d) use ($user) {
            if ((int) $user['role'] === 2) {
                if ($d['policy_id']) {
                    $p = Database::fetch("SELECT branch_id FROM policies WHERE id = ? AND deleted_at IS NULL", [$d['policy_id']]);
                    return $p && (int) $p['branch_id'] === (int) $user['branch_id'];
                }
                if ($d['customer_id']) {
                    return (bool) Database::fetch(
                        "SELECT 1 FROM policies WHERE customer_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1",
                        [$d['customer_id'], $user['branch_id']]
                    );
                }
                return false;
            }
            return (int) $d['uploaded_by'] === (int) $user['userId'];
        }));
    }

    private function verifyMagicBytes(string $path, array $signatures): bool
    {
        if (!$signatures) return true;
        $fh = fopen($path, 'rb');
        if (!$fh) return false;
        $head = fread($fh, 16);
        fclose($fh);
        foreach ($signatures as $sig) {
            if (str_starts_with($head, $sig)) return true;
        }
        return false;
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w\s\-\.]/u', '_', $name);
        return substr($name, 0, 200);
    }

    private function format(array $d): array
    {
        return [
            'id' => (int) $d['id'],
            'name' => $d['name'],
            'type' => $d['mime_type'],
            'size' => (int) ($d['file_size'] ?? 0),
            'encrypted' => (bool) ($d['encrypted'] ?? 0),
            'customerId' => $d['customer_id'] ? (int) $d['customer_id'] : null,
            'policyId' => $d['policy_id'] ? (int) $d['policy_id'] : null,
            'uploadedAt' => $d['created_at'],
            'uploadedBy' => $d['uploaded_by_name'] ?? null,
        ];
    }
}
