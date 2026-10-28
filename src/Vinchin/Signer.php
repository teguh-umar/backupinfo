<?php

namespace GlpiPlugin\Backupinfo\Vinchin;

/**
 * Vinchin console request signer (port of the Python client's sign_params / rsa_encrypt_b64).
 * KEY, IV and the RSA public key are static Vinchin console constants, not customer secrets.
 */
final class Signer
{
    private const KEY = '7ebec7acd38b0643c34b09c84ea54393';
    private const IV = 'sNONwyJtvi2ch2in';
    private const PUBLIC_KEY_B64 = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCq/O2gNw8H3RNTCfJlXYWTPX3JmTeKoPr6lCaHpEBgBg9FTt7Ftu+zYVaPtBLt'
        . 'PVYpxXzHdnjrkFThCuI30TZnh23RbU92Ap67IV6V0DqL3gQRPVsEgRCeH7Uwsvt5/YFZTgUT/V+hW5+Hq6fjMf8ghaAFDro/vHElH2FA'
        . 'evLy0wIDAQAB';

    /**
     * Add timestamp and sign. Keys (including timestamp) are sorted before encoding.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function sign(array $params, int $timestampMs): array
    {
        $params['timestamp'] = $timestampMs;
        $ordered = $params;
        ksort($ordered, SORT_STRING);
        $params['sign'] = bin2hex(openssl_encrypt(
            self::json($ordered),
            'aes-256-cbc',
            self::KEY,
            OPENSSL_RAW_DATA,
            self::IV
        ));
        return $params;
    }

    /** JSON.stringify equivalent: compact, unicode and slashes as-is, empty array stays []. */
    public static function json(array $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** RSA PKCS#1 v1.5 with the console public key, base64 output. */
    public static function rsaEncrypt(string $plain): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(self::PUBLIC_KEY_B64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $out = '';
        if (!openssl_public_encrypt($plain, $out, $pem, OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('Vinchin RSA encryption failed');
        }
        return base64_encode($out);
    }
}
