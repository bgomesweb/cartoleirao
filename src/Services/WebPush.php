<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

/**
 * Web Push (RFC 8030) in pure PHP/OpenSSL, no Composer dependency:
 * payload encryption per RFC 8291 (aes128gcm) and VAPID auth per RFC 8292.
 *
 * VAPID keys: generate them once with scripts/generate_vapid_keys.php and
 * put them in config.local.php (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY).
 */
class WebPush
{
    /** ASN.1 prefix of an uncompressed P-256 SubjectPublicKeyInfo. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(
        private string $publicKey,
        private string $privateKey,
        private string $subject,
    ) {
    }

    public static function fromEnv(): ?self
    {
        $public = (string) getenv('VAPID_PUBLIC_KEY');
        $private = (string) getenv('VAPID_PRIVATE_KEY');
        if ($public === '' || $private === '') {
            return null;
        }

        return new self($public, $private, getenv('VAPID_SUBJECT') ?: 'mailto:bgomesweb@gmail.com');
    }

    /** @return array{public: string, private: string} base64url keys */
    public static function generateVapidKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'public' => self::b64(self::point($ec)),
            'private' => self::b64(str_pad($ec['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * Sends one notification to every subscription in parallel.
     *
     * @param array<int, array{endpoint: string, p256dh: string, auth: string}> $subscriptions
     * @return array<int, array{status: int, expired: bool}> same keys as $subscriptions
     */
    public function sendMany(array $subscriptions, string $payload, int $ttl = 3600): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($subscriptions as $i => $sub) {
            try {
                $body = self::encrypt($payload, self::unb64($sub['p256dh']), self::unb64($sub['auth']));
                $headers = [
                    'Authorization: ' . $this->vapidHeader($sub['endpoint']),
                    'Content-Encoding: aes128gcm',
                    'Content-Type: application/octet-stream',
                    'TTL: ' . $ttl,
                    'Urgency: high',
                ];
            } catch (\Throwable $e) {
                error_log('[WebPush] ' . $e->getMessage());
                $results[$i] = ['status' => 0, 'expired' => false];
                continue;
            }

            $ch = curl_init($sub['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $results[$i] = ['status' => $code, 'expired' => $code === 404 || $code === 410];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        return $results;
    }

    private function vapidHeader(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $signingInput = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . self::b64(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $this->subject]));

        $privateKey = openssl_pkey_get_private(self::privateKeyPem(self::unb64($this->privateKey), self::unb64($this->publicKey)));
        if (!$privateKey || !openssl_sign($signingInput, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Falha ao assinar o token VAPID — confira as chaves VAPID.');
        }

        return 'vapid t=' . $signingInput . '.' . self::b64(self::derToRawSignature($der)) . ', k=' . $this->publicKey;
    }

    /** RFC 8291 payload encryption with a fresh ephemeral key; returns the full aes128gcm body. */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        return self::encryptWithKey($payload, $uaPublic, $authSecret, $key, self::point(openssl_pkey_get_details($key)['ec']), random_bytes(16));
    }

    /** Core of the encryption, with the ephemeral key already loaded. */
    public static function encryptWithKey(string $payload, string $uaPublic, string $authSecret, \OpenSSLAsymmetricKey $asKey, string $asPublic, string $salt): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('Inscrição push inválida.');
        }

        $uaKey = openssl_pkey_get_public(self::publicKeyPem($uaPublic));
        $secret = $uaKey ? openssl_pkey_derive($uaKey, $asKey, 32) : false;
        if ($secret === false) {
            throw new \RuntimeException('Falha no ECDH — chave p256dh inválida.');
        }

        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // 0x02 = delimiter of the last (and only) record.
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    }

    /** Builds an EC private key (PEM) from its raw 32-byte scalar and 65-byte public point. */
    public static function privateKeyPem(string $d, string $publicPoint): string
    {
        $der = hex2bin('30770201010420') . str_pad($d, 32, "\0", STR_PAD_LEFT)
            . hex2bin('a00a06082a8648ce3d030107a144034200') . $publicPoint;

        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    private static function publicKeyPem(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX) . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function point(array $ec): string
    {
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** ECDSA DER signature -> raw R||S (64 bytes), as JWT ES256 requires. */
    private static function derToRawSignature(string $der): string
    {
        $offset = 3; // 0x30 len 0x02 ...
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7f;
        }
        $rLen = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLen);
        $offset += 1 + $rLen + 1;
        $sLen = ord($der[$offset]);
        $s = substr($der, $offset + 1, $sLen);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
