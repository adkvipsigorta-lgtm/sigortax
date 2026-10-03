<?php

class SettingsController
{
    public function index(): void
    {
        $options = Database::fetchAll(
            "SELECT `key`, `value` FROM settings"
        );

        $result = [];
        $geminiKey = '';
        foreach ($options as $opt) {
            if ($opt['key'] === 'gemini_api_key') {
                $geminiKey = $opt['value'] ?? '';
                $result['gemini_api_key'] = ($geminiKey !== '')
                    ? str_repeat('*', max(0, strlen($geminiKey) - 4)) . substr($geminiKey, -4)
                    : '';
                continue;
            }
            if ($opt['key'] === 'netgsm_password') {
                $np = $opt['value'] ?? '';
                $result['netgsm_password'] = ($np !== '')
                    ? str_repeat('*', max(0, strlen($np) - 3)) . substr($np, -3)
                    : '';
                continue;
            }
            $decoded = json_decode($opt['value'], true);
            $result[$opt['key']] = $decoded !== null ? $decoded : $opt['value'];
        }
        $result['pdf_parsing_enabled'] = ($geminiKey !== '');

        Response::success($result);
    }

    public function save(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        foreach ($input as $key => $value) {
            // Maskelenmiş key gelirse (değişmemiş) atla
            if ($key === 'gemini_api_key' && is_string($value) && str_contains($value, '***')) {
                continue;
            }
            if ($key === 'netgsm_password' && is_string($value) && str_contains($value, '***')) {
                continue;
            }
            $metaValue = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;

            $existing = Database::fetch(
                "SELECT id FROM settings WHERE `key` = ?",
                [$key]
            );

            if ($existing) {
                Database::update('settings', [
                    'value' => $metaValue,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
            } else {
                Database::insert('settings', [
                    'key' => $key,
                    'value' => $metaValue,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        Response::success(null, 'Ayarlar kaydedildi');
    }
}
