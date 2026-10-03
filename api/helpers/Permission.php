<?php

class Permission
{
    // Rol bazli varsayilan izinler
    // admin (1): tum izinler acik
    // kullanici (0): sadece view izinleri acik
    // acente (2): sadece view izinleri acik
    private static array $roleDefaults = [
        1 => '*',  // admin: hepsi acik
        0 => [     // kullanici: temel islemler
            'customers.view',
            'policies.view',
            'policies.manage',
            'tasks.view',
            'tasks.manage',
            'lost_policies.view',
            'lost_policies.manage',
            'offers.view',
            'messages.view',
            'messages.send',
            'performance.view',
            'portfolio.view',
            'tools.cross_sell',
            'tools.reconciliation',
            'leads.view',
            'leads.manage',
        ],
        2 => [     // acente: sinirli erisim
            'customers.view',
            'policies.view',
            'tasks.view',
            'offers.view',
        ],
    ];

    /**
     * Kullanicinin belirli bir izne sahip olup olmadigini kontrol et
     */
    public static function has(array $user, string $permissionKey): bool
    {
        $userId = (int) $user['userId'];
        $role = (int) $user['role'];

        // user_permissions tablosunda override var mi?
        $override = Database::fetch(
            "SELECT allowed FROM user_permissions WHERE user_id = ? AND permission_key = ?",
            [$userId, $permissionKey]
        );

        if ($override !== false && $override !== null) {
            return (bool) $override['allowed'];
        }

        // Override yoksa rol varsayilanina bak
        return self::roleHasDefault($role, $permissionKey);
    }

    /**
     * Rol bazli varsayilan izin kontrolu
     */
    private static function roleHasDefault(int $role, string $permissionKey): bool
    {
        $defaults = self::$roleDefaults[$role] ?? [];

        if ($defaults === '*') return true;

        return in_array($permissionKey, $defaults);
    }

    /**
     * Izin yoksa 403 dondur
     */
    public static function require(array $user, string $permissionKey): void
    {
        if (!self::has($user, $permissionKey)) {
            Response::error('Bu islem icin yetkiniz yok', 403);
        }
    }

    /**
     * Kullanicinin tum izinlerini getir (UI icin)
     */
    public static function getAllForUser(int $userId, int $role): array
    {
        // Tum izin tanimlari
        $permissions = Database::fetchAll("SELECT * FROM permissions ORDER BY sort_order ASC");

        // Kullanicinin override'lari
        $overrides = Database::fetchAll(
            "SELECT permission_key, allowed FROM user_permissions WHERE user_id = ?",
            [$userId]
        );
        $overrideMap = [];
        foreach ($overrides as $o) {
            $overrideMap[$o['permission_key']] = (bool) $o['allowed'];
        }

        $result = [];
        foreach ($permissions as $p) {
            $key = $p['key'];
            $hasOverride = array_key_exists($key, $overrideMap);

            $result[] = [
                'key' => $key,
                'group' => $p['group'],
                'groupLabel' => $p['group_label'],
                'label' => $p['label'],
                'allowed' => $hasOverride ? $overrideMap[$key] : self::roleHasDefault($role, $key),
                'isOverride' => $hasOverride,
            ];
        }

        return $result;
    }

    /**
     * Kullanicinin izinlerini toplu guncelle
     */
    public static function updateForUser(int $userId, array $permissions): void
    {
        $pdo = Database::getInstance();

        // Mevcut override'lari sil
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$userId]);

        // Yeni override'lari ekle (sadece rol varsayilanindan farkli olanlar)
        $user = Database::fetch("SELECT role FROM users WHERE id = ?", [$userId]);
        if (!$user) return;

        $role = (int) $user['role'];

        $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, allowed) VALUES (?, ?, ?)");
        foreach ($permissions as $key => $allowed) {
            $default = self::roleHasDefault($role, $key);
            $allowed = (bool) $allowed;

            // Sadece varsayilandan farkli olanlari kaydet
            if ($allowed !== $default) {
                $stmt->execute([$userId, $key, $allowed ? 1 : 0]);
            }
        }
    }
}
