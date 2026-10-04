<?php

/**
 * Personel Onboarding & Sözleşme Controller
 *
 * Akış:
 * 1. Profil tamamlama (TC, doğum tarihi, şirket tel, adres, kişisel email)
 * 2. KVKK onayı + SMS doğrulama
 * 3. Kimlik yükleme (ön + arka)
 * 4. Taahhütname onayı + SMS doğrulama
 */

class OnboardingController
{
    /**
     * Onboarding durumunu getir
     * GET /api/onboarding/status
     */
    public function status(array $user): void
    {
        $u = Database::fetch(
            "SELECT id, name, email, phone, personal_phone, company_phone, personal_email,
                    tc_no, birth_date, address, identity_front, identity_back,
                    onboarding_completed, two_factor_enabled
             FROM users WHERE id = ?",
            [$user['userId']]
        );

        if (!$u) {
            Response::error('Kullanıcı bulunamadı', 404);
            return;
        }

        // Hangi adımlar tamamlanmış
        $profileComplete = !empty($u['tc_no']) && !empty($u['birth_date'])
                        && !empty($u['company_phone']) && !empty($u['address'])
                        && !empty($u['personal_email']);

        $kvkkAccepted = $this->hasActiveAgreement((int)$u['id'], 'KVKK');
        $identityUploaded = !empty($u['identity_front']) && !empty($u['identity_back']);
        $commitmentAccepted = $this->hasActiveAgreement((int)$u['id'], 'COMMITMENT');

        // Mevcut adımı belirle
        $currentStep = 'profile';
        if ($profileComplete) $currentStep = 'kvkk';
        if ($profileComplete && $kvkkAccepted) $currentStep = 'identity';
        if ($profileComplete && $kvkkAccepted && $identityUploaded) $currentStep = 'commitment';
        if ($profileComplete && $kvkkAccepted && $identityUploaded && $commitmentAccepted) $currentStep = 'completed';

        Response::success([
            'completed'    => $u['onboarding_completed'] == 1,
            'currentStep'  => $currentStep,
            'steps'        => [
                'profile'    => $profileComplete,
                'kvkk'       => $kvkkAccepted,
                'identity'   => $identityUploaded,
                'commitment' => $commitmentAccepted,
            ],
            'user' => [
                'name'          => $u['name'],
                'email'         => $u['email'],
                'phone'         => $u['phone'],
                'personalPhone' => $u['personal_phone'],
                'companyPhone'  => $u['company_phone'],
                'personalEmail' => $u['personal_email'],
                'tcNo'          => $u['tc_no'],
                'birthDate'     => $u['birth_date'],
                'address'       => $u['address'],
                'identityFront' => $u['identity_front'],
                'identityBack'  => $u['identity_back'],
                'twoFactorEnabled' => (bool) $u['two_factor_enabled'],
            ],
        ]);
    }

    /**
     * Profil tamamlama
     * POST /api/onboarding/profile
     */
    public function saveProfile(array $user, array $input): void
    {
        $validator = new Validator();
        $rules = [
            'tcNo'          => 'required|min:11|max:11',
            'birthDate'     => 'required',
            'companyPhone'  => 'required|min:10',
            'personalEmail' => 'required|email',
            'address'       => 'required|min:10',
        ];

        if (!$validator->validate($input, $rules)) {
            Response::error('Doğrulama hatası', 422, $validator->getErrors());
            return;
        }

        Database::query(
            "UPDATE users SET
                tc_no = ?, birth_date = ?, company_phone = ?,
                personal_email = ?, address = ?
             WHERE id = ?",
            [
                $input['tcNo'],
                $input['birthDate'],
                $input['companyPhone'],
                $input['personalEmail'],
                $input['address'],
                $user['userId'],
            ]
        );

        Response::success(['message' => 'Profil güncellendi']);
    }

    /**
     * Aktif belge içeriğini getir
     * GET /api/onboarding/agreement/:type
     */
    public function getAgreement(string $type): void
    {
        $type = strtoupper($type);
        if (!in_array($type, ['KVKK', 'COMMITMENT'])) {
            Response::error('Geçersiz belge türü', 400);
            return;
        }

        $doc = Database::fetch(
            "SELECT id, type, version, title, content FROM agreement_documents
             WHERE type = ? AND is_active = 1 ORDER BY id DESC LIMIT 1",
            [$type]
        );

        if (!$doc) {
            Response::error('Belge bulunamadı', 404);
            return;
        }

        Response::success([
            'id'      => (int) $doc['id'],
            'type'    => $doc['type'],
            'version' => $doc['version'],
            'title'   => $doc['title'],
            'content' => $doc['content'],
        ]);
    }

    /**
     * Sözleşme onayı + SMS gönder
     * POST /api/onboarding/agreement/:type/accept
     */
    public function acceptAgreement(array $user, string $type): void
    {
        $type = strtoupper($type);
        if (!in_array($type, ['KVKK', 'COMMITMENT'])) {
            Response::error('Geçersiz belge türü', 400);
            return;
        }

        $u = Database::fetch(
            "SELECT personal_phone FROM users WHERE id = ?",
            [$user['userId']]
        );

        $phone = $u['personal_phone'] ?? '';
        if (empty($phone)) {
            Response::error('Kişisel telefon numarası bulunamadı', 400);
            return;
        }

        // Aktif belge
        $doc = Database::fetch(
            "SELECT id, version FROM agreement_documents WHERE type = ? AND is_active = 1 ORDER BY id DESC LIMIT 1",
            [$type]
        );
        if (!$doc) {
            Response::error('Aktif belge bulunamadı', 404);
            return;
        }

        // Zaten onaylamış mı
        $existing = Database::fetch(
            "SELECT id FROM user_agreements WHERE user_id = ? AND agreement_type = ? AND agreement_version = ? AND sms_verified = 1",
            [$user['userId'], $type, $doc['version']]
        );
        if ($existing) {
            Response::success(['message' => 'Zaten onaylanmış', 'alreadyAccepted' => true]);
            return;
        }

        // SMS kodu oluştur
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        // Eski kodları geçersiz kıl
        Database::query(
            "UPDATE agreement_sms_codes SET used = 1 WHERE user_id = ? AND type = ? AND used = 0",
            [$user['userId'], $type]
        );

        // Yeni kod kaydet
        Database::query(
            "INSERT INTO agreement_sms_codes (user_id, phone, code, type, expires_at) VALUES (?, ?, ?, ?, ?)",
            [$user['userId'], $phone, $code, $type, $expiresAt]
        );

        // SMS gönder
        $label = $type === 'KVKK' ? 'KVKK Onay' : 'Taahhütname Onay';
        $message = "ADK Vip Sigorta - {$label} dogrulama kodunuz: {$code} (5 dakika gecerli)";
        $smsResult = NetgsmSms::send($phone, $message);

        // Onay kaydı (SMS doğrulanmadan)
        Database::query(
            "INSERT INTO user_agreements (user_id, agreement_type, agreement_version, agreement_doc_id, accepted_at, ip_address, user_agent, sms_phone)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)",
            [
                $user['userId'],
                $type,
                $doc['version'],
                (int) $doc['id'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $phone,
            ]
        );

        $maskedPhone = substr($phone, 0, -4) . '****';
        Response::success([
            'message'     => "Doğrulama kodu {$maskedPhone} numarasına gönderildi",
            'maskedPhone' => $maskedPhone,
            'smsSent'     => !empty($smsResult['success']),
        ]);
    }

    /**
     * SMS doğrulama
     * POST /api/onboarding/agreement/:type/verify
     */
    public function verifyAgreement(array $user, string $type, array $input): void
    {
        $type = strtoupper($type);
        $code = trim($input['code'] ?? '');

        if (strlen($code) !== 6) {
            Response::error('Geçersiz doğrulama kodu', 400);
            return;
        }

        // Aktif kodu bul
        $smsCode = Database::fetch(
            "SELECT id, code, attempts, expires_at FROM agreement_sms_codes
             WHERE user_id = ? AND type = ? AND used = 0
             ORDER BY id DESC LIMIT 1",
            [$user['userId'], $type]
        );

        if (!$smsCode) {
            Response::error('Doğrulama kodu bulunamadı. Yeniden onay verin.', 400);
            return;
        }

        // Süre kontrolü
        if (strtotime($smsCode['expires_at']) < time()) {
            Database::query("UPDATE agreement_sms_codes SET used = 1 WHERE id = ?", [$smsCode['id']]);
            Response::error('Doğrulama kodunun süresi dolmuş. Yeniden onay verin.', 400);
            return;
        }

        // Max deneme
        if ((int)$smsCode['attempts'] >= 5) {
            Database::query("UPDATE agreement_sms_codes SET used = 1 WHERE id = ?", [$smsCode['id']]);
            Response::error('Çok fazla deneme. Yeniden onay verin.', 429);
            return;
        }

        // Kod doğrula
        Database::query(
            "UPDATE agreement_sms_codes SET attempts = attempts + 1 WHERE id = ?",
            [$smsCode['id']]
        );

        if ($smsCode['code'] !== $code) {
            $remaining = 5 - (int)$smsCode['attempts'] - 1;
            Response::error("Yanlış kod. {$remaining} deneme hakkınız kaldı.", 400);
            return;
        }

        // Başarılı — kodu kullanıldı işaretle
        Database::query("UPDATE agreement_sms_codes SET used = 1 WHERE id = ?", [$smsCode['id']]);

        // Son onay kaydını güncelle
        Database::query(
            "UPDATE user_agreements SET sms_verified = 1, sms_verified_at = NOW()
             WHERE user_id = ? AND agreement_type = ? AND sms_verified = 0
             ORDER BY id DESC LIMIT 1",
            [$user['userId'], $type]
        );

        // Onboarding tamamlanma kontrolü
        $this->checkOnboardingComplete((int)$user['userId']);

        Response::success(['message' => 'Doğrulama başarılı', 'verified' => true]);
    }

    /**
     * Kimlik yükleme
     * POST /api/onboarding/identity
     */
    public function uploadIdentity(array $user): void
    {
        $side = $_POST['side'] ?? '';
        if (!in_array($side, ['front', 'back'])) {
            Response::error('Geçersiz yüz (front/back)', 400);
            return;
        }

        if (empty($_FILES['file'])) {
            Response::error('Dosya yüklenmedi', 400);
            return;
        }

        $file = $_FILES['file'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

        if ($file['size'] > $maxSize) {
            Response::error('Dosya 5MB\'dan büyük olamaz', 400);
            return;
        }

        if (!in_array($file['type'], $allowedTypes)) {
            Response::error('Yalnızca JPG, PNG veya WebP formatı kabul edilir', 400);
            return;
        }

        // Dosya adı: user_ID_front/back_timestamp.ext
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = "identity_{$user['userId']}_{$side}_" . time() . ".{$ext}";
        $uploadDir = __DIR__ . '/../uploads/identity/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $targetPath = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Response::error('Dosya yüklenemedi', 500);
            return;
        }

        // DB güncelle
        $column = $side === 'front' ? 'identity_front' : 'identity_back';
        Database::query(
            "UPDATE users SET {$column} = ? WHERE id = ?",
            [$filename, $user['userId']]
        );

        $this->checkOnboardingComplete((int)$user['userId']);

        Response::success([
            'message'  => ($side === 'front' ? 'Ön yüz' : 'Arka yüz') . ' yüklendi',
            'filename' => $filename,
        ]);
    }

    /**
     * Admin: Kullanıcının onboarding bilgilerini getir
     * GET /api/onboarding/admin/:userId
     */
    public function adminUserDetail(array $adminUser, int $userId): void
    {
        if ((int)$adminUser['role'] !== 1) {
            Response::error('Yetkisiz', 403);
            return;
        }

        $u = Database::fetch(
            "SELECT id, name, email, phone, personal_phone, company_phone, personal_email,
                    tc_no, birth_date, address, identity_front, identity_back,
                    onboarding_completed, created_at
             FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$u) {
            Response::error('Kullanıcı bulunamadı', 404);
            return;
        }

        // Onay kayıtları
        $agreements = Database::fetchAll(
            "SELECT ua.*, ad.title as doc_title
             FROM user_agreements ua
             LEFT JOIN agreement_documents ad ON ua.agreement_doc_id = ad.id
             WHERE ua.user_id = ? AND ua.sms_verified = 1
             ORDER BY ua.accepted_at DESC",
            [$userId]
        );

        Response::success([
            'user' => [
                'id'               => (int) $u['id'],
                'name'             => $u['name'],
                'email'            => $u['email'],
                'phone'            => $u['phone'],
                'personalPhone'    => $u['personal_phone'],
                'companyPhone'     => $u['company_phone'],
                'personalEmail'    => $u['personal_email'],
                'tcNo'             => $u['tc_no'],
                'birthDate'        => $u['birth_date'],
                'address'          => $u['address'],
                'identityFront'    => $u['identity_front'],
                'identityBack'     => $u['identity_back'],
                'onboardingCompleted' => (bool) $u['onboarding_completed'],
                'createdAt'        => $u['created_at'],
            ],
            'agreements' => array_map(fn($a) => [
                'type'        => $a['agreement_type'],
                'version'     => $a['agreement_version'],
                'title'       => $a['doc_title'],
                'acceptedAt'  => $a['accepted_at'],
                'ipAddress'   => $a['ip_address'],
                'smsPhone'    => $a['sms_phone'],
                'smsVerified' => (bool) $a['sms_verified'],
                'verifiedAt'  => $a['sms_verified_at'],
                'userAgent'   => $a['user_agent'],
            ], $agreements),
        ]);
    }

    /**
     * Admin: Kimlik görüntüleme
     * GET /api/onboarding/identity/:userId/:side
     */
    public function serveIdentity(array $adminUser, int $userId, string $side): void
    {
        if ((int)$adminUser['role'] !== 1) {
            Response::error('Yetkisiz', 403);
            return;
        }

        $column = $side === 'front' ? 'identity_front' : 'identity_back';
        $u = Database::fetch("SELECT {$column} FROM users WHERE id = ?", [$userId]);

        if (!$u || empty($u[$column])) {
            Response::error('Dosya bulunamadı', 404);
            return;
        }

        $filePath = __DIR__ . '/../uploads/identity/' . $u[$column];
        if (!file_exists($filePath)) {
            Response::error('Dosya bulunamadı', 404);
            return;
        }

        $mime = mime_content_type($filePath) ?: 'image/jpeg';
        header("Content-Type: {$mime}");
        header("Content-Length: " . filesize($filePath));
        header("Cache-Control: private, max-age=3600");
        readfile($filePath);
        exit;
    }

    // ── Yardımcılar ──

    private function hasActiveAgreement(int $userId, string $type): bool
    {
        $doc = Database::fetch(
            "SELECT version FROM agreement_documents WHERE type = ? AND is_active = 1 ORDER BY id DESC LIMIT 1",
            [$type]
        );
        if (!$doc) return false;

        $agreement = Database::fetch(
            "SELECT id FROM user_agreements
             WHERE user_id = ? AND agreement_type = ? AND agreement_version = ? AND sms_verified = 1",
            [$userId, $type, $doc['version']]
        );
        return (bool) $agreement;
    }

    private function checkOnboardingComplete(int $userId): void
    {
        $u = Database::fetch(
            "SELECT tc_no, birth_date, company_phone, personal_email, address,
                    identity_front, identity_back
             FROM users WHERE id = ?",
            [$userId]
        );

        $profileOk = !empty($u['tc_no']) && !empty($u['birth_date'])
                   && !empty($u['company_phone']) && !empty($u['address'])
                   && !empty($u['personal_email']);
        $identityOk = !empty($u['identity_front']) && !empty($u['identity_back']);
        $kvkkOk = $this->hasActiveAgreement($userId, 'KVKK');
        $commitmentOk = $this->hasActiveAgreement($userId, 'COMMITMENT');

        if ($profileOk && $kvkkOk && $identityOk && $commitmentOk) {
            Database::query(
                "UPDATE users SET onboarding_completed = 1 WHERE id = ?",
                [$userId]
            );
        }
    }
}
