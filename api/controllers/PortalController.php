<?php

require_once __DIR__ . '/../helpers/DocumentCrypto.php';
require_once __DIR__ . '/../helpers/NetgsmSms.php';

class PortalController
{
    /**
     * Portal durumu: acik mi kapali mi
     */
    public function status(): void
    {
        $setting = Database::fetch("SELECT value FROM settings WHERE `key` = 'customer_portal_enabled'");
        $enabled = $setting && ($setting['value'] === 'true' || $setting['value'] === '1');

        // Acente bilgilerini de gonder (logo, isim)
        $agency = Database::fetch("SELECT value FROM settings WHERE `key` = 'agency_name'");
        $logo = Database::fetch("SELECT value FROM settings WHERE `key` = 'agency_logo'");

        Response::success([
            'enabled' => $enabled,
            'agencyName' => $agency['value'] ?? 'Sigorta Acentesi',
            'agencyLogo' => $logo['value'] ?? null,
        ]);
    }

    /**
     * Token dogrulama
     */
    public function me(): void
    {
        $tokenData = Auth::getCurrentUser();
        if (!$tokenData || (int) $tokenData['role'] !== 3) {
            Response::error('Oturum süresi dolmuş', 401);
        }

        $customerIds = $tokenData['customerIds'] ?? [];
        if (empty($customerIds)) {
            Response::error('Oturum süresi dolmuş', 401);
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $customers = Database::fetchAll(
            "SELECT id, name, customer_type FROM customers WHERE id IN ($placeholders) AND deleted_at IS NULL",
            $customerIds
        );

        Response::success([
            'id' => 0,
            'name' => $customers[0]['name'] ?? 'Müşteri',
            'email' => '',
            'role' => 'musteri',
            'customers' => array_map(function ($c) {
                return ['id' => (int) $c['id'], 'name' => $c['name'], 'type' => $c['customer_type']];
            }, $customers),
        ]);
    }

    /**
     * Client IP adresini al
     */
    private function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip;
    }

    /**
     * TC/VKN ile SMS doğrulama başlat
     */
    public function smsLogin(array $input): void
    {
        // Portal açık mı?
        $setting = Database::fetch("SELECT value FROM settings WHERE `key` = 'customer_portal_enabled'");
        if (!$setting || ($setting['value'] !== 'true' && $setting['value'] !== '1')) {
            Response::error('Müşteri portalı şu anda kapatılmıştır', 403);
        }

        $identityNo = trim($input['identityNo'] ?? '');
        if (!$identityNo || strlen($identityNo) < 10) {
            Response::error('Geçerli bir TC Kimlik No veya Vergi No giriniz', 422);
        }

        $phoneLast4 = preg_replace('/[^0-9]/', '', trim($input['phoneLast4'] ?? ''));
        if (strlen($phoneLast4) !== 4) {
            Response::error('Telefon numaranızın son 4 hanesini giriniz', 422);
        }

        $ip = $this->getClientIp();
        $now = date('Y-m-d H:i:s');
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $oneDayAgo = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $twoMinAgo = date('Y-m-d H:i:s', strtotime('-2 minutes'));

        // ── RATE LIMIT 1: IP bazlı (saatte 10, günde 30) ──
        $ipHourly = Database::fetch(
            "SELECT COUNT(*) as cnt FROM portal_otps WHERE ip = ? AND sms_sent = 1 AND created_at > ?",
            [$ip, $oneHourAgo]
        );
        if ((int) $ipHourly['cnt'] >= 10) {
            Response::error('Çok fazla istek gönderildi. Lütfen daha sonra tekrar deneyin.', 429);
        }

        $ipDaily = Database::fetch(
            "SELECT COUNT(*) as cnt FROM portal_otps WHERE ip = ? AND sms_sent = 1 AND created_at > ?",
            [$ip, $oneDayAgo]
        );
        if ((int) $ipDaily['cnt'] >= 30) {
            Response::error('Günlük istek limitine ulaşıldı. Lütfen yarın tekrar deneyin.', 429);
        }

        // ── RATE LIMIT 2: Global günlük SMS limiti ──
        $globalLimit = Database::fetch("SELECT value FROM settings WHERE `key` = 'portal_daily_sms_limit'");
        $maxDaily = (int) ($globalLimit['value'] ?? 500);
        $globalToday = Database::fetch(
            "SELECT COUNT(*) as cnt FROM portal_otps WHERE sms_sent = 1 AND created_at > ?",
            [$oneDayAgo]
        );
        if ((int) $globalToday['cnt'] >= $maxDaily) {
            Response::error('Sistem şu anda yoğun. Lütfen daha sonra tekrar deneyin.', 429);
        }

        // Müşteriyi bul
        $customer = Database::fetch(
            "SELECT id, name, phone, phone_alt FROM customers WHERE identity_no = ? AND deleted_at IS NULL",
            [$identityNo]
        );

        if (!$customer) {
            Response::error('Bilgileriniz doğrulanamadı. Lütfen acenteniz ile iletişime geçin.', 422);
        }

        // Telefon numarası var mı?
        $phone = $customer['phone'] ?: $customer['phone_alt'];
        if (!$phone) {
            Response::error('Bilgileriniz doğrulanamadı. Lütfen acenteniz ile iletişime geçin.', 422);
        }

        // Son 4 hane eşleşiyor mu?
        $dbPhoneDigits = preg_replace('/[^0-9]/', '', $phone);
        $dbLast4 = substr($dbPhoneDigits, -4);
        if ($phoneLast4 !== $dbLast4) {
            // Alt telefonu da kontrol et
            $phoneAlt = $customer['phone_alt'] ?: '';
            $dbAltDigits = preg_replace('/[^0-9]/', '', $phoneAlt);
            $dbAltLast4 = $dbAltDigits ? substr($dbAltDigits, -4) : '';
            if ($phoneLast4 !== $dbAltLast4) {
                Response::error('Bilgileriniz doğrulanamadı. Lütfen acenteniz ile iletişime geçin.', 422);
            }
        }

        $customerId = (int) $customer['id'];

        // ── RATE LIMIT 3: Müşteri bazlı — 24 saat blok kontrolü ──
        // Son 24 saatte toplam 15+ başarısız OTP denemesi → blokla
        $failedAttempts = Database::fetch(
            "SELECT COALESCE(SUM(attempts), 0) as total FROM portal_otps WHERE customer_id = ? AND created_at > ?",
            [$customerId, $oneDayAgo]
        );
        if ((int) $failedAttempts['total'] >= 15) {
            Response::error('Hesabınız geçici olarak kilitlendi. 24 saat sonra tekrar deneyin.', 429);
        }

        // ── RATE LIMIT 4: Müşteri bazlı — Kademeli SMS hakkı ──
        // Son 24 saatte gönderilen SMS sayısı
        $customerDailySms = Database::fetch(
            "SELECT COUNT(*) as cnt FROM portal_otps WHERE customer_id = ? AND sms_sent = 1 AND created_at > ?",
            [$customerId, $oneDayAgo]
        );
        $smsSentToday = (int) $customerDailySms['cnt'];

        // Günlük toplam 5 SMS limiti (3 ilk hak + 30dk sonra 2 hak)
        if ($smsSentToday >= 5) {
            Response::error('Günlük SMS limitinize ulaştınız. Yarın tekrar deneyin.', 429);
        }

        // İlk 3 hak kullanıldıysa → 30 dakika bekleme kontrolü
        if ($smsSentToday >= 3) {
            $thirdSms = Database::fetch(
                "SELECT created_at FROM portal_otps WHERE customer_id = ? AND sms_sent = 1 AND created_at > ? ORDER BY created_at ASC LIMIT 1 OFFSET 2",
                [$customerId, $oneDayAgo]
            );
            if ($thirdSms) {
                $waitUntil = strtotime($thirdSms['created_at']) + 1800; // 30 dakika
                if (time() < $waitUntil) {
                    $remaining = (int) ceil(($waitUntil - time()) / 60);
                    Response::error("SMS hakkınız doldu. {$remaining} dakika sonra tekrar deneyin.", 429);
                }
            }
        }

        // ── RATE LIMIT 5: Son SMS'den bu yana 2 dakika bekleme ──
        $recent = Database::fetch(
            "SELECT id FROM portal_otps WHERE customer_id = ? AND sms_sent = 1 AND created_at > ?",
            [$customerId, $twoMinAgo]
        );
        if ($recent) {
            Response::error('SMS zaten gönderildi. Lütfen 2 dakika bekleyiniz.', 429);
        }

        // ── RATE LIMIT 6: Başarılı giriş limiti (günde 5) ──
        $successfulLogins = Database::fetch(
            "SELECT COUNT(*) as cnt FROM portal_otps WHERE customer_id = ? AND verified = 1 AND created_at > ?",
            [$customerId, $oneDayAgo]
        );
        if ((int) $successfulLogins['cnt'] >= 5) {
            Response::error('Bugün çok fazla giriş yaptınız. Yarın tekrar deneyin.', 429);
        }

        // ── Eski doğrulanmamış OTP'leri iptal et (rate limit kayıtlarını koru) ──
        Database::query(
            "DELETE FROM portal_otps WHERE customer_id = ? AND verified = 0 AND sms_sent = 0",
            [$customerId]
        );

        // ── OTP oluştur ve kaydet ──
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $otpId = Database::insert('portal_otps', [
            'customer_id' => $customerId,
            'phone' => $phone,
            'ip' => $ip,
            'code' => $code,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
            'sms_sent' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // ── SMS gönder ──
        $smsPhone = $phone;
        $message = $code . " tek kullanimlik sifrenizle musteri portalina giris yapabilirsiniz. Lutfen size ozel gelen bu sifreyi hic kimseyle paylasmayiniz.";
        $smsResult = NetgsmSms::send($smsPhone, $message);

        if (!$smsResult['success']) {
            // SMS başarısız — OTP'yi sil
            Database::query("DELETE FROM portal_otps WHERE id = ?", [$otpId]);
            Response::error('SMS gönderilemedi. Lütfen daha sonra tekrar deneyin.', 500);
        }

        // SMS başarılı — sms_sent=1 yap
        Database::query("UPDATE portal_otps SET sms_sent = 1 WHERE id = ?", [$otpId]);

        // Telefon numarasını maskele
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) >= 10) {
            $masked = substr($cleanPhone, 0, 3) . ' *** ** ' . substr($cleanPhone, -2);
        } else {
            $masked = '*** *** ** **';
        }

        Response::success([
            'customerId' => $customerId,
            'maskedPhone' => $masked,
        ], 'Doğrulama kodu gönderildi');
    }

    /**
     * SMS kodunu doğrula ve giriş yap
     */
    public function smsVerify(array $input): void
    {
        $customerId = (int) ($input['customerId'] ?? 0);
        $code = trim($input['code'] ?? '');

        if (!$customerId || !$code) {
            Response::error('Doğrulama kodu gerekli', 422);
        }

        // OTP'yi bul
        $otp = Database::fetch(
            "SELECT id, code, attempts, expires_at FROM portal_otps WHERE customer_id = ? AND verified = 0 ORDER BY created_at DESC LIMIT 1",
            [$customerId]
        );

        if (!$otp) {
            Response::error('Doğrulama kodu bulunamadı. Lütfen yeniden SMS isteyin.', 404);
        }

        // Süre dolmuş mu?
        if (strtotime($otp['expires_at']) < time()) {
            Response::error('Doğrulama kodunun süresi dolmuş. Lütfen yeniden SMS isteyin.', 410);
        }

        // Deneme sayısı (max 5)
        if ((int) $otp['attempts'] >= 5) {
            Response::error('Çok fazla hatalı deneme. Lütfen yeniden SMS isteyin.', 429);
        }

        // Kodu kontrol et
        if ($otp['code'] !== $code) {
            Database::query(
                "UPDATE portal_otps SET attempts = attempts + 1 WHERE id = ?",
                [$otp['id']]
            );
            $remaining = 4 - (int) $otp['attempts'];
            Response::error('Doğrulama kodu hatalı. ' . $remaining . ' deneme hakkınız kaldı.', 401);
        }

        // OTP'yi doğrulanmış olarak işaretle
        Database::query("UPDATE portal_otps SET verified = 1 WHERE id = ?", [$otp['id']]);

        // Eski OTP'leri temizle
        Database::query(
            "DELETE FROM portal_otps WHERE customer_id = ? AND id != ?",
            [$customerId, $otp['id']]
        );

        // Müşteri bilgilerini çek
        $customer = Database::fetch(
            "SELECT id, name, customer_type FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$customerId]
        );

        if (!$customer) {
            Response::error('Müşteri bulunamadı', 404);
        }

        // Token oluştur (30 dakikalık kısa oturum)
        $token = Auth::generateToken([
            'userId' => 0,
            'email' => '',
            'role' => 3,
            'customerIds' => [$customerId],
            'smsLogin' => true,
        ], false, 1800); // 30 dakika

        Response::success([
            'token' => $token,
            'user' => [
                'id' => 0,
                'name' => $customer['name'],
                'email' => '',
                'role' => 'musteri',
                'customers' => [[
                    'id' => (int) $customer['id'],
                    'name' => $customer['name'],
                    'type' => $customer['customer_type'],
                ]],
            ],
        ], 'Giriş başarılı');
    }

    /**
     * Musterinin policelerini listele
     */
    private function getCustomerIds(array $tokenData): array
    {
        return $tokenData['customerIds'] ?? [];
    }

    public function policies(array $tokenData, array $query): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::success([]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $params = $customerIds;

        $where = "p.customer_id IN ($placeholders) AND p.deleted_at IS NULL";

        // Firma filtresi
        if (!empty($query['customerId']) && in_array((int) $query['customerId'], $customerIds)) {
            $where .= " AND p.customer_id = ?";
            $params[] = (int) $query['customerId'];
        }

        // Durum filtresi
        if (!empty($query['status'])) {
            if ($query['status'] === 'active') {
                $where .= " AND p.is_cancelled = 0 AND p.expires_at >= CURDATE()";
            } elseif ($query['status'] === 'expired') {
                $where .= " AND p.is_cancelled = 0 AND p.expires_at < CURDATE()";
            } elseif ($query['status'] === 'cancelled') {
                $where .= " AND p.is_cancelled = 1";
            }
        }

        // Tarih filtresi
        if (!empty($query['dateFrom'])) {
            $where .= " AND p.starts_at >= ?";
            $params[] = $query['dateFrom'];
        }
        if (!empty($query['dateTo'])) {
            $where .= " AND p.starts_at <= ?";
            $params[] = $query['dateTo'];
        }

        // Arama
        if (!empty($query['search'])) {
            $where .= " AND (p.policy_no LIKE ? OR p.plate_no LIKE ? OR p.insured_name LIKE ? OR i.name LIKE ?)";
            $s = '%' . $query['search'] . '%';
            $params = array_merge($params, [$s, $s, $s, $s]);
        }

        // Sadece ana poliçeler (zeyiller hariç), toplam primi hesapla
        $where .= " AND p.parent_id IS NULL";

        $policies = Database::fetchAll(
            "SELECT p.id, p.policy_no, p.endorsement_no, p.plate_no, p.registration_no,
                    p.insured_name, p.gross_premium, p.net_premium,
                    p.issued_at, p.starts_at, p.expires_at, p.is_cancelled,
                    p.customer_id,
                    c.name as customer_name,
                    i.name as insurance_name, i.color as insurance_color,
                    co.name as company_name,
                    (SELECT COUNT(*) FROM documents d INNER JOIN policies dp ON d.policy_id = dp.id WHERE dp.policy_no = p.policy_no AND dp.deleted_at IS NULL AND d.deleted_at IS NULL) as doc_count,
                    (SELECT COALESCE(SUM(z.gross_premium), 0) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL) as total_gross,
                    (SELECT COALESCE(SUM(z.net_premium), 0) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL) as total_net,
                    (SELECT MAX(z.expires_at) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL AND z.is_cancelled = 0) as effective_expires
             FROM policies p
             LEFT JOIN customers c ON p.customer_id = c.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN companies co ON p.company_id = co.id
             WHERE $where
             ORDER BY p.expires_at ASC",
            $params
        );

        $now = date('Y-m-d');
        $result = array_map(function ($p) use ($now) {
            // Kalan gün: en son zeyilin bitiş tarihine göre
            $effectiveExpires = $p['effective_expires'] ?: $p['expires_at'];
            $daysLeft = null;
            $status = 'active';

            if ((int) $p['is_cancelled'] === 1) {
                $status = 'cancelled';
            } elseif ($effectiveExpires) {
                $diff = (strtotime($effectiveExpires) - strtotime($now)) / 86400;
                $daysLeft = (int) ceil($diff);
                if ($daysLeft < 0) {
                    $status = 'expired';
                } elseif ($daysLeft <= 30) {
                    $status = 'expiring';
                }
            }

            return [
                'id' => (int) $p['id'],
                'policyNo' => $p['policy_no'],
                'endorsementNo' => (int) $p['endorsement_no'],
                'plateNo' => $p['plate_no'],
                'registrationNo' => $p['registration_no'],
                'insuredName' => $p['customer_name'],
                'insurerName' => $p['insured_name'] ?: null,
                'grossPremium' => round((float) $p['total_gross'], 2),
                'netPremium' => round((float) $p['total_net'], 2),
                'issuedAt' => $p['issued_at'],
                'startsAt' => $p['starts_at'],
                'expiresAt' => $effectiveExpires,
                'customerId' => (int) $p['customer_id'],
                'customerName' => $p['customer_name'],
                'insuranceName' => $p['insurance_name'],
                'insuranceColor' => $p['insurance_color'],
                'companyName' => $p['company_name'],
                'docCount' => (int) $p['doc_count'],
                'daysLeft' => $daysLeft,
                'status' => $status,
            ];
        }, $policies);

        Response::success($result);
    }

    /**
     * Ozet istatistikler
     */
    public function summary(array $tokenData): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::success(['totalActive' => 0, 'totalExpired' => 0, 'expiringThisMonth' => 0, 'totalPremium' => 0]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $now = date('Y-m-d');
        $monthEnd = date('Y-m-t');

        $active = Database::fetch(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(gross_premium), 0) as total
             FROM policies WHERE customer_id IN ($placeholders) AND deleted_at IS NULL AND is_cancelled = 0 AND expires_at >= ?",
            array_merge($customerIds, [$now])
        );

        $expired = Database::fetch(
            "SELECT COUNT(*) as cnt FROM policies WHERE customer_id IN ($placeholders) AND deleted_at IS NULL AND is_cancelled = 0 AND expires_at < ?",
            array_merge($customerIds, [$now])
        );

        $expiring = Database::fetch(
            "SELECT COUNT(*) as cnt FROM policies WHERE customer_id IN ($placeholders) AND deleted_at IS NULL AND is_cancelled = 0 AND expires_at BETWEEN ? AND ?",
            array_merge($customerIds, [$now, $monthEnd])
        );

        Response::success([
            'totalActive' => (int) $active['cnt'],
            'totalExpired' => (int) $expired['cnt'],
            'expiringThisMonth' => (int) $expiring['cnt'],
            'totalPremium' => round((float) $active['total'], 2),
        ]);
    }

    /**
     * Police belgesini indir
     */
    public function downloadDocument(array $tokenData, int $docId): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::error('Erişim engellendi', 403);
        }

        $doc = Database::fetch(
            "SELECT d.* FROM documents d
             INNER JOIN policies p ON d.policy_id = p.id
             WHERE d.id = ? AND d.deleted_at IS NULL AND p.deleted_at IS NULL
               AND p.customer_id IN (" . implode(',', array_fill(0, count($customerIds), '?')) . ")",
            array_merge([$docId], $customerIds)
        );

        if (!$doc) {
            Response::error('Belge bulunamadı veya erişim yetkiniz yok', 404);
        }

        if (empty($doc['storage_hash']) || empty($doc['encrypted'])) {
            Response::error('Bu belge indirilebilir formatta değil', 400);
        }

        $encPath = DocumentCrypto::storagePathFromHash($doc['storage_hash']);
        if (!is_file($encPath)) {
            Response::error('Dosya bulunamadı', 404);
        }

        try {
            $dek = DocumentCrypto::decryptDek($doc['dek_encrypted'], $doc['dek_iv'], $doc['dek_auth_tag']);
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
            header('Content-Length: ' . (int) $doc['file_size']);
            header('Content-Disposition: attachment; filename="' . rawurlencode($doc['name']) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');

            DocumentCrypto::decryptFileToOutput($encPath, $dek, $doc['file_iv'], $doc['file_auth_tag']);
            $dek = str_repeat("\0", strlen($dek));
            exit;
        } catch (\Throwable $e) {
            Response::error('Dosya indirilemedi', 500);
        }
    }

    /**
     * Policenin belgelerini listele
     */
    public function policyDocuments(array $tokenData, int $policyId): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::error('Erişim engellendi', 403);
        }

        // Policenin musteriye ait oldugundan emin ol
        $policy = Database::fetch(
            "SELECT id FROM policies WHERE id = ? AND deleted_at IS NULL AND customer_id IN ("
            . implode(',', array_fill(0, count($customerIds), '?')) . ")",
            array_merge([$policyId], $customerIds)
        );

        if (!$policy) {
            Response::error('Poliçe bulunamadı', 404);
        }

        $docs = Database::fetchAll(
            "SELECT id, name, mime_type, file_size, created_at FROM documents WHERE policy_id = ? AND deleted_at IS NULL ORDER BY created_at DESC",
            [$policyId]
        );

        Response::success(array_map(function ($d) {
            return [
                'id' => (int) $d['id'],
                'name' => $d['name'],
                'type' => $d['mime_type'],
                'size' => (int) $d['file_size'],
                'createdAt' => $d['created_at'],
            ];
        }, $docs));
    }

    // ═══════════════════════════════════════════════════
    // PROFİL
    // ═══════════════════════════════════════════════════

    /**
     * Müşteri profil bilgileri (maskelenmiş hassas alanlar)
     */
    public function profile(array $tokenData): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::error('Erişim engellendi', 403);
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $customers = Database::fetchAll(
            "SELECT c.id, c.name, c.customer_type, c.identity_no, c.phone, c.phone_alt, c.email,
                    c.contact_person, c.address, c.tax_office,
                    c.city_id, c.district_id, c.country_id,
                    ci.name as city_name, di.name as district_name, co.name as country_name
             FROM customers c
             LEFT JOIN cities ci ON c.city_id = ci.id
             LEFT JOIN districts di ON c.district_id = di.id
             LEFT JOIN countries co ON c.country_id = co.id
             WHERE c.id IN ($placeholders) AND c.deleted_at IS NULL",
            $customerIds
        );

        $result = array_map(function ($c) {
            // TC/VKN maskeleme: son 4 hane göster
            $identityNo = $c['identity_no'] ?? '';
            $maskedIdentity = '';
            if (strlen($identityNo) > 4) {
                $maskedIdentity = str_repeat('*', strlen($identityNo) - 4) . substr($identityNo, -4);
            } elseif ($identityNo) {
                $maskedIdentity = $identityNo;
            }

            // Telefon maskeleme: ilk 4 + son 2 göster
            $phone = $c['phone'] ?? '';
            $maskedPhone = '';
            if ($phone) {
                $digits = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($digits) >= 10) {
                    $maskedPhone = substr($digits, 0, 4) . ' *** ' . substr($digits, -2);
                } else {
                    $maskedPhone = $phone;
                }
            }

            $phoneAlt = $c['phone_alt'] ?? '';
            $maskedPhoneAlt = '';
            if ($phoneAlt) {
                $digits = preg_replace('/[^0-9]/', '', $phoneAlt);
                if (strlen($digits) >= 10) {
                    $maskedPhoneAlt = substr($digits, 0, 4) . ' *** ' . substr($digits, -2);
                } else {
                    $maskedPhoneAlt = $phoneAlt;
                }
            }

            return [
                'id' => (int) $c['id'],
                'name' => $c['name'],
                'customerType' => $c['customer_type'],
                'customerTypeLabel' => $c['customer_type'] === 'CORPORATE' ? 'Kurumsal' : 'Bireysel',
                'identityNo' => $maskedIdentity,
                'phone' => $maskedPhone,
                'phoneAlt' => $maskedPhoneAlt,
                'email' => $c['email'] ?: null,
                'contactPerson' => $c['contact_person'] ?: null,
                'taxOffice' => $c['tax_office'] ?: null,
                'address' => $c['address'] ?: null,
                'city' => $c['city_name'] ?: null,
                'district' => $c['district_name'] ?: null,
                'country' => $c['country_name'] ?: null,
            ];
        }, $customers);

        Response::success($result);
    }

    // ═══════════════════════════════════════════════════
    // BİLDİRİMLER
    // ═══════════════════════════════════════════════════

    /**
     * Bildirim listesi
     */
    public function notificationList(array $tokenData): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::success(['notifications' => [], 'unreadCount' => 0]);
            return;
        }

        // Vade yaklaşma bildirimlerini otomatik üret (günde 1 kez)
        $this->generateExpiringNotifications($customerIds);

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $rows = Database::fetchAll(
            "SELECT * FROM portal_notifications WHERE customer_id IN ($placeholders) ORDER BY created_at DESC LIMIT 50",
            $customerIds
        );

        $unread = Database::fetch(
            "SELECT COUNT(*) as cnt FROM portal_notifications WHERE customer_id IN ($placeholders) AND is_read = 0",
            $customerIds
        );

        Response::success([
            'notifications' => array_map(function ($n) {
                return [
                    'id'        => (int) $n['id'],
                    'type'      => $n['type'],
                    'title'     => $n['title'],
                    'message'   => $n['message'],
                    'refType'   => $n['ref_type'],
                    'refId'     => $n['ref_id'] ? (int) $n['ref_id'] : null,
                    'isRead'    => (bool) $n['is_read'],
                    'createdAt' => $n['created_at'],
                ];
            }, $rows),
            'unreadCount' => (int) $unread['cnt'],
        ]);
    }

    /**
     * Bildirimleri okundu olarak işaretle
     */
    public function notificationMarkRead(array $tokenData): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::success(null);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        Database::query(
            "UPDATE portal_notifications SET is_read = 1 WHERE customer_id IN ($placeholders) AND is_read = 0",
            $customerIds
        );

        Response::success(null, 'Bildirimler okundu olarak işaretlendi');
    }

    /**
     * Vade yaklaşma bildirimi üret (aynı poliçe için günde 1 kez)
     */
    private function generateExpiringNotifications(array $customerIds): void
    {
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $now = date('Y-m-d');
        $thirtyDaysLater = date('Y-m-d', strtotime('+30 days'));

        // 30 gün içinde bitecek aktif poliçeler
        $expiring = Database::fetchAll(
            "SELECT p.id, p.policy_no, p.plate_no, p.expires_at, p.customer_id,
                    i.name as insurance_name
             FROM policies p
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             WHERE p.customer_id IN ($placeholders)
               AND p.deleted_at IS NULL AND p.is_cancelled = 0 AND p.parent_id IS NULL
               AND p.expires_at BETWEEN ? AND ?",
            array_merge($customerIds, [$now, $thirtyDaysLater])
        );

        foreach ($expiring as $p) {
            // Bu poliçe için bugün bildirim üretilmiş mi?
            $existing = Database::fetch(
                "SELECT id FROM portal_notifications WHERE customer_id = ? AND ref_type = 'policy' AND ref_id = ? AND created_at >= ?",
                [$p['customer_id'], $p['id'], $now . ' 00:00:00']
            );
            if ($existing) continue;

            $daysLeft = (int) ceil((strtotime($p['expires_at']) - strtotime($now)) / 86400);
            $label = $p['plate_no'] ?: $p['policy_no'];
            $insName = $p['insurance_name'] ?: 'Poliçe';

            Database::insert('portal_notifications', [
                'customer_id' => $p['customer_id'],
                'type'        => 'policy_expiring',
                'title'       => $insName . ' poliçenizin süresi dolmak üzere',
                'message'     => $label . ' poliçenizin bitiş tarihine ' . $daysLeft . ' gün kaldı.',
                'ref_type'    => 'policy',
                'ref_id'      => $p['id'],
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════
    // TALEP SİSTEMİ
    // ═══════════════════════════════════════════════════

    private const VALID_REQUEST_TYPES = [
        'phone_change', 'address_change', 'vehicle_change',
        'family_member', 'policy_document', 'health_insurance',
        'new_insurance', 'other',
    ];

    private const VALID_INSURANCE_TYPES = [
        'saglik', 'trafik', 'kasko', 'konut', 'isyeri', 'diger',
    ];

    private const REQUEST_TYPE_LABELS = [
        'phone_change'    => 'Telefon Numaram Değişti',
        'address_change'  => 'Adresim Değişti',
        'vehicle_change'  => 'Araç Değişikliği',
        'family_member'   => 'Yeni Aile Ferdi',
        'policy_document' => 'Poliçe Belgesi Talebi',
        'health_insurance'=> 'Sağlık Sigortası Hakkında',
        'new_insurance'   => 'Yeni Sigorta Talebi',
        'other'           => 'Diğer',
    ];

    private const STATUS_LABELS = [
        'NEW'        => 'Yeni',
        'IN_REVIEW'  => 'İnceleniyor',
        'COMPLETED'  => 'Tamamlandı',
        'CANCELLED'  => 'İptal',
    ];

    /**
     * Talep listesi
     */
    public function requestList(array $tokenData, array $query): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::success([]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $params = $customerIds;

        $where = "customer_id IN ($placeholders)";

        // Durum filtresi
        if (!empty($query['status']) && $query['status'] !== 'all') {
            $where .= " AND status = ?";
            $params[] = strtoupper($query['status']);
        }

        $rows = Database::fetchAll(
            "SELECT * FROM portal_requests WHERE $where ORDER BY created_at DESC LIMIT 100",
            $params
        );

        Response::success(array_map([$this, 'formatRequest'], $rows));
    }

    /**
     * Talep detayı
     */
    public function requestDetail(array $tokenData, int $id): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::error('Erişim engellendi', 403);
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $row = Database::fetch(
            "SELECT * FROM portal_requests WHERE id = ? AND customer_id IN ($placeholders)",
            array_merge([$id], $customerIds)
        );

        if (!$row) {
            Response::error('Talep bulunamadı', 404);
        }

        Response::success($this->formatRequest($row));
    }

    /**
     * Yeni talep oluştur
     */
    public function requestCreate(array $tokenData, array $input): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::error('Erişim engellendi', 403);
        }

        // Tip validasyonu
        $type = trim($input['type'] ?? '');
        if (!in_array($type, self::VALID_REQUEST_TYPES)) {
            Response::error('Lütfen talep türünü seçin.', 422);
        }

        // Mesaj validasyonu
        $message = trim($input['message'] ?? '');
        if (mb_strlen($message) > 2000) {
            Response::error('Mesaj en fazla 2000 karakter olabilir.', 422);
        }

        // Yeni sigorta talebi için sigorta türü
        $insuranceType = null;
        if ($type === 'new_insurance') {
            $insuranceType = trim($input['insuranceType'] ?? '');
            if (!in_array($insuranceType, self::VALID_INSURANCE_TYPES)) {
                Response::error('Lütfen sigorta türünü seçin.', 422);
            }
        }

        // İlk customer_id'yi kullan (SMS login'de tek müşteri)
        $customerId = (int) $customerIds[0];

        // Rate limit: müşteri başına günde max 10 talep
        $now = date('Y-m-d H:i:s');
        $oneDayAgo = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $dailyCount = Database::fetch(
            "SELECT COUNT(*) as cnt FROM portal_requests WHERE customer_id = ? AND created_at > ?",
            [$customerId, $oneDayAgo]
        );
        if ((int) $dailyCount['cnt'] >= 10) {
            Response::error('Şu anda çok sayıda talep oluşturdunuz. Lütfen daha sonra tekrar deneyin.', 429);
        }

        $title = self::REQUEST_TYPE_LABELS[$type] ?? 'Talep';
        $ip = $this->getClientIp();

        $id = Database::insert('portal_requests', [
            'customer_id'    => $customerId,
            'type'           => $type,
            'title'          => $title,
            'message'        => $message ?: null,
            'insurance_type' => $insuranceType,
            'status'         => 'NEW',
            'ip'             => $ip,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // Yeni sigorta talebi ise CRM'e lead olarak gönder
        if ($type === 'new_insurance') {
            $customer = Database::fetch(
                "SELECT name, identity_no, phone, birth_date FROM customers WHERE id = ? AND deleted_at IS NULL",
                [$customerId]
            );
            if ($customer) {
                $insuranceMap = [
                    'saglik' => 'Sağlık Sigortası', 'trafik' => 'Trafik Sigortası',
                    'kasko' => 'Kasko', 'konut' => 'Konut Sigortası',
                    'isyeri' => 'İşyeri Sigortası', 'diger' => 'Diğer',
                ];
                require_once __DIR__ . '/LeadController.php';
                $leadCtrl = new LeadController();

                // Kaynak ID bul
                $portalSource = Database::fetch(
                    "SELECT id FROM lead_sources WHERE name = 'Musteri Portali' AND deleted_at IS NULL"
                );
                $sourceId = $portalSource ? (int)$portalSource['id'] : null;

                // Ürün ID bul
                $productName = $insuranceMap[$insuranceType] ?? 'Diğer';
                $leadProduct = Database::fetch(
                    "SELECT id FROM lead_products WHERE name = ? AND deleted_at IS NULL",
                    [$productName]
                );
                $productId = $leadProduct ? (int)$leadProduct['id'] : null;

                // Otomatik atama
                $assignedTo = null;
                if ($sourceId) {
                    $src = Database::fetch("SELECT auto_assign_to FROM lead_sources WHERE id = ? AND deleted_at IS NULL", [$sourceId]);
                    if ($src && $src['auto_assign_to']) {
                        // Round-robin
                        $rr = Database::fetch(
                            "SELECT u.id FROM users u WHERE u.is_sales_rep = 1 AND u.is_active = 1 AND u.deleted_at IS NULL
                             ORDER BY (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id AND l.status IN ('ACIK','DEVAM') AND l.deleted_at IS NULL) ASC LIMIT 1"
                        );
                        $assignedTo = $rr ? (int)$rr['id'] : null;
                    }
                }

                $leadId = Database::insert('leads', [
                    'source_id' => $sourceId,
                    'product_id' => $productId,
                    'assigned_to' => $assignedTo,
                    'assigned_at' => $assignedTo ? $now : null,
                    'status' => 'ACIK',
                    'full_name' => $customer['name'] ?? null,
                    'tc_no' => $customer['identity_no'] ?? null,
                    'birth_date' => $customer['birth_date'] ?? null,
                    'phone' => $customer['phone'] ?? '',
                    'webhook_event_id' => 'portal-request-' . $id,
                    'webhook_source' => 'musteri.sigortax.net',
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Aktivite logu
                Database::insert('lead_activities', [
                    'lead_id' => $leadId,
                    'user_id' => 0,
                    'type' => 'DURUM',
                    'content' => 'Müşteri portalından oluşturuldu' . ($message ? ': ' . $message : ''),
                    'old_value' => null,
                    'new_value' => 'ACIK',
                    'created_at' => $now,
                ]);

                // Bildirim
                if ($assignedTo) {
                    if (!class_exists('NotificationController')) {
                        require_once __DIR__ . '/NotificationController.php';
                    }
                    NotificationController::create(
                        $assignedTo,
                        'Lead Ataması Yapıldı',
                        'Müşteri portalından yeni lead: ' . ($customer['name'] ?? $customer['phone']),
                        '/leadler',
                        'lead_assigned'
                    );
                }
            }
        }

        Response::success(['id' => $id], 'Talebiniz başarıyla oluşturuldu');
    }

    /**
     * Talep iptal et (müşteri kendi talebini iptal edebilir, sadece NEW durumunda)
     */
    public function requestCancel(array $tokenData, int $id): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::error('Erişim engellendi', 403);
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $row = Database::fetch(
            "SELECT id, status FROM portal_requests WHERE id = ? AND customer_id IN ($placeholders)",
            array_merge([$id], $customerIds)
        );

        if (!$row) {
            Response::error('Talep bulunamadı', 404);
        }

        if ($row['status'] !== 'NEW') {
            Response::error('Sadece yeni talepler iptal edilebilir.', 422);
        }

        Database::update('portal_requests', [
            'status' => 'CANCELLED',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        Response::success(null, 'Talebiniz iptal edildi');
    }

    private function formatRequest(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'customerId'    => (int) $r['customer_id'],
            'type'          => $r['type'],
            'typeLabel'     => self::REQUEST_TYPE_LABELS[$r['type']] ?? $r['type'],
            'title'         => $r['title'],
            'message'       => $r['message'],
            'insuranceType' => $r['insurance_type'],
            'status'        => $r['status'],
            'statusLabel'   => self::STATUS_LABELS[$r['status']] ?? $r['status'],
            'adminReply'    => $r['admin_reply'],
            'repliedAt'     => $r['replied_at'],
            'createdAt'     => $r['created_at'],
            'updatedAt'     => $r['updated_at'],
        ];
    }

    /**
     * Portal poliçe listesini XLSX olarak export et
     * GET /api/portal/policies/export
     */
    public function policiesExport(array $tokenData, array $query): void
    {
        $customerIds = $this->getCustomerIds($tokenData);
        if (empty($customerIds)) {
            Response::error('Poliçe bulunamadı', 404);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $params = $customerIds;

        $where = "p.customer_id IN ($placeholders) AND p.deleted_at IS NULL";

        // Firma filtresi
        if (!empty($query['customerId']) && in_array((int) $query['customerId'], $customerIds)) {
            $where .= " AND p.customer_id = ?";
            $params[] = (int) $query['customerId'];
        }

        // Durum filtresi
        if (!empty($query['status'])) {
            if ($query['status'] === 'active') {
                $where .= " AND p.is_cancelled = 0 AND p.expires_at >= CURDATE()";
            } elseif ($query['status'] === 'expired') {
                $where .= " AND p.is_cancelled = 0 AND p.expires_at < CURDATE()";
            } elseif ($query['status'] === 'cancelled') {
                $where .= " AND p.is_cancelled = 1";
            }
        }

        // Tarih filtresi
        if (!empty($query['dateFrom'])) {
            $where .= " AND p.starts_at >= ?";
            $params[] = $query['dateFrom'];
        }
        if (!empty($query['dateTo'])) {
            $where .= " AND p.starts_at <= ?";
            $params[] = $query['dateTo'];
        }

        // Arama
        if (!empty($query['search'])) {
            $where .= " AND (p.policy_no LIKE ? OR p.plate_no LIKE ? OR p.insured_name LIKE ? OR i.name LIKE ?)";
            $s = '%' . $query['search'] . '%';
            $params = array_merge($params, [$s, $s, $s, $s]);
        }

        // Sadece ana poliçeler
        $where .= " AND p.parent_id IS NULL";

        $policies = Database::fetchAll(
            "SELECT p.id, p.policy_no, p.plate_no,
                    p.insured_name, p.is_cancelled,
                    p.issued_at, p.starts_at, p.expires_at,
                    p.customer_id,
                    c.name as customer_name,
                    i.name as insurance_name,
                    co.name as company_name,
                    (SELECT COALESCE(SUM(z.gross_premium), 0) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL) as total_gross,
                    (SELECT MAX(z.expires_at) FROM policies z WHERE z.policy_no = p.policy_no AND z.deleted_at IS NULL AND z.is_cancelled = 0) as effective_expires
             FROM policies p
             LEFT JOIN customers c ON p.customer_id = c.id
             LEFT JOIN insurance_types i ON p.insurance_type_id = i.id
             LEFT JOIN companies co ON p.company_id = co.id
             WHERE $where
             ORDER BY p.expires_at ASC",
            $params
        );

        $now = date('Y-m-d');
        $statusLabels = ['active' => 'Aktif', 'expiring' => 'Aktif', 'expired' => 'Süresi Dolmuş', 'cancelled' => 'İptal'];

        $exportRows = [];
        $counter = 0;
        foreach ($policies as $p) {
            $counter++;
            $effectiveExpires = $p['effective_expires'] ?: $p['expires_at'];
            $status = 'active';
            if ((int) $p['is_cancelled'] === 1) {
                $status = 'cancelled';
            } elseif ($effectiveExpires) {
                $diff = (strtotime($effectiveExpires) - strtotime($now)) / 86400;
                $daysLeft = (int) ceil($diff);
                if ($daysLeft < 0) {
                    $status = 'expired';
                } elseif ($daysLeft <= 30) {
                    $status = 'expiring';
                }
            }

            $exportRows[] = [
                'sira'           => $counter,
                'plate_no'       => $p['plate_no'] ?: '-',
                'policy_no'      => $p['policy_no'],
                'insurance_name' => $p['insurance_name'] ?: '-',
                'company_name'   => $p['company_name'] ?: '-',
                'insured_name'   => $p['customer_name'] ?: '-',
                'insurer_name'   => $p['insured_name'] ?: '-',
                'issued_at'      => $p['issued_at'],
                'starts_at'      => $p['starts_at'],
                'expires_at'     => $effectiveExpires,
                'status'         => $statusLabels[$status] ?? $status,
                'gross_premium'  => (float) $p['total_gross'],
            ];
        }

        // Toplam satırı
        $totalPremium = array_sum(array_column($exportRows, 'gross_premium'));
        $exportRows[] = [
            'sira'           => '',
            'plate_no'       => '',
            'policy_no'      => '',
            'insurance_name' => '',
            'company_name'   => '',
            'insured_name'   => '',
            'insurer_name'   => '',
            'issued_at'      => '',
            'starts_at'      => '',
            'expires_at'     => '',
            'status'         => 'TOPLAM',
            'gross_premium'  => $totalPremium,
        ];

        $customerName = '';
        if (!empty($policies)) {
            $customerName = $policies[0]['customer_name'] ?? '';
        }
        $safeName = preg_replace('/[^a-zA-Z0-9_\x{00C0}-\x{024F}]/u', '_', $customerName);

        $columns = [
            ['key' => 'sira',           'label' => 'S.No',           'type' => Response::COL_NUMBER],
            ['key' => 'plate_no',       'label' => 'Plaka'],
            ['key' => 'policy_no',      'label' => 'Poliçe No',      'type' => Response::COL_IDENTIFIER],
            ['key' => 'insurance_name', 'label' => 'Poliçe Türü'],
            ['key' => 'company_name',   'label' => 'Şirket'],
            ['key' => 'insured_name',   'label' => 'Sigortalı'],
            ['key' => 'insurer_name',   'label' => 'Sigorta Ettiren'],
            ['key' => 'issued_at',      'label' => 'Tanzim Tarihi',  'type' => Response::COL_DATE],
            ['key' => 'starts_at',      'label' => 'Başlangıç',      'type' => Response::COL_DATE],
            ['key' => 'expires_at',     'label' => 'Bitiş',          'type' => Response::COL_DATE],
            ['key' => 'status',         'label' => 'Durum'],
            ['key' => 'gross_premium',  'label' => 'Brüt Prim',      'type' => Response::COL_CURRENCY],
        ];

        Response::xlsx($exportRows, $columns, $safeName . '_policeler_' . date('Y-m-d') . '.xlsx');
    }
}
