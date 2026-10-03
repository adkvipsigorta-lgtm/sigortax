<?php

/**
 * TOTP secret ve hassas auth verilerinin AES-256-GCM ile sifrelenmesi.
 *
 * DocumentCrypto'dan farkli olarak dosya degil kisa stringler icin optimize.
 * Ayni master key dosyasini (.storage_key) kullanir — key rotation uyumlu.
 */
class AuthCrypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    /**
     * Master key'i oku. DocumentCrypto ile ayni kaynak.
     */
    private static function getMasterKey(): string
    {
        require_once __DIR__ . '/DocumentCrypto.php';
        return DocumentCrypto::getMasterKey();
    }

    /**
     * Plaintext string'i sifrele.
     * Donus: ['ciphertext' => base64, 'iv' => base64, 'tag' => base64]
     */
    public static function encrypt(string $plaintext): array
    {
        $key = self::getMasterKey();
        $iv = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AuthCrypto: sifrelemesi basarisiz');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($tag),
        ];
    }

    /**
     * Sifreli veriyi coz.
     */
    public static function decrypt(string $ciphertextB64, string $ivB64, string $tagB64): string
    {
        $key = self::getMasterKey();
        $ciphertext = base64_decode($ciphertextB64, true);
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);

        if ($ciphertext === false || $iv === false || $tag === false) {
            throw new RuntimeException('AuthCrypto: base64 decode basarisiz');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('AuthCrypto: cozulemedi (bozuk veya master key eslesmiyor)');
        }

        return $plaintext;
    }

    /**
     * Recovery code'u hash'le (tek yonlu).
     * password_hash kullaniyoruz — timing-safe verify icin.
     */
    public static function hashRecoveryCode(string $code): string
    {
        return password_hash(strtolower(trim($code)), PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * Recovery code dogrulama.
     */
    public static function verifyRecoveryCode(string $code, string $hash): bool
    {
        // Eski plaintext format destegi (gecis donemi)
        if (!str_starts_with($hash, '$2y$') && !str_starts_with($hash, '$2b$')) {
            return hash_equals(strtolower(trim($hash)), strtolower(trim($code)));
        }
        return password_verify(strtolower(trim($code)), $hash);
    }
}
