<?php

require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/Response.php';

class AuthMiddleware
{
    public static function handle(): array
    {
        $user = Auth::getCurrentUser();
        if (!$user) {
            Response::error('Oturum suresi dolmus veya gecersiz token', 401);
        }
        // Müşteri portalı kullanıcıları CRM'e erişemez
        if ((int) $user['role'] === 3) {
            Response::error('Bu alana erişim yetkiniz yok', 403);
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
