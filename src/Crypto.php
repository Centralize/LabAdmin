<?php
declare(strict_types=1);

namespace LabAdmin;

use RuntimeException;

/**
 * Encrypts stored SSH secrets (passwords / private keys) at rest with AES-256-GCM.
 * The master key lives in data/secret.key (created on first use, chmod 600).
 * Keep that file out of backups you share - without it the secrets cannot be decrypted.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $file = $GLOBALS['LABADMIN_CONFIG']['data_dir'] . '/secret.key';
        if (!is_file($file)) {
            $raw = random_bytes(32);
            if (file_put_contents($file, base64_encode($raw), LOCK_EX) === false) {
                throw new RuntimeException('Cannot write ' . $file);
            }
            @chmod($file, 0600);
        }
        $raw = base64_decode(trim((string) file_get_contents($file)), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('data/secret.key is corrupt');
        }
        return self::$key = $raw;
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(?string $enc): ?string
    {
        if ($enc === null || $enc === '') {
            return null;
        }
        if (!str_starts_with($enc, 'v1:')) {
            throw new RuntimeException('Unknown secret format');
        }
        $bin = base64_decode(substr($enc, 3), true);
        if ($bin === false || strlen($bin) < 29) {
            throw new RuntimeException('Corrupt secret');
        }
        $plain = openssl_decrypt(substr($bin, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Cannot decrypt secret (wrong data/secret.key?)');
        }
        return $plain;
    }
}
