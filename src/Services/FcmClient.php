<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Database\Connection;

/**
 * Push to the native Android app through Firebase Cloud Messaging (HTTP v1), in pure PHP/OpenSSL.
 * Auth is a service-account JWT exchanged for an OAuth token (cached in a temp file for ~50 min).
 *
 * Config: FCM_SERVICE_ACCOUNT_FILE = path to the service account JSON (outside public/).
 * Messages are sent with high priority to the "gols" channel, which the app creates.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private function __construct(private array $account)
    {
    }

    public static function fromEnv(): ?self
    {
        $file = (string) getenv('FCM_SERVICE_ACCOUNT_FILE');
        if ($file === '' || !is_readable($file)) {
            error_log('[fcm] FCM_SERVICE_ACCOUNT_FILE ' . ($file === '' ? 'não definido' : "ilegível: {$file}"));
            return null;
        }
        $account = json_decode((string) file_get_contents($file), true);
        if (!is_array($account) || empty($account['project_id']) || empty($account['private_key']) || empty($account['client_email'])) {
            error_log("[fcm] conta de serviço inválida em {$file}");
            return null;
        }

        return new self($account);
    }

    /**
     * Sends to every registered device in parallel and drops tokens FCM says are gone.
     * Returns [delivered, total].
     *
     * @return array{0: int, 1: int}
     */
    public function broadcast(string $title, string $body, string $url): array
    {
        $pdo = Connection::get();
        $tokens = $pdo->query('SELECT id, token FROM fcm_tokens')->fetchAll();
        if (!$tokens) {
            return [0, 0];
        }

        $access = $this->accessToken();
        if ($access === null) {
            echo "[fcm] não foi possível obter o token OAuth do Firebase\n";
            return [0, count($tokens)];
        }

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$this->account['project_id']}/messages:send";
        $multi = curl_multi_init();
        $handles = [];
        foreach ($tokens as $i => $row) {
            $payload = json_encode(['message' => [
                'token' => $row['token'],
                'notification' => ['title' => $title, 'body' => $body],
                'data' => ['url' => $url],
                'android' => [
                    'priority' => 'HIGH',
                    'ttl' => '3600s',
                    'notification' => ['channel_id' => 'gols', 'sound' => 'default'],
                ],
            ]], JSON_UNESCAPED_UNICODE);

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
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

        $ok = 0;
        $gone = [];
        foreach ($handles as $i => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $response = (string) curl_multi_getcontent($ch);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                $ok++;
            } elseif ($code === 404 || str_contains($response, 'UNREGISTERED')) {
                $gone[] = (int) $tokens[$i]['id'];
            } else {
                echo "[fcm] aparelho {$tokens[$i]['id']} falhou: HTTP {$code} " . substr($response, 0, 200) . "\n";
            }
        }
        curl_multi_close($multi);

        if ($gone) {
            $in = implode(',', array_fill(0, count($gone), '?'));
            $pdo->prepare("DELETE FROM fcm_tokens WHERE id IN ($in)")->execute($gone);
        }

        return [$ok, count($tokens)];
    }

    private function accessToken(): ?string
    {
        $cache = sys_get_temp_dir() . '/cartoleirao_fcm_' . md5($this->account['client_email']) . '.json';
        $cached = is_readable($cache) ? json_decode((string) file_get_contents($cache), true) : null;
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
            return $cached['token'];
        }

        $now = time();
        $input = WebPush::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . WebPush::b64(json_encode([
            'iss' => $this->account['client_email'],
            'scope' => self::SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        if (!openssl_sign($input, $signature, $this->account['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $input . '.' . WebPush::b64($signature),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = json_decode((string) curl_exec($ch), true);
        curl_close($ch);

        if (empty($response['access_token'])) {
            return null;
        }
        @file_put_contents($cache, json_encode(['token' => $response['access_token'], 'expires' => $now + (int) ($response['expires_in'] ?? 3000) - 300]), LOCK_EX);
        @chmod($cache, 0600);

        return $response['access_token'];
    }
}
