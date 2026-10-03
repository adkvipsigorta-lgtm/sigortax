<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Database.php';

class Auth
{
    public static function generateToken(array $payload, bool $rememberMe = false, ?int $ttl = null): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + ($ttl ?? ($rememberMe ? 86400 * 30 : JWT_EXPIRY));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
        );
        return "$header.$payloadEncoded.$signature";
    }

    /**
     * Socket.IO icin kisa sureli token uret (ayri secret)
     */
    public static function generateSocketToken(int $userId, string $role): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = [
            'userId' => $userId,
            'role' => $role,
            'iat' => time(),
            'exp' => time() + JWT_EXPIRY, // ana authToken ile ayni omur (drift olmasin)
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

    public static function getCurrentUser(): ?array
    {
        $token = self::getTokenFromHeader();
        if (!$token) return null;

        $data = self::verifyToken($token);
        if (!$data) return null;

        // Check if token is revoked
        $tokenHash = hash('sha256', $token);
        $revoked = Database::fetch(
            "SELECT id FROM sessions WHERE token_hash = ? AND is_revoked = 1",
            [$tokenHash]
        );
        if ($revoked) return null;

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
