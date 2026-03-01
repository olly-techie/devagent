<?php
declare(strict_types=1);

namespace DevAgent\Security;

/**
 * AES-256-GCM encryption for sensitive values stored in the database.
 *
 * Encrypted format (stored as a single base64 string):
 *   base64( IV[12] || TAG[16] || CIPHERTEXT[N] )
 *
 * Key must be 32 bytes (256 bits). Provide as a 64-char hex string in .env:
 *   ENCRYPTION_KEY=<openssl rand -hex 32>
 */
final class Encryption
{
    private const CIPHER    = 'aes-256-gcm';
    private const IV_LEN    = 12;  // GCM recommended IV length
    private const TAG_LEN   = 16;  // GCM authentication tag length
    private const PREFIX    = 'enc:v1:'; // versioned prefix to detect encrypted values

    private string $key;

    public function __construct()
    {
        $hexKey = env('ENCRYPTION_KEY')
            ?? throw new \RuntimeException('ENCRYPTION_KEY is not set in environment');

        if (strlen($hexKey) !== 64) {
            throw new \RuntimeException('ENCRYPTION_KEY must be exactly 64 hex characters (32 bytes)');
        }

        $this->key = hex2bin($hexKey);
    }

    /**
     * Encrypt a plaintext string. Returns a base64-encoded ciphertext with prefix.
     */
    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value produced by encrypt(). Returns the original plaintext.
     * Throws on tampered or invalid input (GCM authentication failure).
     */
    public function decrypt(string $encrypted): string
    {
        if (!str_starts_with($encrypted, self::PREFIX)) {
            throw new \RuntimeException('Invalid encrypted value: missing version prefix');
        }

        $payload = base64_decode(substr($encrypted, strlen(self::PREFIX)), strict: true);

        if ($payload === false || strlen($payload) < self::IV_LEN + self::TAG_LEN + 1) {
            throw new \RuntimeException('Invalid encrypted value: malformed payload');
        }

        $iv         = substr($payload, 0, self::IV_LEN);
        $tag        = substr($payload, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($payload, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // GCM tag mismatch = ciphertext was tampered with
            throw new \RuntimeException('Decryption failed: authentication tag mismatch — data may be corrupted or tampered');
        }

        return $plaintext;
    }

    /**
     * Returns true if a value looks like it was produced by encrypt().
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Singleton accessor — avoids recreating the instance on every call.
     */
    public static function instance(): self
    {
        static $inst = null;
        return $inst ??= new self();
    }
}
