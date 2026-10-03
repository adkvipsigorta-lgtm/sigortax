<?php

require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/Response.php';

class AuthMiddleware
{
    public static function handle(): array
    {
        $reason = null;
        $user = Auth::getCurrentUser($reason);
        if (!$user) {
            $messages = [
                'invalid_token'   => 'Gecersiz veya eksik token',
                'session_expired' => 'Oturum suresi dolmus',
                'session_revoked' => 'Oturum sonlandirilmis',
                'forced_logout'   => 'Oturum suresi sona erdi',
            ];
            $msg = $messages[$reason ?? ''] ?? 'Oturum suresi dolmus veya gecersiz token';
            Response::error($msg, 401, ['reason' => $reason ?? 'session_expired']);
        }
        // Musteri portali kullanicilari CRM'e erisemez
        if ((int) $user['role'] === 3) {
            Response::error('Bu alana erisim yetkiniz yok', 403);
        }
        return $user;
    }

    public static function requireAdmin(array $user): void
    {
        if ((int) $user['role'] !== 1) {
            Response::error('Bu islem icin yetkiniz yok', 403);
        }
    }

    public static function requireAdminOrSelf(array $user, int $targetId): void
    {
        if ((int) $user['role'] !== 1 && (int) $user['userId'] !== $targetId) {
            Response::error('Bu islem icin yetkiniz yok', 403);
        }
    }

    public static function denyAgent(array $user): void
    {
        if ((int) $user['role'] === 2) {
            Response::error('Bu islem icin yetkiniz yok', 403);
        }
    }
}
