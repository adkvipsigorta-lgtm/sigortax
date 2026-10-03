<?php

/**
 * Envelope encryption için yardımcı sınıf.
 *
 * Mimari:
 *   Master Key  = api/.storage_key dosyasinda (versiyon kontrolune GIRMEZ)
 *   DEK         = her dosya icin rastgele 32 byte (Data Encryption Key)
 *   Dosya      = DEK ile AES-256-GCM ile sifrelenir, disk'te .enc
 *   DEK        = Master key ile AES-256-GCM ile sifrelenir, DB'de saklanir
 *
 * Sizinti senaryolari:
 *   - Sadece DB sizarsa   → DEK'ler sifreli, master key olmadan cozulemez
 *   - Sadece disk sizarsa → dosya DEK ile sifreli, DEK yok
 *   - DB + disk + master sizarsa → savunma dusmus
 */
class DocumentCrypto
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_LEN = 32;
    private const IV_LEN = 12;         // GCM icin onerilen
    private const TAG_LEN = 16;
    private const CHUNK_SIZE = 65536;  // 64 KB

    /**
     * Master key dosyasi yolu.
     */
    public static function masterKeyPath(): string
    {
        return realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . '.storage_key';
    }

    /**
     * Master key'i oku, yoksa olustur. Return: 32 byte binary.
     */
    public static function getMasterKey(): string
    {
        $path = self::masterKeyPath();
        if (!is_file($path)) {
            $key = random_bytes(self::KEY_LEN);
            file_put_contents($path, $key, LOCK_EX);
            @chmod($path, 0600);
        }
        $key = file_get_contents($path);
        if (strlen($key) !== self::KEY_LEN) {
            throw new RuntimeException('Master key dosyasi bozuk ya da eksik (32 byte bekleniyor).');
        }
        return $key;
    }

    /**
     * Yeni rastgele DEK uret (32 byte).
     */
    public static function generateDek(): string
    {
        return random_bytes(self::KEY_LEN);
    }

    /**
     * DEK'i master key ile sifrele. Donus: ['ciphertext', 'iv', 'tag']
     */
    public static function encryptDek(string $dek): array
    {
        $master = self::getMasterKey();
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($dek, self::CIPHER, $master, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($cipher === false) {
            throw new RuntimeException('DEK sifrelemesi basarisiz.');
        }
        return ['ciphertext' => $cipher, 'iv' => $iv, 'tag' => $tag];
    }

    /**
     * DB'den gelen encrypted DEK'i master key ile coz. Donus: 32 byte DEK.
     */
    public static function decryptDek(string $cipherDek, string $iv, string $tag): string
    {
        $master = self::getMasterKey();
        $plain = openssl_decrypt($cipherDek, self::CIPHER, $master, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false || strlen($plain) !== self::KEY_LEN) {
            throw new RuntimeException('DEK cozulemedi (bozuk veya master key eslesmiyor).');
        }
        return $plain;
    }

    /**
     * Dosyayi DEK ile sifrele ve diske yaz. Tek seferde (bellekte) sifreler — 20MB limitli.
     * Donus: ['iv', 'tag'] (DB'de saklanacak)
     */
    public static function encryptFile(string $plainSourcePath, string $targetEncryptedPath, string $dek): array
    {
        if (!is_file($plainSourcePath)) {
            throw new RuntimeException('Kaynak dosya bulunamadi.');
        }
        $plain = file_get_contents($plainSourcePath);
        if ($plain === false) {
            throw new RuntimeException('Kaynak dosya okunamadi.');
        }
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $dek, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($cipher === false) {
            throw new RuntimeException('Dosya sifrelemesi basarisiz.');
        }

        // Hedef klasoru olustur
        $dir = dirname($targetEncryptedPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $bytes = file_put_contents($targetEncryptedPath, $cipher, LOCK_EX);
        if ($bytes === false) {
            throw new RuntimeException('Sifreli dosya diske yazilamadi.');
        }

        return ['iv' => $iv, 'tag' => $tag];
    }

    /**
     * Sifreli dosyayi DEK ile coz ve stream olarak output'a yaz.
     * Cok buyuk dosyalar icin bellek dostu degildir ama 20MB limitte sorun yok.
     */
    public static function decryptFileToOutput(string $encryptedPath, string $dek, string $iv, string $tag): void
    {
        if (!is_file($encryptedPath)) {
            throw new RuntimeException('Sifreli dosya bulunamadi.');
        }
        $cipher = file_get_contents($encryptedPath);
        if ($cipher === false) {
            throw new RuntimeException('Sifreli dosya okunamadi.');
        }
        $plain = openssl_decrypt($cipher, self::CIPHER, $dek, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Dosya cozulemedi (bozuk veya tampered).');
        }
        echo $plain;
    }

    /**
     * Rastgele depolama hash'i uret (64 hex karakter).
     */
    public static function newStorageHash(): string
    {
        return bin2hex(random_bytes(16)) . bin2hex(random_bytes(16));
    }

    /**
     * Hash'ten disk yolu uret: ab/cd/abcd...enc
     */
    public static function storagePathFromHash(string $hash): string
    {
        $uploads = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documents';
        return $uploads . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . substr($hash, 2, 2) . DIRECTORY_SEPARATOR . $hash . '.enc';
    }
}
