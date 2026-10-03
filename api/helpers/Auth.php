<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Database.php';

class Auth
{
    /**
     * Sonraki zorunlu logout boundary'sini hesapla (Europe/Istanbul).
     * Donemler: gun baslangici→12:30, 12:30→17:30, 17:30→ertesi gun 12:30
     */
    public static function nextSessionBoundary(): int
    {
        $tz = new DateTimeZone('Europe/Istanbul');
        $now = new DateTime('now', $tz);

        $today = $now->format('Y-m-d');
        $cutoff1 = new DateTime("$today 12:30:00", $tz);
        $cutoff2 = new DateTime("$today 17:30:00", $tz);

        if ($now < $cutoff1) {
            return $cutoff1->getTimestamp();
        } elseif ($now < $cutoff2) {
            return $cutoff2->getTimestamp();
        } else {
            $tomorrow = new DateTime("$today 12:30:00", $tz);
            $tomorrow->modify('+1 day');
            return $tomorrow->getTimestamp();
        }
    }

    /**
     * Session boundary'yi ISO-8601 formatinda don.
     */
    public static function sessionBoundaryISO(): string
    {
        $tz = new DateTimeZone('Europe/Istanbul');
        $dt = new DateTime('now', $tz);
        $dt->setTimestamp(self::nextSessionBoundary());
        return $dt->format('c'); // ISO-8601 with offset
    }

    public static function generateToken(array $payload, bool $rememberMe = false, ?int $ttl = null): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

        $payload['iat'] = time();

        // Session boundary: JWT exp asla boundary'yi asamaz
        $boundary = self::nextSessionBoundary();
        if ($ttl !== null) {
            $payload['exp'] = min(time() + $ttl, $boundary);
        } else {
            // rememberMe artik token suresini uzatmaz, sadece email hatirlama icin
            $payload['exp'] = $boundary;
        }

        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
        );
        return "$header.$payloadEncoded.$signature";
    }

    /**
     * Socket.IO icin token uret (ayri secret).
     * Ayni session boundary ile sinirlanir.
     */
    public static function generateSocketToken(int $userId, string $role): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $boundary = self::nextSessionBoundary();
        $payload = [
            'userId' => $userId,
            'role' => $role,
            'iat' => time(),
            'exp' => $boundary,
        ];
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payloadEncoded", SOCKET_JWT_SECRET, true)
        );
        return "$header.$payloadEncoded.$signature";
    }

    public static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;

        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
        );

        if (!hash_equals($expectedSignature, $signature)) return null;

        $data = json_decode(self::base64UrlDecode($payload), true);
        if (!$data || !isset($data['exp']) || $data['exp'] < time()) return null;

        return $data;
    }

    public static function getTokenFromHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }
        // Fallback: query param for file download/export endpoints
        if (isset($_GET['token'])) {
            return $_GET['token'];
        }
        return null;
    }

    /**
     * Mevcut kullaniciyi dogrula. Basarisizsa null doner.
     * Basarili olursa session last_active_at gunceller.
     * 401 reason bilgisi icin $reason referansi kullanilir.
     */
    public static function getCurrentUser(?string &$reason = null): ?array
    {
        $reason = null;
        $token = self::getTokenFromHeader();
        if (!$token) {
            $reason = 'invalid_token';
            return null;
        }

        $data = self::verifyToken($token);
        if (!$data) {
            $reason = 'session_expired';
            return null;
        }

        // Token revoke kontrolu
        $tokenHash = hash('sha256', $token);
        $session = Database::fetch(
            "SELECT id, is_revoked, expires_at FROM sessions WHERE token_hash = ?",
            [$tokenHash]
        );

        if ($session && (int) $session['is_revoked'] === 1) {
            $reason = 'session_revoked';
            return null;
        }

        // Defense-in-depth: sessions.expires_at kontrolu
        if ($session && !empty($session['expires_at'])) {
            $tz = new DateTimeZone('Europe/Istanbul');
            $expiresAt = new DateTime($session['expires_at'], $tz);
            $now = new DateTime('now', $tz);
            if ($now >= $expiresAt) {
                $reason = 'forced_logout';
                return null;
            }
        }

        // Session last_active_at guncelle (inactivity timeout altyapisi)
        if ($session) {
            Database::update('sessions', [
                'last_active_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$session['id']]);
        }

        return $data;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
