<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class PushSubscription
{
    public static function save(string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        $stmt = Connection::get()->prepare('
            INSERT INTO push_subscriptions (endpoint_hash, endpoint, p256dh, auth, user_agent)
            VALUES (:hash, :endpoint, :p256dh, :auth, :ua)
            ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)
        ');
        $stmt->execute([
            ':hash' => hash('sha256', $endpoint),
            ':endpoint' => $endpoint,
            ':p256dh' => $p256dh,
            ':auth' => $auth,
            ':ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }

    public static function saveFcmToken(string $token, ?string $userAgent): void
    {
        Connection::get()->prepare('
            INSERT INTO fcm_tokens (token_hash, token, user_agent) VALUES (:hash, :token, :ua)
            ON DUPLICATE KEY UPDATE user_agent = VALUES(user_agent)
        ')->execute([
            ':hash' => hash('sha256', $token),
            ':token' => $token,
            ':ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }

    public static function deleteFcmToken(string $token): void
    {
        Connection::get()->prepare('DELETE FROM fcm_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }

    public static function deleteByEndpoint(string $endpoint): void
    {
        Connection::get()->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?')
            ->execute([hash('sha256', $endpoint)]);
    }

    public static function deleteByIds(array $ids): void
    {
        if (!$ids) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        Connection::get()->prepare("DELETE FROM push_subscriptions WHERE id IN ($in)")->execute(array_values($ids));
    }

    public static function all(): array
    {
        return Connection::get()->query('SELECT id, endpoint, p256dh, auth FROM push_subscriptions')->fetchAll();
    }

    /**
     * Sends to the Android app (FCM) and to every Web Push subscription, dropping the expired ones.
     * Returns the number of devices delivered. Either channel may be absent ($push null / FCM not configured).
     */
    public static function broadcast(?\Cartoleirao\Services\WebPush $push, string $title, string $body, string $url = '/jogos.php'): int
    {
        $fcmOk = 0;
        if ($fcm = \Cartoleirao\Services\FcmClient::fromEnv()) {
            [$fcmOk, $fcmTotal] = $fcm->broadcast($title, $body, $url);
            echo "[fcm] \"{$body}\" -> entregue a {$fcmOk} de {$fcmTotal} app(s)\n";
        }

        $subs = $push ? self::all() : [];
        if (!$subs) {
            return $fcmOk;
        }

        $results = $push->sendMany($subs, json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE));

        $expired = [];
        $ok = 0;
        foreach ($results as $i => $r) {
            if ($r['expired']) {
                $expired[] = (int) $subs[$i]['id'];
            } elseif ($r['status'] >= 200 && $r['status'] < 300) {
                $ok++;
            } else {
                error_log("[push] endpoint {$subs[$i]['id']} respondeu HTTP {$r['status']}");
                echo "[push] aparelho {$subs[$i]['id']} falhou: HTTP {$r['status']}\n";
            }
        }
        self::deleteByIds($expired);
        echo '[push] "' . $body . '" -> entregue a ' . $ok . ' de ' . count($subs) . ' aparelhos'
            . ($expired ? ', ' . count($expired) . ' expirado(s) removido(s)' : '') . "\n";

        return $ok + $fcmOk;
    }
}
