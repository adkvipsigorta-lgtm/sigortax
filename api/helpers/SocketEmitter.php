<?php

/**
 * Tek event (evt) + type ile Socket.IO relay.
 * Socket server sadece payload'u ilgili room'a iletir.
 * Frontend type'a gore dispatch yapar.
 */
class SocketEmitter
{
    private static string $url = '';
    private static string $secret = '';

    private static function init(): void
    {
        if (self::$url) return;
        self::$url = SOCKET_URL;
        self::$secret = SOCKET_API_SECRET;
    }

    /**
     * Room'a { type, ...data } gonder
     */
    public static function send(string $room, string $type, array $data = []): bool
    {
        self::init();

        // NOT: array_merge'de sag taraf onceliklidir. Event type'i ($type) korumak icin
        // $data once gelir, sonra event type ile uzerine yazilir; boylece $data icindeki
        // 'type' alani (ornegin notification icin 'info', 'warning') event type'i ezmez.
        $payload = json_encode([
            'secret' => self::$secret,
            'room' => $room,
            'payload' => array_merge($data, ['type' => $type]),
        ]);

        $ch = curl_init(self::$url . '/emit');
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ];
        // CA bundle yolunu ini'den bagimsiz olarak elde et (PHP CLI server bazi sistemlerde
        // ini'deki curl.cainfo'yu ihmal ediyor, bu yuzden olasi yollari sirayla deneriz).
        $caCandidates = [
            ini_get('curl.cainfo'),
            ini_get('openssl.cafile'),
            __DIR__ . '/../../wamp/SecureWAMP_Portable/php/extras/ssl/cacert.pem',
            'C:/Users/smart/Desktop/wamp/SecureWAMP_Portable/php/extras/ssl/cacert.pem',
            __DIR__ . '/cacert.pem',
        ];
        foreach ($caCandidates as $ca) {
            if ($ca && @is_file($ca)) {
                $opts[CURLOPT_CAINFO] = $ca;
                break;
            }
        }
        curl_setopt_array($ch, $opts);

        curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        return $ok;
    }

    // --- Kisayollar ---

    public static function toUser(int $userId, string $type, array $data = []): bool
    {
        return self::send("user:$userId", $type, $data);
    }

    public static function toTenant(string $tenant, string $type, array $data = []): bool
    {
        return self::send("tenant:$tenant", $type, $data);
    }

    public static function toAdmins(string $tenant, string $type, array $data = []): bool
    {
        return self::send("role:admin:$tenant", $type, $data);
    }

    // --- Hazir tipler ---

    public static function notifyUser(int $userId, array $notification): bool
    {
        return self::toUser($userId, 'notification', $notification);
    }

    public static function kickUser(int $userId, string $reason = 'revoked'): bool
    {
        return self::toUser($userId, 'session-revoked', ['reason' => $reason]);
    }

    public static function sessionsUpdated(int $userId): bool
    {
        return self::toUser($userId, 'sessions-updated');
    }
}
