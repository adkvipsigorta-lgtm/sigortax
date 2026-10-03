<?php

class AuthController
{
    // -------------------------------------------------------
    // TOTP Helper Methods (Base32 + HMAC-SHA1 based TOTP)
    // -------------------------------------------------------

    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::$base32Chars[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        $binary = '';
        foreach (str_split($encoded) as $char) {
            $pos = strpos(self::$base32Chars, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        $chunks = str_split($binary, 8);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $decoded .= chr(bindec($chunk));
        }

        return $decoded;
    }

    private function generateBase32Secret(int $length = 16): string
    {
        $randomBytes = random_bytes(ceil($length * 5 / 8));
        $secret = $this->base32Encode($randomBytes);
        return substr($secret, 0, $length);
    }

    private function getTotpCode(string $secret, ?int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretKey = $this->base32Decode($secret);

        // Pack time as 8-byte big-endian
        $time = pack('N*', 0, $timeSlice);

        // HMAC-SHA1
        $hmac = hash_hmac('sha1', $time, $secretKey, true);

        // Dynamic truncation
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $code = (
            ((ord($hmac[$offset]) & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
            (ord($hmac[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    private function verifyTotpCode(string $secret, string $code, int $window = 1): bool
    {
        $currentTimeSlice = floor(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $calculatedCode = $this->getTotpCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    private function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $charsLen = strlen($chars);

        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $chars[random_int(0, $charsLen - 1)];
            }
            $codes[] = $code;
        }

        return $codes;
    }

    // -------------------------------------------------------
    // User-Agent Parser + Session Logger
    // -------------------------------------------------------

    private function parseUserAgent(string $ua): array
    {
        // Browser
        $browser = 'Bilinmiyor';
        if (preg_match('/Edg[e\/](\d+)/i', $ua)) $browser = 'Edge';
        elseif (preg_match('/OPR\/(\d+)/i', $ua)) $browser = 'Opera';
        elseif (preg_match('/Chrome\/(\d+)/i', $ua) && !preg_match('/Edg/i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/Firefox\/(\d+)/i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/Safari\/(\d+)/i', $ua) && !preg_match('/Chrome/i', $ua)) $browser = 'Safari';
        elseif (preg_match('/MSIE|Trident/i', $ua)) $browser = 'Internet Explorer';

        // OS
        $os = 'Bilinmiyor';
        if (preg_match('/Windows NT 10/i', $ua)) $os = 'Windows 10/11';
        elseif (preg_match('/Windows NT/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Mac OS X (\d+[._]\d+)/i', $ua, $m)) $os = 'macOS ' . str_replace('_', '.', $m[1]);
        elseif (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
        elseif (preg_match('/Android (\d+)/i', $ua, $m)) $os = 'Android ' . $m[1];
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
        elseif (preg_match('/iPhone OS (\d+)/i', $ua, $m)) $os = 'iOS ' . $m[1];
        elseif (preg_match('/iPad/i', $ua)) $os = 'iPadOS';

        // Device
        $device = 'Masaustu';
        if (preg_match('/Mobile|Android.*Mobile|iPhone/i', $ua)) $device = 'Mobil';
        elseif (preg_match('/iPad|Android(?!.*Mobile)|Tablet/i', $ua)) $device = 'Tablet';

        return ['browser' => $browser, 'os' => $os, 'device' => $device];
    }

    private function parseLanguage(): string
    {
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (!$accept) return 'Bilinmiyor';

        $langMap = [
            'tr' => 'Turkce', 'en' => 'Ingilizce', 'de' => 'Almanca', 'fr' => 'Fransizca',
            'es' => 'Ispanyolca', 'it' => 'Italyanca', 'ar' => 'Arapca', 'ru' => 'Rusca',
            'zh' => 'Cince', 'ja' => 'Japonca', 'ko' => 'Korece', 'pt' => 'Portekizce',
            'nl' => 'Hollandaca', 'pl' => 'Lehce', 'sv' => 'Isvecce', 'da' => 'Danca',
            'fi' => 'Fince', 'no' => 'Norvecc', 'el' => 'Yunanca', 'cs' => 'Cekcce',
            'ro' => 'Romence', 'hu' => 'Macarca', 'uk' => 'Ukraynaca', 'bg' => 'Bulgarca',
            'az' => 'Azerbaycanca', 'ka' => 'Gurcuce', 'fa' => 'Farsca', 'he' => 'Ibranice',
        ];

        // Parse first language code
        $primary = explode(',', $accept)[0];
        $code = strtolower(substr(trim($primary), 0, 2));

        return $langMap[$code] ?? strtoupper($code);
    }

    private function getGeoFromIp(string $ip): array
    {
        $result = ['country' => null, 'countryCode' => null, 'city' => null];

        // Skip local/private IPs
        if (in_array($ip, ['127.0.0.1', '::1', '0.0.0.0']) || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) {
            return $result;
        }

        // ip-api.com free API (no key needed, 45 req/min)
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city", false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data) && ($data['status'] ?? '') === 'success') {
                $result['country'] = $data['country'] ?? null;
                $result['countryCode'] = $data['countryCode'] ?? null;
                $result['city'] = $data['city'] ?? null;
            }
        }

        return $result;
    }

    /**
     * Bildirim tipinin global + kullanici bazli acik olup olmadigini kontrol eder.
     */
    private static function isNotificationEnabled(string $type, int $userId): bool
    {
        // 1. Global ayar kontrolu
        $globalRow = Database::fetch("SELECT value FROM settings WHERE `key` = 'notification_settings'");
        if ($globalRow) {
            $global = json_decode($globalRow['value'], true);
            if (is_array($global) && isset($global[$type]) && !$global[$type]) {
                return false; // Admin kapattiysa kimseye gitmesin
            }
        }

        // 2. Kullanici tercihi kontrolu
        $userRow = Database::fetch(
            "SELECT value FROM settings WHERE `key` = ?",
            ["notification_preferences_$userId"]
        );
        if ($userRow) {
            $prefs = json_decode($userRow['value'], true);
            if (is_array($prefs) && isset($prefs[$type]) && !$prefs[$type]) {
                return false; // Kullanici kendisi kapattiysa
            }
        }

        return true;
    }

    private function logSession(int $userId, string $token): void
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $parsed = $this->parseUserAgent($ua);
        $tokenHash = hash('sha256', $token);
        $language = $this->parseLanguage();
        $geo = $this->getGeoFromIp($ip);

        $location = null;
        if ($geo['city'] && $geo['country']) {
            $location = $geo['city'] . ', ' . $geo['country'];
        } elseif ($geo['country']) {
            $location = $geo['country'];
        }

        Database::insert('sessions', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'device' => $parsed['device'],
            'browser' => $parsed['browser'],
            'os' => $parsed['os'],
            'location' => $location,
            'city' => $geo['city'],
            'country' => $geo['country'],
            'country_code' => $geo['countryCode'],
            'language' => $language,
            'token_hash' => $tokenHash,
            'is_current' => 0,
            'logged_in_at' => date('Y-m-d H:i:s'),
        ]);

        // Login bildirimi - ayarlara bagli
        if (self::isNotificationEnabled('login_new_device', $userId)) {
            require_once __DIR__ . '/NotificationController.php';
            $locationText = $location ? " ($location)" : '';
            NotificationController::create(
                $userId,
                'Yeni giris algilandi',
                $parsed['browser'] . ' - ' . $parsed['os'] . ' (' . $parsed['device'] . ')' . $locationText . ' - IP: ' . $ip,
                null,
                'warning'
            );
        }
    }

    // -------------------------------------------------------
    // Auth Endpoints
    // -------------------------------------------------------

    public function login(array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, ['email' => 'required|email', 'password' => 'required|min:6'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $user = Database::fetch(
            "SELECT * FROM users WHERE email = ? AND deleted_at IS NULL",
            [$input['email']]
        );

        if (!$user || !password_verify($input['password'], $user['password'])) {
            Response::error('Gecersiz e-posta veya sifre', 401);
        }

        if ((int) $user['is_active'] !== 1) {
            Response::error('Hesabiniz devre disi birakilmis. Yonetici ile iletisime gecin.', 403);
        }

        // Müşteri portalı kullanıcıları CRM'e giremez
        if ((int) $user['role'] === 3) {
            Response::error('Bu hesap ile CRM\'e giriş yapılamaz. Müşteri portalını kullanın.', 403);
        }

        // Check if 2FA is enabled
        if (!empty($user['two_factor_enabled']) && (int) $user['two_factor_enabled'] === 1) {
            Response::success([
                'requiresTwoFactor' => true,
                'userId' => $user['id'],
            ], 'Iki faktorlu dogrulama gerekli');
            return;
        }

        $rememberMe = !empty($input['rememberMe']) && $input['rememberMe'] === true;
        $token = Auth::generateToken([
            'userId' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'branchId' => $user['branch_id'],
        ], $rememberMe);

        $roleMap = [1 => 'admin', 0 => 'kullanici', 2 => 'acente'];
        $roleName = $roleMap[(int) $user['role']] ?? 'kullanici';

        $this->logSession((int) $user['id'], $token);

        $socketToken = Auth::generateSocketToken((int) $user['id'], $roleName);

        Response::success([
            'token' => $token,
            'socketToken' => $socketToken,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $roleName,
                'branchId' => $user['branch_id'],
                'twoFactorEnabled' => !empty($user['two_factor_enabled']) && (int) $user['two_factor_enabled'] === 1,
            ],
        ], 'Giris basarili');
    }

    public function logout(): void
    {
        // Stateless JWT - just return success
        Response::success(null, 'Cikis basarili');
    }

    public function me(): void
    {
        $tokenData = Auth::getCurrentUser();
        if (!$tokenData) {
            Response::error('Oturum suresi dolmus', 401);
        }

        $user = Database::fetch(
            "SELECT id, name, email, role, branch_id, is_active, two_factor_enabled, phone, birth_date, address, country_id, city_id, district_id FROM users WHERE id = ? AND deleted_at IS NULL",
            [$tokenData['userId']]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        $roleMap = [1 => 'admin', 0 => 'kullanici', 2 => 'acente'];
        $roleName = $roleMap[(int) $user['role']] ?? 'kullanici';

        Response::success([
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $roleName,
            'branchId' => $user['branch_id'],
            'isActive' => (bool) $user['is_active'],
            'twoFactorEnabled' => !empty($user['two_factor_enabled']) && (int) $user['two_factor_enabled'] === 1,
            'phone' => $user['phone'],
            'birthDate' => $user['birth_date'],
            'address' => $user['address'],
            'countryId' => $user['country_id'] ? (int) $user['country_id'] : null,
            'cityId' => $user['city_id'] ? (int) $user['city_id'] : null,
            'districtId' => $user['district_id'] ? (int) $user['district_id'] : null,
            // Taze socketToken — client expired socket token'i bununla yeniler
            'socketToken' => Auth::generateSocketToken((int) $user['id'], $roleName),
        ]);
    }

    // -------------------------------------------------------
    // Notification Preferences
    // -------------------------------------------------------

    public function getNotificationPreferences(array $authUser): void
    {
        $userId = $authUser['userId'];

        // Notification preferences stored in settings table as JSON
        $setting = Database::fetch(
            "SELECT value FROM settings WHERE `key` = ?",
            ['notification_preferences_' . $userId]
        );

        $prefs = $setting ? (json_decode($setting['value'], true) ?: []) : [];

        Response::success($prefs);
    }

    public function saveNotificationPreferences(array $authUser, array $input): void
    {
        $userId = $authUser['userId'];
        $key = 'notification_preferences_' . $userId;
        $value = json_encode($input);

        $existing = Database::fetch("SELECT id FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            Database::update('settings', [
                'value' => $value,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$existing['id']]);
        } else {
            Database::insert('settings', [
                'key' => $key,
                'value' => $value,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        Response::success(null, 'Bildirim tercihleri kaydedildi');
    }

    // -------------------------------------------------------
    // Profile Management
    // -------------------------------------------------------

    public function updateProfile(array $authUser, array $input): void
    {
        $userId = $authUser['userId'];

        $user = Database::fetch(
            "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        // Map input fields to database columns (only valid users columns)
        $fieldMap = [
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'birthDate' => 'birth_date',
            'address' => 'address',
            'countryId' => 'country_id',
            'cityId' => 'city_id',
            'districtId' => 'district_id',
        ];

        $updateData = [];
        foreach ($fieldMap as $inputKey => $dbColumn) {
            if (array_key_exists($inputKey, $input)) {
                $updateData[$dbColumn] = $input[$inputKey];
            }
        }

        if (empty($updateData)) {
            Response::error('Guncellenecek alan bulunamadi', 422);
        }

        // If email is changing, check uniqueness
        if (isset($updateData['email']) && $updateData['email'] !== $user['email']) {
            $existing = Database::fetch(
                "SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL",
                [$updateData['email'], $userId]
            );

            if ($existing) {
                Response::error('Bu e-posta adresi zaten kullaniliyor', 422);
            }
        }

        Database::update('users', $updateData, 'id = ?', [$userId]);

        Response::success(null, 'Profil basariyla guncellendi');
    }

    public function changePassword(array $authUser, array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, [
            'currentPassword' => 'required',
            'newPassword' => 'required|min:6',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $userId = $authUser['userId'];

        $user = Database::fetch(
            "SELECT id, password FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        if (!password_verify($input['currentPassword'], $user['password'])) {
            Response::error('Mevcut sifre yanlis', 422);
        }

        $hashedPassword = password_hash($input['newPassword'], PASSWORD_DEFAULT);

        Database::update('users', ['password' => $hashedPassword], 'id = ?', [$userId]);

        Response::success(null, 'Sifre basariyla degistirildi');
    }

    // -------------------------------------------------------
    // Two-Factor Authentication (TOTP)
    // -------------------------------------------------------

    // -------------------------------------------------------
    // Sessions (Login History)
    // -------------------------------------------------------

    public function sessions(array $authUser): void
    {
        $userId = $authUser['userId'];

        // Get current token hash to mark active session
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $currentTokenHash = '';
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $currentTokenHash = hash('sha256', $matches[1]);
        }

        $sessions = Database::fetchAll(
            "SELECT id, ip_address, device, browser, os, location, city, country, country_code, language, token_hash, logged_in_at
             FROM sessions
             WHERE user_id = ? AND is_revoked = 0
             ORDER BY logged_in_at DESC
             LIMIT 50",
            [$userId]
        );

        $result = array_map(function ($s) use ($currentTokenHash) {
            return [
                'id' => (int) $s['id'],
                'ipAddress' => $s['ip_address'],
                'device' => $s['device'],
                'browser' => $s['browser'],
                'os' => $s['os'],
                'location' => $s['location'],
                'city' => $s['city'],
                'country' => $s['country'],
                'countryCode' => $s['country_code'],
                'language' => $s['language'],
                'isCurrent' => $s['token_hash'] === $currentTokenHash,
                'loggedInAt' => $s['logged_in_at'],
            ];
        }, $sessions);

        Response::success($result);
    }

    public function deleteSession(array $authUser, int $sessionId): void
    {
        $userId = $authUser['userId'];

        $session = Database::fetch(
            "SELECT id, token_hash FROM sessions WHERE id = ? AND user_id = ?",
            [$sessionId, $userId]
        );

        if (!$session) {
            Response::error('Oturum bulunamadi', 404);
        }

        // Check if trying to delete current session
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $currentTokenHash = hash('sha256', $matches[1]);
            if ($session['token_hash'] === $currentTokenHash) {
                Response::error('Aktif oturumunuzu silemezsiniz', 422);
            }
        }

        Database::update('sessions', ['is_revoked' => 1], 'id = ? AND user_id = ?', [$sessionId, $userId]);

        // Kullanicinin tum tab/cihazlari sessions-updated alir,
        // token'i revoke edilen tab fetchMe'de basarisiz olup otomatik logout olur
        SocketEmitter::sessionsUpdated($userId);

        Response::success(null, 'Oturum sonlandirildi');
    }

    // -------------------------------------------------------
    // Two-Factor Authentication (TOTP)
    // -------------------------------------------------------

    public function twoFactorSetup(array $authUser): void
    {
        $userId = $authUser['userId'];

        $user = Database::fetch(
            "SELECT id, email, two_factor_enabled FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        if (!empty($user['two_factor_enabled']) && (int) $user['two_factor_enabled'] === 1) {
            Response::error('Iki faktorlu dogrulama zaten aktif', 422);
        }

        // Generate a 16-character base32 secret
        $secret = $this->generateBase32Secret(16);

        // Store secret but don't enable yet
        Database::update('users', ['two_factor_secret' => $secret], 'id = ?', [$userId]);

        // Build otpauth URL
        $otpauthUrl = 'otpauth://totp/SigortaApp:' . urlencode($user['email'])
            . '?secret=' . $secret
            . '&issuer=SigortaApp';

        Response::success([
            'secret' => $secret,
            'otpauthUrl' => $otpauthUrl,
        ], 'Iki faktorlu dogrulama kurulumu hazir');
    }

    public function twoFactorEnable(array $authUser, array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, ['code' => 'required'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $userId = $authUser['userId'];

        $user = Database::fetch(
            "SELECT id, two_factor_secret, two_factor_enabled FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        if (empty($user['two_factor_secret'])) {
            Response::error('Once iki faktorlu dogrulama kurulumunu baslatin', 422);
        }

        if (!empty($user['two_factor_enabled']) && (int) $user['two_factor_enabled'] === 1) {
            Response::error('Iki faktorlu dogrulama zaten aktif', 422);
        }

        $code = str_pad(trim($input['code']), 6, '0', STR_PAD_LEFT);

        if (!$this->verifyTotpCode($user['two_factor_secret'], $code)) {
            Response::error('Gecersiz dogrulama kodu', 401);
        }

        // Generate recovery codes
        $recoveryCodes = $this->generateRecoveryCodes(8);

        Database::update('users', [
            'two_factor_enabled' => 1,
            'two_factor_recovery_codes' => json_encode($recoveryCodes),
        ], 'id = ?', [$userId]);

        Response::success([
            'recoveryCodes' => $recoveryCodes,
        ], 'Iki faktorlu dogrulama basariyla etkinlestirildi');
    }

    public function twoFactorDisable(array $authUser, array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, ['password' => 'required'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $userId = $authUser['userId'];

        $user = Database::fetch(
            "SELECT id, password FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        if (!password_verify($input['password'], $user['password'])) {
            Response::error('Gecersiz sifre', 401);
        }

        Database::update('users', [
            'two_factor_enabled' => 0,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ], 'id = ?', [$userId]);

        Response::success(null, 'Iki faktorlu dogrulama devre disi birakildi');
    }

    public function twoFactorVerify(array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, ['userId' => 'required', 'code' => 'required'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $user = Database::fetch(
            "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL",
            [$input['userId']]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        if (empty($user['two_factor_secret'])) {
            Response::error('Iki faktorlu dogrulama yapilandirilmamis', 422);
        }

        $code = trim($input['code']);
        $verified = false;
        $recoveryCodeUsed = false;

        // First try TOTP code (6 digits)
        if (preg_match('/^\d{6}$/', $code)) {
            $totpCode = str_pad($code, 6, '0', STR_PAD_LEFT);
            if ($this->verifyTotpCode($user['two_factor_secret'], $totpCode)) {
                $verified = true;
            }
        }

        // If TOTP didn't match, try recovery codes
        if (!$verified) {
            $recoveryCodes = json_decode($user['two_factor_recovery_codes'] ?? '[]', true);
            if (is_array($recoveryCodes)) {
                $codeIndex = array_search($code, $recoveryCodes, true);
                if ($codeIndex !== false) {
                    $verified = true;
                    $recoveryCodeUsed = true;

                    // Remove used recovery code
                    array_splice($recoveryCodes, $codeIndex, 1);
                    Database::update('users', [
                        'two_factor_recovery_codes' => json_encode(array_values($recoveryCodes)),
                    ], 'id = ?', [$user['id']]);
                }
            }
        }

        if (!$verified) {
            Response::error('Gecersiz dogrulama kodu', 401);
        }

        // Generate JWT token
        $token = Auth::generateToken([
            'userId' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'branchId' => $user['branch_id'],
        ]);  // 2FA sonrası rememberMe bilgisi taşınmıyor, normal süre (7 gün) kullanılır

        $roleMap = [1 => 'admin', 0 => 'kullanici', 2 => 'acente'];
        $roleName = $roleMap[(int) $user['role']] ?? 'kullanici';

        $this->logSession((int) $user['id'], $token);

        $socketToken = Auth::generateSocketToken((int) $user['id'], $roleName);

        Response::success([
            'token' => $token,
            'socketToken' => $socketToken,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $roleName,
                'branchId' => $user['branch_id'],
                'twoFactorEnabled' => true,
            ],
        ], $recoveryCodeUsed ? 'Kurtarma kodu ile giris basarili' : 'Iki faktorlu dogrulama basarili');
    }
}
