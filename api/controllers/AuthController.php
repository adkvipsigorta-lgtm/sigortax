<?php

require_once __DIR__ . '/../helpers/AuthCrypto.php';

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

    /**
     * TOTP dogrulama. Replay korumasi icin kullanilan step'i doner.
     * @return int|false Basarili olursa kullanilan time step, degilse false
     */
    private function verifyTotpCode(string $secret, string $code, int $window = 1, ?int $lastUsedStep = null)
    {
        $currentTimeSlice = (int) floor(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $step = $currentTimeSlice + $i;

            // Replay korumasi: ayni veya daha eski step kullanilmis mi
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }

            $calculatedCode = $this->getTotpCode($secret, $step);
            if (hash_equals($calculatedCode, $code)) {
                return $step;
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

    /**
     * Kullanicinin TOTP secret'ini oku (encrypted veya legacy plaintext).
     */
    private function getUserTotpSecret(array $user): ?string
    {
        // Encrypted secret varsa onu kullan
        if (!empty($user['two_factor_secret_enc']) && !empty($user['two_factor_secret_iv']) && !empty($user['two_factor_secret_tag'])) {
            try {
                return AuthCrypto::decrypt(
                    $user['two_factor_secret_enc'],
                    $user['two_factor_secret_iv'],
                    $user['two_factor_secret_tag']
                );
            } catch (Exception $e) {
                // Decrypt basarisiz, plaintext'e fallback
            }
        }

        // Legacy plaintext
        return $user['two_factor_secret'] ?? null;
    }

    /**
     * TOTP secret'i encrypted olarak kaydet.
     */
    private function saveTotpSecretEncrypted(int $userId, string $secret): void
    {
        $enc = AuthCrypto::encrypt($secret);
        Database::update('users', [
            'two_factor_secret' => null, // plaintext temizle
            'two_factor_secret_enc' => $enc['ciphertext'],
            'two_factor_secret_iv' => $enc['iv'],
            'two_factor_secret_tag' => $enc['tag'],
        ], 'id = ?', [$userId]);
    }

    // -------------------------------------------------------
    // Challenge Token Yonetimi
    // -------------------------------------------------------

    /**
     * Opaque challenge token olustur (userId aciga cikmaz).
     */
    private function createChallengeToken(int $userId, bool $requiresSetup = false): string
    {
        $token = bin2hex(random_bytes(48)); // 96 karakter
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        // Eski expired/used challenge'lari temizle
        Database::query(
            "DELETE FROM auth_challenges WHERE (expires_at < NOW() OR used = 1) AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        // expires_at icin MySQL NOW() kullan (PHP/MySQL timezone farki onlenir)
        Database::query(
            "INSERT INTO auth_challenges (token, user_id, requires_setup, attempts, max_attempts, used, ip_address, expires_at)
             VALUES (?, ?, ?, 0, 5, 0, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))",
            [hash('sha256', $token), $userId, $requiresSetup ? 1 : 0, $ip]
        );

        return $token;
    }

    /**
     * Challenge token'i dogrula ve kullaniciya ait bilgileri don.
     * Basarisizsa null doner.
     */
    private function validateChallengeToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $challenge = Database::fetch(
            "SELECT * FROM auth_challenges WHERE token = ? AND used = 0 AND expires_at > NOW()",
            [$tokenHash]
        );

        if (!$challenge) {
            return null;
        }

        // IP kontrolu
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        if ($challenge['ip_address'] !== $ip) {
            return null;
        }

        // Max attempt kontrolu
        if ((int) $challenge['attempts'] >= (int) $challenge['max_attempts']) {
            // Challenge tukendi, invalidate et
            Database::update('auth_challenges', ['used' => 1], 'id = ?', [$challenge['id']]);
            return null;
        }

        return $challenge;
    }

    /**
     * Challenge attempt sayisini artir.
     */
    private function incrementChallengeAttempts(int $challengeId): void
    {
        Database::query(
            "UPDATE auth_challenges SET attempts = attempts + 1 WHERE id = ?",
            [$challengeId]
        );
    }

    /**
     * Challenge'i kullanildi olarak isaretle.
     */
    private function markChallengeUsed(int $challengeId): void
    {
        Database::update('auth_challenges', ['used' => 1], 'id = ?', [$challengeId]);
    }

    // -------------------------------------------------------
    // Brute-force Korumasi
    // -------------------------------------------------------

    /**
     * Kullanicinin 2FA kilidini kontrol et. Kilitliyse hata don.
     */
    private function checkTwoFactorLock(array $user): void
    {
        if (!empty($user['two_factor_locked_until'])) {
            $lockedUntil = strtotime($user['two_factor_locked_until']);
            if ($lockedUntil && $lockedUntil > time()) {
                $remaining = ceil(($lockedUntil - time()) / 60);
                Response::error(
                    "Cok fazla basarisiz deneme. $remaining dakika sonra tekrar deneyin.",
                    429
                );
            }
            // Kilit suresi dolmussa temizle
            Database::update('users', [
                'two_factor_attempts' => 0,
                'two_factor_locked_until' => null,
            ], 'id = ?', [$user['id']]);
        }
    }

    /**
     * Basarisiz 2FA denemesini kaydet. 5 denemeden sonra 15 dk kilitle.
     */
    private function recordFailedTwoFactor(int $userId): void
    {
        Database::query(
            "UPDATE users SET two_factor_attempts = two_factor_attempts + 1 WHERE id = ?",
            [$userId]
        );

        $user = Database::fetch("SELECT two_factor_attempts FROM users WHERE id = ?", [$userId]);
        if ($user && (int) $user['two_factor_attempts'] >= 5) {
            Database::update('users', [
                'two_factor_locked_until' => date('Y-m-d H:i:s', time() + 900), // 15 dakika
            ], 'id = ?', [$userId]);
        }
    }

    /**
     * Basarili 2FA sonrasi attempt sayacini sifirla.
     */
    private function clearTwoFactorAttempts(int $userId): void
    {
        Database::update('users', [
            'two_factor_attempts' => 0,
            'two_factor_locked_until' => null,
        ], 'id = ?', [$userId]);
    }

    // -------------------------------------------------------
    // User-Agent Parser + Session Logger
    // -------------------------------------------------------

    private function parseUserAgent(string $ua): array
    {
        $browser = 'Bilinmiyor';
        if (preg_match('/Edg[e\/](\d+)/i', $ua)) $browser = 'Edge';
        elseif (preg_match('/OPR\/(\d+)/i', $ua)) $browser = 'Opera';
        elseif (preg_match('/Chrome\/(\d+)/i', $ua) && !preg_match('/Edg/i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/Firefox\/(\d+)/i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/Safari\/(\d+)/i', $ua) && !preg_match('/Chrome/i', $ua)) $browser = 'Safari';
        elseif (preg_match('/MSIE|Trident/i', $ua)) $browser = 'Internet Explorer';

        $os = 'Bilinmiyor';
        if (preg_match('/Windows NT 10/i', $ua)) $os = 'Windows 10/11';
        elseif (preg_match('/Windows NT/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Mac OS X (\d+[._]\d+)/i', $ua, $m)) $os = 'macOS ' . str_replace('_', '.', $m[1]);
        elseif (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
        elseif (preg_match('/Android (\d+)/i', $ua, $m)) $os = 'Android ' . $m[1];
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
        elseif (preg_match('/iPhone OS (\d+)/i', $ua, $m)) $os = 'iOS ' . $m[1];
        elseif (preg_match('/iPad/i', $ua)) $os = 'iPadOS';

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

        $primary = explode(',', $accept)[0];
        $code = strtolower(substr(trim($primary), 0, 2));

        return $langMap[$code] ?? strtoupper($code);
    }

    private function getGeoFromIp(string $ip): array
    {
        $result = ['country' => null, 'countryCode' => null, 'city' => null];

        if (in_array($ip, ['127.0.0.1', '::1', '0.0.0.0']) || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) {
            return $result;
        }

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
        $globalRow = Database::fetch("SELECT value FROM settings WHERE `key` = 'notification_settings'");
        if ($globalRow) {
            $global = json_decode($globalRow['value'], true);
            if (is_array($global) && isset($global[$type]) && !$global[$type]) {
                return false;
            }
        }

        $userRow = Database::fetch(
            "SELECT value FROM settings WHERE `key` = ?",
            ["notification_preferences_$userId"]
        );
        if ($userRow) {
            $prefs = json_decode($userRow['value'], true);
            if (is_array($prefs) && isset($prefs[$type]) && !$prefs[$type]) {
                return false;
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

        // Session boundary'yi kaydet
        $tz = new DateTimeZone('Europe/Istanbul');
        $boundaryDt = new DateTime('now', $tz);
        $boundaryDt->setTimestamp(Auth::nextSessionBoundary());
        $expiresAt = $boundaryDt->format('Y-m-d H:i:s');

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
            'expires_at' => $expiresAt,
            'logged_in_at' => date('Y-m-d H:i:s'),
        ]);

        // Login bildirimi
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

    /**
     * Authenticated session olustur ve response don.
     */
    private function createAuthenticatedSession(array $user): void
    {
        $token = Auth::generateToken([
            'userId' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'branchId' => $user['branch_id'],
        ]);

        $roleMap = [1 => 'admin', 0 => 'kullanici', 4 => 'stajer'];
        $roleName = $roleMap[(int) $user['role']] ?? 'kullanici';

        $this->logSession((int) $user['id'], $token);

        $socketToken = Auth::generateSocketToken((int) $user['id'], $roleName);

        Response::success([
            'token' => $token,
            'socketToken' => $socketToken,
            'sessionExpiresAt' => Auth::sessionBoundaryISO(),
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

    // -------------------------------------------------------
    // Auth Endpoints
    // -------------------------------------------------------

    public function login(array $input): void
    {
        // Email normalize: trim + lowercase
        if (isset($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']), 'UTF-8');
        }

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

        // Musteri portali kullanicilari CRM'e giremez
        if ((int) $user['role'] === 3) {
            Response::error('Bu hesap ile CRM\'e giris yapilamaz. Musteri portalini kullanin.', 403);
        }

        // Brute-force kilit kontrolu
        $this->checkTwoFactorLock($user);

        // TOTP her zaman zorunlu — kurulmus mu kontrol et
        $hasTotp = !empty($user['two_factor_enabled']) && (int) $user['two_factor_enabled'] === 1;
        $requiresSetup = !$hasTotp;

        // Challenge token olustur (userId aciga cikmaz)
        $challengeToken = $this->createChallengeToken((int) $user['id'], $requiresSetup);

        Response::success([
            'requiresTwoFactor' => true,
            'requiresSetup' => $requiresSetup,
            'challengeToken' => $challengeToken,
        ], $requiresSetup ? 'Iki faktorlu dogrulama kurulumu gerekli' : 'Iki faktorlu dogrulama gerekli');
    }

    public function logout(): void
    {
        // Server-side session revoke
        $token = Auth::getTokenFromHeader();
        if ($token) {
            $tokenHash = hash('sha256', $token);
            $session = Database::fetch(
                "SELECT id FROM sessions WHERE token_hash = ? AND is_revoked = 0",
                [$tokenHash]
            );
            if ($session) {
                Database::update('sessions', ['is_revoked' => 1], 'id = ?', [$session['id']]);
            }
        }

        Response::success(null, 'Cikis basarili');
    }

    public function me(): void
    {
        $reason = null;
        $tokenData = Auth::getCurrentUser($reason);
        if (!$tokenData) {
            Response::error('Oturum suresi dolmus', 401, ['reason' => $reason ?? 'session_expired']);
        }

        $user = Database::fetch(
            "SELECT id, name, email, role, branch_id, is_active, two_factor_enabled, phone, birth_date, address, country_id, city_id, district_id, onboarding_completed FROM users WHERE id = ? AND deleted_at IS NULL",
            [$tokenData['userId']]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        $roleMap = [1 => 'admin', 0 => 'kullanici', 4 => 'stajer'];
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
            'onboardingCompleted' => (bool) ($user['onboarding_completed'] ?? false),
            'sessionExpiresAt' => Auth::sessionBoundaryISO(),
            'socketToken' => Auth::generateSocketToken((int) $user['id'], $roleName),
        ]);
    }

    // -------------------------------------------------------
    // Notification Preferences
    // -------------------------------------------------------

    public function getNotificationPreferences(array $authUser): void
    {
        $userId = $authUser['userId'];

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
                $val = $input[$inputKey];
                // Email normalize: trim + lowercase
                if ($dbColumn === 'email' && is_string($val)) {
                    $val = mb_strtolower(trim($val), 'UTF-8');
                }
                $updateData[$dbColumn] = $val;
            }
        }

        if (empty($updateData)) {
            Response::error('Guncellenecek alan bulunamadi', 422);
        }

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
    // Sessions (Login History)
    // -------------------------------------------------------

    public function sessions(array $authUser): void
    {
        $userId = $authUser['userId'];

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $currentTokenHash = '';
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $currentTokenHash = hash('sha256', $matches[1]);
        }

        $sessions = Database::fetchAll(
            "SELECT id, ip_address, device, browser, os, location, city, country, country_code, language, token_hash, logged_in_at, expires_at
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
                'expiresAt' => $s['expires_at'],
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

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $currentTokenHash = hash('sha256', $matches[1]);
            if ($session['token_hash'] === $currentTokenHash) {
                Response::error('Aktif oturumunuzu silemezsiniz', 422);
            }
        }

        Database::update('sessions', ['is_revoked' => 1], 'id = ? AND user_id = ?', [$sessionId, $userId]);

        SocketEmitter::sessionsUpdated($userId);

        Response::success(null, 'Oturum sonlandirildi');
    }

    // -------------------------------------------------------
    // Two-Factor Authentication — Login akisi (Challenge Token ile)
    // -------------------------------------------------------

    /**
     * Login akisi icinde 2FA dogrulama.
     * Challenge token + TOTP kodu ile calisir.
     */
    public function twoFactorVerify(array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, ['challengeToken' => 'required', 'code' => 'required'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        // Challenge token dogrula
        $challenge = $this->validateChallengeToken($input['challengeToken']);
        if (!$challenge) {
            Response::error('Dogrulama suresi dolmus veya gecersiz istek. Tekrar giris yapin.', 401, ['reason' => 'challenge_expired']);
        }

        $userId = (int) $challenge['user_id'];
        $challengeId = (int) $challenge['id'];

        $user = Database::fetch(
            "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user) {
            $this->markChallengeUsed($challengeId);
            Response::error('Kullanici bulunamadi', 404);
        }

        // Brute-force kilit kontrolu
        $this->checkTwoFactorLock($user);

        $secret = $this->getUserTotpSecret($user);
        if (empty($secret)) {
            $this->markChallengeUsed($challengeId);
            Response::error('Iki faktorlu dogrulama yapilandirilmamis', 422);
        }

        $code = trim($input['code']);
        $verified = false;
        $recoveryCodeUsed = false;
        $usedStep = null;

        // TOTP kodu dene (6 haneli)
        if (preg_match('/^\d{6}$/', $code)) {
            $totpCode = str_pad($code, 6, '0', STR_PAD_LEFT);
            $lastStep = $user['two_factor_last_step'] !== null ? (int) $user['two_factor_last_step'] : null;
            $step = $this->verifyTotpCode($secret, $totpCode, 1, $lastStep);
            if ($step !== false) {
                $verified = true;
                $usedStep = $step;
            }
        }

        // TOTP basarisizsa recovery code dene
        if (!$verified) {
            $recoveryCodes = json_decode($user['two_factor_recovery_codes'] ?? '[]', true);
            if (is_array($recoveryCodes)) {
                foreach ($recoveryCodes as $idx => $storedCode) {
                    if (AuthCrypto::verifyRecoveryCode($code, $storedCode)) {
                        $verified = true;
                        $recoveryCodeUsed = true;

                        // Kullanilan kodu sil
                        array_splice($recoveryCodes, $idx, 1);
                        Database::update('users', [
                            'two_factor_recovery_codes' => json_encode(array_values($recoveryCodes)),
                        ], 'id = ?', [$userId]);
                        break;
                    }
                }
            }
        }

        if (!$verified) {
            // Challenge attempt artir
            $this->incrementChallengeAttempts($challengeId);
            // User attempt artir
            $this->recordFailedTwoFactor($userId);
            Response::error('Gecersiz dogrulama kodu', 401);
        }

        // Basarili — challenge'i kapat
        $this->markChallengeUsed($challengeId);

        // Attempt sayaci sifirla
        $this->clearTwoFactorAttempts($userId);

        // Replay korumasi: son kullanilan step'i kaydet
        if ($usedStep !== null) {
            Database::update('users', [
                'two_factor_last_step' => $usedStep,
            ], 'id = ?', [$userId]);
        }

        // Legacy plaintext secret varsa encrypted'a tasi
        if (!empty($user['two_factor_secret']) && empty($user['two_factor_secret_enc'])) {
            $this->saveTotpSecretEncrypted($userId, $user['two_factor_secret']);
        }

        // Authenticated session olustur
        $this->createAuthenticatedSession($user);
    }

    // -------------------------------------------------------
    // Two-Factor Authentication — Kurulum (Challenge Token ile / Authenticated)
    // -------------------------------------------------------

    /**
     * 2FA kurulumu baslat.
     * Login akisinda challengeToken ile, CRM icinde auth ile calisir.
     */
    public function twoFactorSetup($authOrInput): void
    {
        $userId = null;

        // Challenge token ile gelen istek (login akisi)
        if (is_array($authOrInput) && isset($authOrInput['challengeToken'])) {
            $challenge = $this->validateChallengeToken($authOrInput['challengeToken']);
            if (!$challenge || !(int) $challenge['requires_setup']) {
                Response::error('Gecersiz veya suresi dolmus istek', 401, ['reason' => 'challenge_expired']);
            }
            $userId = (int) $challenge['user_id'];
        }
        // Authenticated user (ayarlar sayfasindan)
        elseif (is_array($authOrInput) && isset($authOrInput['userId'])) {
            $userId = (int) $authOrInput['userId'];
        }

        if (!$userId) {
            Response::error('Gecersiz istek', 400);
        }

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

        // 16 karakter base32 secret olustur
        $secret = $this->generateBase32Secret(16);

        // Secret'i encrypted olarak kaydet
        $this->saveTotpSecretEncrypted($userId, $secret);

        // otpauth URI
        $otpauthUrl = 'otpauth://totp/SigortaCRM:' . urlencode($user['email'])
            . '?secret=' . $secret
            . '&issuer=SigortaCRM'
            . '&digits=6'
            . '&period=30';

        Response::success([
            'secret' => $secret,
            'otpauthUrl' => $otpauthUrl,
        ], 'Iki faktorlu dogrulama kurulumu hazir');
    }

    /**
     * 2FA etkinlestir (kod dogrulama + recovery code uretimi).
     * Login akisinda challengeToken ile, CRM icinde auth ile calisir.
     */
    public function twoFactorEnable($authOrInput, ?array $input = null): void
    {
        $userId = null;
        $challengeId = null;
        $isLoginFlow = false;

        // Input parametresini belirle
        $actualInput = $input ?? $authOrInput;

        // Challenge token ile gelen istek
        if (isset($actualInput['challengeToken'])) {
            $challenge = $this->validateChallengeToken($actualInput['challengeToken']);
            if (!$challenge || !(int) $challenge['requires_setup']) {
                Response::error('Gecersiz veya suresi dolmus istek', 401, ['reason' => 'challenge_expired']);
            }
            $userId = (int) $challenge['user_id'];
            $challengeId = (int) $challenge['id'];
            $isLoginFlow = true;
        }
        // Authenticated user
        elseif (is_array($authOrInput) && isset($authOrInput['userId']) && $input !== null) {
            $userId = (int) $authOrInput['userId'];
            $actualInput = $input;
        }

        if (!$userId) {
            Response::error('Gecersiz istek', 400);
        }

        $validator = new Validator();
        if (!$validator->validate($actualInput, ['code' => 'required'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $user = Database::fetch(
            "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user) {
            Response::error('Kullanici bulunamadi', 404);
        }

        $secret = $this->getUserTotpSecret($user);
        if (empty($secret)) {
            Response::error('Once iki faktorlu dogrulama kurulumunu baslatin', 422);
        }

        if (!empty($user['two_factor_enabled']) && (int) $user['two_factor_enabled'] === 1) {
            Response::error('Iki faktorlu dogrulama zaten aktif', 422);
        }

        $code = str_pad(trim($actualInput['code']), 6, '0', STR_PAD_LEFT);

        $step = $this->verifyTotpCode($secret, $code);
        if ($step === false) {
            Response::error('Gecersiz dogrulama kodu', 401);
        }

        // Recovery code'lari uret ve hash'le
        $plaintextCodes = $this->generateRecoveryCodes(8);
        $hashedCodes = array_map(function ($c) {
            return AuthCrypto::hashRecoveryCode($c);
        }, $plaintextCodes);

        Database::update('users', [
            'two_factor_enabled' => 1,
            'two_factor_recovery_codes' => json_encode($hashedCodes),
            'two_factor_last_step' => $step,
        ], 'id = ?', [$userId]);

        // Login akisinda challenge'i kapat ama henuz session olusturma
        // Frontend recovery kodlari gosterdikten sonra ayri bir confirm istegi atacak
        if ($isLoginFlow && $challengeId) {
            // Challenge'i hala acik birak — confirm adiminda kullanilacak
            // Ama attempts'i sifirla
            Database::update('auth_challenges', ['attempts' => 0], 'id = ?', [$challengeId]);
        }

        Response::success([
            'recoveryCodes' => $plaintextCodes,
        ], 'Iki faktorlu dogrulama basariyla etkinlestirildi');
    }

    /**
     * Login akisinda 2FA enrollment tamamlandiktan sonra session olustur.
     */
    public function twoFactorConfirmSetup(array $input): void
    {
        $validator = new Validator();
        if (!$validator->validate($input, ['challengeToken' => 'required'])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $challenge = $this->validateChallengeToken($input['challengeToken']);
        if (!$challenge) {
            Response::error('Gecersiz veya suresi dolmus istek. Tekrar giris yapin.', 401, ['reason' => 'challenge_expired']);
        }

        $userId = (int) $challenge['user_id'];

        $user = Database::fetch(
            "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND two_factor_enabled = 1",
            [$userId]
        );

        if (!$user) {
            Response::error('2FA kurulumu tamamlanmamis', 422);
        }

        // Challenge'i kapat
        $this->markChallengeUsed((int) $challenge['id']);

        // Authenticated session olustur
        $this->createAuthenticatedSession($user);
    }

    public function twoFactorDisable(array $authUser, array $input): void
    {
        // 2FA zorunlu — normal kullanıcı self-service disable yapamaz
        // Sadece admin başka kullanıcının 2FA'sını sıfırlayabilir (ileride eklenecek)
        if ((int) $authUser['role'] !== 1) {
            Response::error('İki adımlı doğrulama CRM girişinde zorunludur ve devre dışı bırakılamaz.', 403);
        }

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
            'two_factor_secret_enc' => null,
            'two_factor_secret_iv' => null,
            'two_factor_secret_tag' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_attempts' => 0,
            'two_factor_locked_until' => null,
            'two_factor_last_step' => null,
        ], 'id = ?', [$userId]);

        Response::success(null, 'Iki faktorlu dogrulama devre disi birakildi');
    }
}
