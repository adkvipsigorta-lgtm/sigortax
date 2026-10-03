<?php

class LeadController
{
    /**
     * Lead listesi — status filtresiyle 3 tab destekler
     * ?tab=acik | devam | kapatilan
     */
    public function index(array $user, array $query): void
    {
        Permission::require($user, 'leads.view');

        $canViewAll = Permission::has($user, 'leads.view_all');

        $tab = $query['tab'] ?? 'acik';
        $where = ["l.deleted_at IS NULL"];
        $params = [];

        // İzni yoksa: kendine atanan + havuz (atanmamış) lead'leri görsün
        if (!$canViewAll) {
            $where[] = "(l.assigned_to = ? OR l.assigned_to IS NULL)";
            $params[] = (int)$user['userId'];
        }

        if ($tab === 'acik') {
            $where[] = "l.status = 'ACIK'";
        } elseif ($tab === 'devam') {
            $where[] = "l.status = 'DEVAM'";
        } elseif ($tab === 'kapatilan') {
            $where[] = "l.status IN ('KAZANILDI','KAYBEDILDI')";
        }

        // Filtreler
        if (!empty($query['sourceId'])) {
            $where[] = "l.source_id = ?";
            $params[] = (int)$query['sourceId'];
        }
        if (!empty($query['productId'])) {
            $where[] = "l.product_id = ?";
            $params[] = (int)$query['productId'];
        }
        if (!empty($query['assignedTo'])) {
            $where[] = "l.assigned_to = ?";
            $params[] = (int)$query['assignedTo'];
        }
        if (!empty($query['search'])) {
            $where[] = "(l.full_name LIKE ? OR l.phone LIKE ? OR l.tc_no LIKE ?)";
            $s = '%' . $query['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = implode(' AND ', $where);

        $allowedSorts = ['full_name', 'created_at', 'closed_at', 'phone'];
        $sort = in_array($query['sort'] ?? '', $allowedSorts) ? $query['sort'] : 'created_at';
        $order = ($query['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = min(100, max(1, (int)($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT l.*,
                    ls.name as source_name, ls.color as source_color,
                    lp.name as product_name, lp.color as product_color,
                    u.name as assigned_name
                FROM leads l
                LEFT JOIN lead_sources ls ON ls.id = l.source_id
                LEFT JOIN lead_products lp ON lp.id = l.product_id
                LEFT JOIN users u ON u.id = l.assigned_to
                WHERE $whereSql
                ORDER BY l.$sort $order";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'format'], $result['data']);

        // ACIK tab: kalan mesai dakikasini hesapla
        if ($tab === 'acik') {
            $setting = Database::fetch("SELECT value FROM settings WHERE `key` = 'lead_reassign_timeout'");
            $timeoutMin = $setting ? (int)$setting['value'] : 30;
            // Tatilleri bir kez yukle
            $hRows = Database::fetchAll("SELECT date FROM lead_holidays");
            $hMap = [];
            foreach ($hRows as $hr) $hMap[$hr['date']] = true;
            foreach ($result['data'] as &$item) {
                if (!empty($item['assignedAt'])) {
                    $elapsed = $this->calcWorkingMinutes($item['assignedAt'], $hMap);
                    $item['remainingMinutes'] = max(0, $timeoutMin - $elapsed);
                } else {
                    $item['remainingMinutes'] = null;
                }
            }
            unset($item);
        }

        // Tab sayilarini ekle
        $counts = $this->getTabCounts($user);

        Response::json([
            'success' => true,
            'data' => $result['data'],
            'pagination' => $result['pagination'],
            'counts' => $counts,
        ]);
    }

    /**
     * Tek lead detay
     */
    public function show(array $user, int $id): void
    {
        Permission::require($user, 'leads.view');

        $lead = Database::fetch(
            "SELECT l.*,
                ls.name as source_name, ls.color as source_color,
                lp.name as product_name, lp.color as product_color,
                u.name as assigned_name,
                cb.name as created_by_name
            FROM leads l
            LEFT JOIN lead_sources ls ON ls.id = l.source_id
            LEFT JOIN lead_products lp ON lp.id = l.product_id
            LEFT JOIN users u ON u.id = l.assigned_to
            LEFT JOIN users cb ON cb.id = l.created_by
            WHERE l.id = ? AND l.deleted_at IS NULL",
            [$id]
        );

        if (!$lead) Response::error('Lead bulunamadi', 404);

        // Aktiviteleri de getir
        $activities = Database::fetchAll(
            "SELECT la.*, u.name as user_name
             FROM lead_activities la
             LEFT JOIN users u ON u.id = la.user_id
             WHERE la.lead_id = ?
             ORDER BY la.created_at DESC",
            [$id]
        );

        $data = $this->format($lead);
        $data['createdByName'] = $lead['created_by_name'] ?? '';
        $data['activities'] = array_map(function ($a) {
            return [
                'id' => (int)$a['id'],
                'type' => $a['type'],
                'content' => $a['content'],
                'oldValue' => $a['old_value'],
                'newValue' => $a['new_value'],
                'userName' => $a['user_name'] ?? '',
                'createdAt' => $a['created_at'],
            ];
        }, $activities);

        Response::success($data);
    }

    /**
     * Yeni lead olustur (manuel giris)
     */
    public function store(array $user, array $input): void
    {
        Permission::require($user, 'leads.manage');

        $isQuick = !empty($input['quick']);

        $validator = new Validator();
        if ($isQuick) {
            // Hizli lead: sadece telefon zorunlu
            if (!$validator->validate($input, ['phone' => 'required|min:10'])) {
                Response::error('Dogrulama hatasi', 422, $validator->getErrors());
            }
        } else {
            // Normal lead: tüm alanlar zorunlu
            if (!$validator->validate($input, [
                'tcNo' => 'required|min:11',
                'fullName' => 'required|min:2',
                'birthDate' => 'required',
                'phone' => 'required|min:10',
                'productId' => 'required',
                'sourceId' => 'required',
            ])) {
                Response::error('Dogrulama hatasi', 422, $validator->getErrors());
            }
        }

        // TC Kimlik No format kontrolu (varsa)
        $tcNo = null;
        if (!empty($input['tcNo'])) {
            $tcNo = preg_replace('/\D/', '', $input['tcNo']);
            if (strlen($tcNo) !== 11 && !$isQuick) Response::error('TC Kimlik No 11 haneli olmalidir', 422);
            if (strlen($tcNo) !== 11) $tcNo = null;
            else $input['tcNo'] = $tcNo;
        }

        // Tarih format kontrolu (varsa)
        if (!empty($input['birthDate']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['birthDate'])) {
            Response::error('Dogum tarihi gecersiz (YYYY-MM-DD)', 422);
        }
        if (!empty($input['birthDate'])) {
            $bd = DateTime::createFromFormat('Y-m-d', $input['birthDate']);
            if (!$bd || $bd->format('Y-m-d') !== $input['birthDate']) {
                Response::error('Dogum tarihi gecersiz', 422);
            }
        }

        // Telefon format kontrolu
        $phoneDigits = preg_replace('/\D/', '', $input['phone']);
        if (strlen($phoneDigits) < 10) Response::error('Telefon numarasi en az 10 haneli olmalidir', 422);

        // Atama mantığı
        $rawAssigned = isset($input['assignedTo']) ? (int)$input['assignedTo'] : 0;
        $isPool = ($rawAssigned === -1); // Havuza at

        if ($isPool) {
            $assignedTo = null;
        } elseif ($rawAssigned > 0) {
            $assignedTo = $rawAssigned;
        } elseif ((int)$user['userId'] > 0) {
            // Manuel giriş, seçim yapılmadı → giren kişiye ata
            $assignedTo = (int)$user['userId'];
        } else {
            // Webhook/sistem → round-robin
            $assignedTo = $this->resolveAutoAssign(!empty($input['sourceId']) ? (int)$input['sourceId'] : null);
        }

        // Kendine atadıysa direkt sürece al (DEVAM), değilse ACIK
        $selfAssigned = $assignedTo && (int)$user['userId'] > 0 && $assignedTo === (int)$user['userId'];
        $status = $selfAssigned ? 'DEVAM' : 'ACIK';

        $now = date('Y-m-d H:i:s');
        $id = Database::insert('leads', [
            'source_id' => !empty($input['sourceId']) ? (int)$input['sourceId'] : null,
            'product_id' => !empty($input['productId']) ? (int)$input['productId'] : null,
            'assigned_to' => $assignedTo,
            'assigned_at' => $assignedTo ? $now : null,
            'skipped_users' => null,
            'status' => $status,
            'full_name' => $input['fullName'] ?? null,
            'tc_no' => $input['tcNo'] ?? null,
            'birth_date' => $input['birthDate'] ?? null,
            'phone' => $input['phone'],
            'created_by' => (int)$user['userId'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Aktivite kaydi
        $this->logActivity($id, (int)$user['userId'], 'DURUM', null, null, 'ACIK');
        if ($selfAssigned) {
            $this->logActivity($id, (int)$user['userId'], 'DURUM', null, 'ACIK', 'DEVAM');
        }

        // Bildirimler
        if ($isPool) {
            // Havuza atıldı → tüm aktif satış personellerine bildirim
            $this->notifyPool($id, $input['phone'] ?? '');
        } elseif ($assignedTo && !$selfAssigned) {
            // Başkasına atandı → o kişiye bildirim
            $this->notifyAssignment($assignedTo, $id, $input['fullName'] ?? '', $input['phone'] ?? '');
        }

        Response::success(['id' => $id, 'selfAssigned' => $selfAssigned], 'Lead olusturuldu', 201);
    }

    /**
     * Lead guncelle
     */
    public function update(array $user, int $id, array $input): void
    {
        Permission::require($user, 'leads.manage');

        $existing = Database::fetch("SELECT * FROM leads WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) Response::error('Lead bulunamadi', 404);

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        $changes = [];

        if (isset($input['fullName'])) {
            $val = trim($input['fullName']);
            if (mb_strlen($val) < 2) Response::error('Ad Soyad en az 2 karakter olmalidir', 422);
            $data['full_name'] = $val;
            if ($existing['full_name'] !== $val) $changes[] = 'Ad Soyad';
        }
        if (isset($input['tcNo'])) {
            $val = preg_replace('/\D/', '', $input['tcNo']);
            if (strlen($val) !== 11) Response::error('TC Kimlik No 11 haneli olmalidir', 422);
            $data['tc_no'] = $val;
            if ($existing['tc_no'] !== $val) $changes[] = 'TC No';
        }
        if (isset($input['birthDate'])) {
            $val = $input['birthDate'];
            if ($val && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) Response::error('Dogum tarihi gecersiz (YYYY-MM-DD)', 422);
            if ($val) {
                $d = DateTime::createFromFormat('Y-m-d', $val);
                if (!$d || $d->format('Y-m-d') !== $val) Response::error('Dogum tarihi gecersiz', 422);
            }
            $data['birth_date'] = $val ?: null;
            if ($existing['birth_date'] !== ($val ?: null)) $changes[] = 'Dogum Tarihi';
        }
        if (isset($input['phone'])) {
            $val = preg_replace('/\D/', '', $input['phone']);
            if (strlen($val) < 10) Response::error('Telefon numarasi en az 10 haneli olmalidir', 422);
            $data['phone'] = $input['phone'];
            if ($existing['phone'] !== $input['phone']) $changes[] = 'Telefon';
        }
        if (isset($input['sourceId'])) {
            $data['source_id'] = (int)$input['sourceId'];
            if ((int)$existing['source_id'] !== (int)$input['sourceId']) $changes[] = 'Kaynak';
        }
        if (isset($input['productId'])) {
            $data['product_id'] = (int)$input['productId'];
            if ((int)$existing['product_id'] !== (int)$input['productId']) $changes[] = 'Urun';
        }

        Database::update('leads', $data, 'id = ?', [$id]);

        // Degisiklik varsa aktivite logu
        if (!empty($changes)) {
            $this->logActivity($id, (int)$user['userId'], 'DURUM', 'Duzenlendi: ' . implode(', ', $changes), null, null);
        }

        Response::success(null, 'Lead guncellendi');
    }

    /**
     * Sureci al: ACIK -> DEVAM
     */
    public function startProcess(array $user, int $id): void
    {
        Permission::require($user, 'leads.manage');

        Database::beginTransaction();
        try {
            // SELECT FOR UPDATE ile satırı kilitle
            $lead = Database::fetch("SELECT * FROM leads WHERE id = ? AND deleted_at IS NULL FOR UPDATE", [$id]);
            if (!$lead) {
                Database::rollBack();
                Response::error('Lead bulunamadi', 404);
            }
            if ($lead['status'] !== 'ACIK') {
                Database::rollBack();
                Response::error('Bu lead başka bir temsilci tarafından sürece alındı', 400);
            }
            if ($lead['assigned_to'] && (int)$lead['assigned_to'] !== (int)$user['userId']) {
                Database::rollBack();
                Response::error('Bu lead size atanmamış, süreci alamazsınız', 403);
            }

            Database::update('leads', [
                'status' => 'DEVAM',
                'assigned_to' => (int)$user['userId'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            Response::error('İşlem sırasında hata oluştu', 500);
        }

        $this->logActivity($id, (int)$user['userId'], 'DURUM', null, 'ACIK', 'DEVAM');

        Response::success(null, 'Lead surece alindi');
    }

    /**
     * Sureci kapat: DEVAM -> KAZANILDI veya KAYBEDILDI
     */
    public function closeProcess(array $user, int $id, array $input): void
    {
        Permission::require($user, 'leads.manage');

        $lead = Database::fetch("SELECT * FROM leads WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$lead) Response::error('Lead bulunamadi', 404);
        if ($lead['status'] !== 'DEVAM') Response::error('Bu lead surec asamasinda degil', 400);

        $result = $input['result'] ?? '';
        if (!in_array($result, ['KAZANILDI', 'KAYBEDILDI'])) {
            Response::error('Gecersiz sonuc. KAZANILDI veya KAYBEDILDI olmali', 400);
        }

        $data = [
            'status' => $result,
            'closed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($result === 'KAYBEDILDI') {
            $reason = trim($input['lostReason'] ?? '');
            if (mb_strlen($reason) < 5) {
                Response::error('Kaybedilme nedeni en az 5 karakter olmalidir', 422);
            }
            $data['lost_reason'] = $reason;
        }

        Database::update('leads', $data, 'id = ?', [$id]);

        $content = $result === 'KAYBEDILDI' ? ($input['lostReason'] ?? '') : null;
        $this->logActivity($id, (int)$user['userId'], 'DURUM', $content, 'DEVAM', $result);

        // Kaybedilme nedenini not olarak da kaydet
        if ($result === 'KAYBEDILDI' && !empty($input['lostReason'])) {
            $this->logActivity($id, (int)$user['userId'], 'NOT', 'Satış Tamamlanamadı: ' . $input['lostReason'], null, null);
        }

        Response::success(null, $result === 'KAZANILDI' ? 'Satis tamamlandi' : 'Lead kapatildi');
    }

    /**
     * Kaybedilen lead'i kazanildi olarak guncelle
     */
    public function reopenWon(array $user, int $id): void
    {
        Permission::require($user, 'leads.manage');

        $lead = Database::fetch("SELECT * FROM leads WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$lead) Response::error('Lead bulunamadi', 404);
        if ($lead['status'] !== 'KAYBEDILDI') Response::error('Sadece kaybedilen lead\'ler kazanildi yapilabilir', 400);

        Database::update('leads', [
            'status' => 'KAZANILDI',
            'closed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $this->logActivity($id, (int)$user['userId'], 'DURUM', null, 'KAYBEDILDI', 'KAZANILDI');
        $this->logActivity($id, (int)$user['userId'], 'NOT', 'Müşteri geri döndü - satış tamamlandı', null, null);

        Response::success(null, 'Lead kazanildi olarak guncellendi');
    }

    /**
     * Lead ata (manuel)
     */
    public function assign(array $user, int $id, array $input): void
    {
        Permission::require($user, 'leads.assign');

        $lead = Database::fetch("SELECT * FROM leads WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$lead) Response::error('Lead bulunamadi', 404);

        $newUserId = (int)($input['userId'] ?? 0);
        if (!$newUserId) Response::error('Kullanici secilmedi', 400);

        $oldAssigned = $lead['assigned_to'];
        Database::update('leads', [
            'assigned_to' => $newUserId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $this->logActivity($id, (int)$user['userId'], 'ATAMA', null, (string)$oldAssigned, (string)$newUserId);

        // Atama bildirimi
        $this->notifyAssignment($newUserId, $id, $lead['full_name'] ?? '', $lead['phone'] ?? '');

        Response::success(null, 'Lead atandi');
    }

    /**
     * Lead sil (soft delete)
     */
    public function destroy(array $user, int $id): void
    {
        Permission::require($user, 'leads.delete');
        Database::softDelete('leads', $id);
        Response::success(null, 'Lead silindi');
    }

    /**
     * Lead notlari listele
     */
    public function notes(array $user, int $id): void
    {
        Permission::require($user, 'leads.view');

        $notes = Database::fetchAll(
            "SELECT la.*, u.name as user_name
             FROM lead_activities la
             LEFT JOIN users u ON u.id = la.user_id
             WHERE la.lead_id = ? AND la.type = 'NOT'
             ORDER BY la.created_at DESC",
            [$id]
        );

        $result = array_map(function ($n) {
            return [
                'id' => (int)$n['id'],
                'note' => $n['content'],
                'createdByName' => $n['user_name'] ?? '',
                'createdAt' => $n['created_at'],
            ];
        }, $notes);

        Response::success($result);
    }

    /**
     * Lead not sayilari (toplu)
     */
    public function noteCounts(array $user, array $query): void
    {
        Permission::require($user, 'leads.view');

        $rows = Database::fetchAll(
            "SELECT lead_id, COUNT(*) as cnt FROM lead_activities WHERE type = 'NOT' GROUP BY lead_id"
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[(int)$r['lead_id']] = (int)$r['cnt'];
        }

        Response::success($counts);
    }

    /**
     * Lead'e not ekle
     */
    public function addNote(array $user, int $id, array $input): void
    {
        Permission::require($user, 'leads.manage');

        $lead = Database::fetch("SELECT id FROM leads WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$lead) Response::error('Lead bulunamadi', 404);

        $note = trim($input['note'] ?? '');
        if (!$note) Response::error('Not icerigi zorunludur', 422);

        $noteId = Database::insert('lead_activities', [
            'lead_id' => $id,
            'user_id' => (int)$user['userId'],
            'type' => 'NOT',
            'content' => $note,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $userName = Database::fetch("SELECT name FROM users WHERE id = ?", [(int)$user['userId']]);

        Response::success([
            'id' => $noteId,
            'note' => $note,
            'createdByName' => $userName['name'] ?? '',
            'createdAt' => date('Y-m-d H:i:s'),
        ], 'Not eklendi', 201);
    }

    /**
     * Lead notu sil
     */
    public function deleteNote(array $user, int $id, int $noteId): void
    {
        Permission::require($user, 'leads.manage');

        $note = Database::fetch("SELECT id FROM lead_activities WHERE id = ? AND lead_id = ? AND type = 'NOT'", [$noteId, $id]);
        if (!$note) Response::error('Not bulunamadi', 404);

        Database::query("DELETE FROM lead_activities WHERE id = ?", [$noteId]);
        Response::success(null, 'Not silindi');
    }

    /**
     * Lead'e dosya yukle (multipart/form-data)
     */
    public function uploadFile(array $user, int $id): void
    {
        Permission::require($user, 'leads.manage');

        $lead = Database::fetch("SELECT id FROM leads WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$lead) Response::error('Lead bulunamadi', 404);

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Dosya yuklenemedi', 400);
        }

        $file = $_FILES['file'];
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            Response::error('Dosya boyutu 10MB\'dan buyuk olamaz', 400);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $mime = mime_content_type($file['tmp_name']) ?: $file['type'];
        if (!in_array($mime, $allowed)) {
            Response::error('Sadece JPG, PNG, WebP ve PDF dosyalari yuklenebilir', 400);
        }

        $uploadDir = __DIR__ . '/../uploads/leads/' . $id;
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Response::error('Dosya kaydedilemedi', 500);
        }

        $fileId = Database::insert('lead_files', [
            'lead_id' => $id,
            'original_name' => $file['name'],
            'file_path' => 'leads/' . $id . '/' . $filename,
            'file_size' => $file['size'],
            'mime_type' => $mime,
            'uploaded_by' => (int)$user['userId'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success([
            'id' => $fileId,
            'originalName' => $file['name'],
            'fileSize' => $file['size'],
            'mimeType' => $mime,
        ], 'Dosya yuklendi', 201);
    }

    /**
     * Lead dosyalarini listele
     */
    public function files(array $user, int $id): void
    {
        Permission::require($user, 'leads.view');

        $files = Database::fetchAll(
            "SELECT lf.*, u.name as uploaded_by_name
             FROM lead_files lf
             LEFT JOIN users u ON u.id = lf.uploaded_by
             WHERE lf.lead_id = ?
             ORDER BY lf.created_at DESC",
            [$id]
        );

        $result = array_map(function ($f) {
            return [
                'id' => (int)$f['id'],
                'originalName' => $f['original_name'],
                'filePath' => $f['file_path'],
                'fileSize' => (int)$f['file_size'],
                'mimeType' => $f['mime_type'],
                'uploadedBy' => $f['uploaded_by_name'] ?? '',
                'createdAt' => $f['created_at'],
            ];
        }, $files);

        Response::success($result);
    }

    /**
     * Lead dosyasi sil
     */
    public function deleteFile(array $user, int $id, int $fileId): void
    {
        Permission::require($user, 'leads.manage');

        $file = Database::fetch("SELECT * FROM lead_files WHERE id = ? AND lead_id = ?", [$fileId, $id]);
        if (!$file) Response::error('Dosya bulunamadi', 404);

        $fullPath = __DIR__ . '/../uploads/' . $file['file_path'];
        if (file_exists($fullPath)) @unlink($fullPath);

        Database::query("DELETE FROM lead_files WHERE id = ?", [$fileId]);
        Response::success(null, 'Dosya silindi');
    }

    /**
     * Tab sayilari
     */
    public function counts(array $user): void
    {
        Permission::require($user, 'leads.view');
        Response::success($this->getTabCounts($user));
    }

    // ─── Yardimci metodlar ───

    private function getTabCounts(array $user): array
    {
        $canViewAll = Permission::has($user, 'leads.view_all');
        $base = "FROM leads WHERE deleted_at IS NULL";
        $filter = '';
        $params = [];
        if (!$canViewAll) {
            $filter = " AND (assigned_to = ? OR assigned_to IS NULL)";
            $params = [(int)$user['userId']];
        }
        return [
            'acik' => (int)Database::fetch("SELECT COUNT(*) c $base AND status = 'ACIK'" . $filter, $params)['c'],
            'devam' => (int)Database::fetch("SELECT COUNT(*) c $base AND status = 'DEVAM'" . $filter, $params)['c'],
            'kapatilan' => (int)Database::fetch("SELECT COUNT(*) c $base AND status IN ('KAZANILDI','KAYBEDILDI')" . $filter, $params)['c'],
            'kazanildi' => (int)Database::fetch("SELECT COUNT(*) c $base AND status = 'KAZANILDI'" . $filter, $params)['c'],
            'kaybedildi' => (int)Database::fetch("SELECT COUNT(*) c $base AND status = 'KAYBEDILDI'" . $filter, $params)['c'],
        ];
    }

    private function logActivity(int $leadId, int $userId, string $type, ?string $content, ?string $oldVal, ?string $newVal): void
    {
        Database::insert('lead_activities', [
            'lead_id' => $leadId,
            'user_id' => $userId,
            'type' => $type,
            'content' => $content,
            'old_value' => $oldVal,
            'new_value' => $newVal,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function notifyAssignment(int $userId, int $leadId, string $name, string $phone): void
    {
        if (!class_exists('NotificationController')) {
            require_once __DIR__ . '/NotificationController.php';
        }
        $label = $name ?: $phone;
        NotificationController::create(
            $userId,
            'Lead Ataması Yapıldı',
            "Yeni bir lead size atandı: {$label}",
            '/leadler',
            'lead_assigned'
        );
    }

    private function notifyPool(int $leadId, string $phone): void
    {
        if (!class_exists('NotificationController')) {
            require_once __DIR__ . '/NotificationController.php';
        }
        $salesUsers = Database::fetchAll(
            "SELECT id FROM users WHERE is_sales_rep = 1 AND is_active = 1 AND deleted_at IS NULL"
        );
        foreach ($salesUsers as $u) {
            NotificationController::create(
                (int)$u['id'],
                'Havuza Yeni Lead Düştü',
                'Havuzda yeni bir lead bekliyor. Görevi alınız.',
                '/leadler',
                'lead_pool'
            );
        }
    }

    private function resolveAutoAssign(?int $sourceId): ?int
    {
        // Kaynağın otomatik atama ayarını kontrol et
        if ($sourceId) {
            $source = Database::fetch("SELECT auto_assign_to FROM lead_sources WHERE id = ? AND deleted_at IS NULL", [$sourceId]);
            // auto_assign_to NULL ise otomatik atama kapalı demek
            if (!$source || !$source['auto_assign_to']) return null;
        }

        // Round-robin: en az açık lead'i olan aktif satış personeline ata
        $user = Database::fetch(
            "SELECT u.id
             FROM users u
             WHERE u.is_sales_rep = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
             ORDER BY (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id AND l.status IN ('ACIK','DEVAM') AND l.deleted_at IS NULL) ASC
             LIMIT 1"
        );
        return $user ? (int)$user['id'] : null;
    }

    private function format(array $r): array
    {
        $assigned = null;
        if (!empty($r['assigned_name'])) {
            $parts = explode(' ', trim($r['assigned_name']));
            if (count($parts) >= 2) {
                $first = mb_substr($parts[0], 0, 1, 'UTF-8') . '.';
                $last = end($parts);
                $assigned = mb_strtoupper($first, 'UTF-8') . ' ' . mb_convert_case($last, MB_CASE_TITLE, 'UTF-8');
            } else {
                $assigned = $r['assigned_name'];
            }
        }

        return [
            'id' => (int)$r['id'],
            'sourceId' => $r['source_id'] ? (int)$r['source_id'] : null,
            'sourceName' => $r['source_name'] ?? null,
            'sourceColor' => $r['source_color'] ?? null,
            'productId' => $r['product_id'] ? (int)$r['product_id'] : null,
            'productName' => $r['product_name'] ?? null,
            'productColor' => $r['product_color'] ?? null,
            'assignedTo' => $r['assigned_to'] ? (int)$r['assigned_to'] : null,
            'assignedName' => $assigned,
            'status' => $r['status'],
            'fullName' => $r['full_name'],
            'tcNo' => $r['tc_no'],
            'birthDate' => $r['birth_date'],
            'phone' => $r['phone'],
            'lostReason' => $r['lost_reason'] ?? null,
            'closedAt' => $r['closed_at'] ?? null,
            'assignedAt' => $r['assigned_at'] ?? null,
            'createdAt' => $r['created_at'],
        ];
    }

    // ─── WEBHOOK: Dis kaynaklardan lead alma ───

    /**
     * Webhook endpoint: Dis sitelerden lead olustur
     * POST /api/leads/webhook
     * Header: X-Webhook-Key: {api_key}
     * Body: { eventId, fullName, tcNo, birthDate, phone, product, source }
     */
    public function webhook(array $input): void
    {
        // API key kontrolu
        $apiKey = $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? '';
        $savedKey = Database::fetch("SELECT value FROM settings WHERE `key` = 'webhook_api_key'");
        if (!$savedKey || !$savedKey['value'] || !hash_equals($savedKey['value'], $apiKey)) {
            Response::error('Gecersiz API anahtari', 401);
        }

        // Zorunlu alan kontrolu
        $phone = trim($input['phone'] ?? '');
        if (!$phone) Response::error('Telefon numarasi zorunludur', 422);

        // Telefon format kontrolu
        $phoneDigits = preg_replace('/\D/', '', $phone);
        if (strlen($phoneDigits) < 10) Response::error('Telefon numarasi en az 10 haneli olmalidir', 422);

        // Event ID idempotency kontrolu
        $eventId = trim($input['eventId'] ?? '');
        if ($eventId) {
            $existing = Database::fetch(
                "SELECT id FROM leads WHERE webhook_event_id = ?",
                [$eventId]
            );
            if ($existing) {
                Response::json([
                    'success' => true,
                    'message' => 'Bu event zaten islendi',
                    'duplicate' => true,
                    'leadId' => (int)$existing['id'],
                ], 200);
                return;
            }
        }

        // TC Kimlik No format kontrolu (opsiyonel)
        $tcNo = null;
        if (!empty($input['tcNo'])) {
            $tcNo = preg_replace('/\D/', '', $input['tcNo']);
            if (strlen($tcNo) !== 11) $tcNo = null;
        }

        // Dogum tarihi format kontrolu (opsiyonel)
        $birthDate = null;
        if (!empty($input['birthDate'])) {
            $bd = $input['birthDate'];
            // DD.MM.YYYY formatini da kabul et
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $bd, $m)) {
                $bd = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd)) {
                $d = DateTime::createFromFormat('Y-m-d', $bd);
                if ($d && $d->format('Y-m-d') === $bd) $birthDate = $bd;
            }
        }

        // Kaynak eslestir (isim veya ID)
        $sourceId = null;
        if (!empty($input['sourceId'])) {
            $sourceId = (int)$input['sourceId'];
        } elseif (!empty($input['source'])) {
            $src = Database::fetch(
                "SELECT id FROM lead_sources WHERE name = ? AND is_active = 1 AND deleted_at IS NULL",
                [trim($input['source'])]
            );
            if ($src) $sourceId = (int)$src['id'];
        }

        // Urun eslestir (isim veya ID)
        $productId = null;
        if (!empty($input['productId'])) {
            $productId = (int)$input['productId'];
        } elseif (!empty($input['product'])) {
            $prd = Database::fetch(
                "SELECT id FROM lead_products WHERE name = ? AND is_active = 1 AND deleted_at IS NULL",
                [trim($input['product'])]
            );
            if ($prd) $productId = (int)$prd['id'];
        }

        // Webhook kaynak domain
        $webhookSource = trim($input['webhookSource'] ?? $input['source'] ?? '');

        // Otomatik atama
        $assignedTo = $this->resolveAutoAssign($sourceId);
        $now = date('Y-m-d H:i:s');

        // Transaction ile lead olustur (race condition onlemi)
        Database::beginTransaction();
        try {
            // Event ID varsa tekrar kontrol (race condition)
            if ($eventId) {
                $recheck = Database::fetch(
                    "SELECT id FROM leads WHERE webhook_event_id = ? FOR UPDATE",
                    [$eventId]
                );
                if ($recheck) {
                    Database::commit();
                    Response::json([
                        'success' => true,
                        'message' => 'Bu event zaten islendi',
                        'duplicate' => true,
                        'leadId' => (int)$recheck['id'],
                    ], 200);
                    return;
                }
            }

            $id = Database::insert('leads', [
                'source_id' => $sourceId,
                'product_id' => $productId,
                'assigned_to' => $assignedTo,
                'assigned_at' => $assignedTo ? $now : null,
                'skipped_users' => null,
                'status' => 'ACIK',
                'full_name' => trim($input['fullName'] ?? '') ?: null,
                'tc_no' => $tcNo,
                'birth_date' => $birthDate,
                'phone' => $phone,
                'webhook_event_id' => $eventId ?: null,
                'webhook_source' => $webhookSource ?: null,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            // UNIQUE constraint hatasi: ayni event_id
            if (str_contains($e->getMessage(), 'Duplicate entry') && $eventId) {
                $dup = Database::fetch("SELECT id FROM leads WHERE webhook_event_id = ?", [$eventId]);
                Response::json([
                    'success' => true,
                    'message' => 'Bu event zaten islendi',
                    'duplicate' => true,
                    'leadId' => $dup ? (int)$dup['id'] : null,
                ], 200);
                return;
            }
            Response::error('Lead olusturulamadi: ' . $e->getMessage(), 500);
        }

        // Aktivite kaydi
        $this->logActivity($id, 0, 'DURUM', 'Webhook ile olusturuldu (' . ($webhookSource ?: 'bilinmeyen') . ')', null, 'ACIK');

        // Atama bildirimi
        if ($assignedTo) {
            $this->notifyAssignment($assignedTo, $id, trim($input['fullName'] ?? '') ?: '', $phone);
        }

        Response::json([
            'success' => true,
            'message' => 'Lead olusturuldu',
            'duplicate' => false,
            'leadId' => $id,
        ], 201);
    }

    // ─── CRON: Otomatik yeniden atama ───

    /**
     * Cron endpoint: suresi dolan lead'leri yeniden ata
     * GET /api/leads/cron?key=CRON_SECRET
     */
    public function cron(array $query): void
    {
        // Guvenlik: CRON_SECRET config'den okunur, hardcoded fallback yok
        if (!defined('CRON_SECRET') || !CRON_SECRET) {
            Response::error('CRON_SECRET tanimlanmamis', 500);
        }
        if (!hash_equals(CRON_SECRET, $query['key'] ?? '')) {
            Response::error('Yetkisiz', 401);
        }

        // Simdi mesai saati mi?
        if (!$this->isWorkingTime()) {
            Response::success(['reassigned' => 0, 'reason' => 'Mesai saati disinda']);
            return;
        }

        // Timeout suresini al (dakika)
        $setting = Database::fetch("SELECT value FROM settings WHERE `key` = 'lead_reassign_timeout'");
        $timeoutMinutes = $setting ? (int)$setting['value'] : 30;

        // Tatilleri bir kez yukle (N+1 query onlemi)
        $holidayRows = Database::fetchAll("SELECT date FROM lead_holidays");
        $holidays = [];
        foreach ($holidayRows as $r) $holidays[$r['date']] = true;

        // ACIK durumda, atanmis, suresi dolmus lead'leri bul
        $leads = Database::fetchAll(
            "SELECT l.* FROM leads l
             WHERE l.status = 'ACIK'
               AND l.assigned_to IS NOT NULL
               AND l.assigned_at IS NOT NULL
               AND l.deleted_at IS NULL"
        );

        $reassigned = 0;
        foreach ($leads as $lead) {
            // Mesai dakikasi hesapla (onceden yuklenmis tatillerle)
            $elapsed = $this->calcWorkingMinutes($lead['assigned_at'], $holidays);
            if ($elapsed < $timeoutMinutes) continue;

            // Yeniden ata
            $this->reassignLead($lead);
            $reassigned++;
        }

        Response::success(['reassigned' => $reassigned]);
    }

    /**
     * Lead'i bir sonraki satıs personeline ata
     */
    private function reassignLead(array $lead): void
    {
        $currentUserId = (int)$lead['assigned_to'];
        $skipped = json_decode($lead['skipped_users'] ?? '[]', true) ?: [];
        $skipped[] = $currentUserId;
        $skipped = array_unique($skipped);

        // Aktif satis personellerini al
        $salesUsers = Database::fetchAll(
            "SELECT id FROM users WHERE is_sales_rep = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id ASC"
        );
        $allIds = array_column($salesUsers, 'id');

        if (empty($allIds)) return;

        // Denenmemis personel bul
        $available = array_diff($allIds, $skipped);

        // Herkes denendiyse basa don
        if (empty($available)) {
            $skipped = [];
            $available = $allIds;
        }

        // Round-robin: en az acik lead'i olan kisiye ata
        $placeholders = implode(',', array_fill(0, count($available), '?'));
        $nextUser = Database::fetch(
            "SELECT u.id
             FROM users u
             WHERE u.id IN ($placeholders)
             ORDER BY (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id AND l.status IN ('ACIK','DEVAM') AND l.deleted_at IS NULL) ASC
             LIMIT 1",
            array_values($available)
        );

        if (!$nextUser) return;

        $newUserId = (int)$nextUser['id'];
        $now = date('Y-m-d H:i:s');

        Database::update('leads', [
            'assigned_to' => $newUserId,
            'assigned_at' => $now,
            'skipped_users' => json_encode(array_values($skipped)),
            'updated_at' => $now,
        ], 'id = ?', [(int)$lead['id']]);

        // Aktivite kaydi
        $this->logActivity(
            (int)$lead['id'],
            0, // sistem
            'ATAMA',
            'Süre aşımı - otomatik yeniden atama',
            (string)$currentUserId,
            (string)$newUserId
        );

        // Atama bildirimi
        $this->notifyAssignment($newUserId, (int)$lead['id'], $lead['full_name'] ?? '', $lead['phone'] ?? '');
    }

    /**
     * Simdi mesai saati mi? (Hafta ici, tatil degil, 09:00-12:30 veya 13:30-18:00)
     */
    private function isWorkingTime(): bool
    {
        $now = new DateTime('now', new DateTimeZone('Europe/Istanbul'));

        // Hafta sonu kontrolu (6=Cumartesi, 0=Pazar)
        $dow = (int)$now->format('w');
        if ($dow === 0 || $dow === 6) return false;

        // Resmi tatil kontrolu
        $today = $now->format('Y-m-d');
        $holiday = Database::fetch("SELECT id FROM lead_holidays WHERE date = ?", [$today]);
        if ($holiday) return false;

        // Mesai saati kontrolu
        $time = $now->format('H:i');
        $inMorning = ($time >= '09:00' && $time <= '12:30');
        $inAfternoon = ($time >= '13:30' && $time <= '18:00');

        return $inMorning || $inAfternoon;
    }

    /**
     * Bir tarihten simdi'ye kadar gecen mesai dakikasi hesapla
     * Mesai: 09:00-12:30 (210dk) + 13:30-18:00 (270dk) = 480dk/gun
     * Hafta sonu + tatiller haric
     */
    private function calcWorkingMinutes(string $fromDatetime, ?array $holidays = null): int
    {
        $tz = new DateTimeZone('Europe/Istanbul');
        $from = new DateTime($fromDatetime, $tz);
        $now = new DateTime('now', $tz);

        if ($now <= $from) return 0;

        // Tatiller parametre olarak gelmediyse DB'den cek
        if ($holidays === null) {
            $holidays = [];
            $rows = Database::fetchAll("SELECT date FROM lead_holidays");
            foreach ($rows as $r) $holidays[$r['date']] = true;
        }

        $totalMinutes = 0;
        $current = clone $from;

        // Gun gun hesapla
        while ($current < $now) {
            $dow = (int)$current->format('w');
            $dateStr = $current->format('Y-m-d');

            // Hafta sonu veya tatil atla
            if ($dow === 0 || $dow === 6 || isset($holidays[$dateStr])) {
                $current->modify('+1 day');
                $current->setTime(9, 0);
                continue;
            }

            $dayStart = clone $current;
            $dayEnd = clone $current;

            // Ayni gun mu?
            $sameStartDay = ($current->format('Y-m-d') === $from->format('Y-m-d'));
            $sameEndDay = ($current->format('Y-m-d') === $now->format('Y-m-d'));

            // Sabah dilimi: 09:00-12:30
            $morningStart = clone $dayStart;
            $morningStart->setTime(9, 0);
            $morningEnd = clone $dayStart;
            $morningEnd->setTime(12, 30);

            $mStart = clone $morningStart;
            $mEnd = clone $morningEnd;

            if ($sameStartDay && $from > $mStart) $mStart = clone $from;
            if ($sameEndDay && $now < $mEnd) $mEnd = clone $now;

            if ($mStart < $mEnd && $mStart < $morningEnd && $mEnd > $morningStart) {
                // Sinirlari duzelt
                if ($mStart < $morningStart) $mStart = clone $morningStart;
                if ($mEnd > $morningEnd) $mEnd = clone $morningEnd;
                if ($mStart < $mEnd) {
                    $totalMinutes += (int)(($mEnd->getTimestamp() - $mStart->getTimestamp()) / 60);
                }
            }

            // Ogleden sonra dilimi: 13:30-18:00
            $afternoonStart = clone $dayStart;
            $afternoonStart->setTime(13, 30);
            $afternoonEnd = clone $dayStart;
            $afternoonEnd->setTime(18, 0);

            $aStart = clone $afternoonStart;
            $aEnd = clone $afternoonEnd;

            if ($sameStartDay && $from > $aStart) $aStart = clone $from;
            if ($sameEndDay && $now < $aEnd) $aEnd = clone $now;

            if ($aStart < $aEnd && $aStart < $afternoonEnd && $aEnd > $afternoonStart) {
                if ($aStart < $afternoonStart) $aStart = clone $afternoonStart;
                if ($aEnd > $afternoonEnd) $aEnd = clone $afternoonEnd;
                if ($aStart < $aEnd) {
                    $totalMinutes += (int)(($aEnd->getTimestamp() - $aStart->getTimestamp()) / 60);
                }
            }

            $current->modify('+1 day');
            $current->setTime(9, 0);
        }

        return $totalMinutes;
    }
}
