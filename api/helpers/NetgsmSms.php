<?php

class NetgsmSms
{
    /**
     * Netgsm üzerinden SMS gönder
     */
    public static function send(string $phone, string $message): array
    {
        // Ayarları DB'den çek
        $usercode = self::getSetting('netgsm_usercode');
        $password = self::getSetting('netgsm_password');
        $msgheader = self::getSetting('netgsm_msgheader');
        $enabled = self::getSetting('netgsm_enabled');

        if ($enabled !== 'true' && $enabled !== '1') {
            return ['success' => false, 'error' => 'SMS servisi aktif değil'];
        }

        if (!$usercode || !$password || !$msgheader) {
            return ['success' => false, 'error' => 'Netgsm API bilgileri eksik'];
        }

        // Telefon numarasını temizle
        $phone = self::cleanPhone($phone);
        if (!$phone) {
            return ['success' => false, 'error' => 'Geçersiz telefon numarası'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<mainbody>
    <header>
        <company dession="' . htmlspecialchars($usercode) . '"/>
        <usercode>' . htmlspecialchars($usercode) . '</usercode>
        <password>' . htmlspecialchars($password) . '</password>
        <type>1:n</type>
        <msgheader>' . htmlspecialchars($msgheader) . '</msgheader>
    </header>
    <body>
        <msg><![CDATA[' . $message . ']]></msg>
        <no>' . $phone . '</no>
    </body>
</mainbody>';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.netgsm.com.tr/sms/send/xml',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'Bağlantı hatası: ' . $error];
        }

        // Netgsm yanıt kodları: 00, 01, 02 = başarılı
        $responseCode = trim(explode(' ', trim($response))[0] ?? '');
        if (in_array($responseCode, ['00', '01', '02'])) {
            return ['success' => true, 'response' => $response];
        }

        return ['success' => false, 'error' => 'SMS gönderilemedi (Kod: ' . $responseCode . ')', 'response' => $response];
    }

    /**
     * Telefon numarasını 5XXXXXXXXX formatına çevir
     */
    private static function cleanPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // +90 veya 90 ile başlıyorsa kaldır
        if (str_starts_with($phone, '90') && strlen($phone) === 12) {
            $phone = substr($phone, 2);
        }
        // 0 ile başlıyorsa kaldır
        if (str_starts_with($phone, '0') && strlen($phone) === 11) {
            $phone = substr($phone, 1);
        }
        // 5 ile başlamalı ve 10 hane olmalı
        if (strlen($phone) === 10 && str_starts_with($phone, '5')) {
            return $phone;
        }

        return '';
    }

    private static function getSetting(string $key): string
    {
        $row = Database::fetch("SELECT value FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? '';
    }
}
