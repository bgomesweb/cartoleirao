<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Database\Connection;
use Cartoleirao\Models\PushSubscription;
use PDO;

/**
 * Push notifications (site and Android app) that aren't tied to a live match:
 *
 *   "Mercado aberto - Rodada 31: escale o seu time!" / "Mercado fechado - Rodada 31: a bola vai rolar!"
 *   "Jogos do dia 29/10/2026 - Corinthians x Palmeiras - 19:30 / São Paulo x Santos - 21:30"
 *
 * What was already announced lives in `notification_state`, so each change/day is sent only once.
 */
class ScheduleNotifier
{
    private const MARKET_OPEN = 1;
    private const MARKET_CLOSED = 2;
    /** The daily games notice goes out from this hour on (so it doesn't wake anyone up). */
    private const DAILY_FROM_HOUR = 8;

    private PDO $pdo;

    public function __construct(private ?WebPush $push)
    {
        $this->pdo = Connection::get();
    }

    /**
     * Announces the market opening/closing. Other statuses (maintenance, updating) are ignored, so
     * closed -> updating -> closed doesn't notify twice. The first run only records the status.
     */
    public function notifyMarket(?array $status): void
    {
        $code = (int) ($status['status_mercado'] ?? 0);
        $round = (int) ($status['rodada_atual'] ?? 0);
        if (!in_array($code, [self::MARKET_OPEN, self::MARKET_CLOSED], true)) {
            return;
        }

        $key = 'market_status';
        $value = $code . ':' . $round;
        $last = $this->getState($key);
        if ($last === $value) {
            return;
        }

        // record before sending: a failure must not turn into a notification every minute
        $this->setState($key, $value);
        if ($last === null) {
            return;
        }

        if ($code === self::MARKET_OPEN) {
            $this->send('Mercado aberto', "Rodada {$round}: escale o seu time!", '/');
        } else {
            $this->send('Mercado fechado', "Rodada {$round}: a bola vai rolar!", '/jogos.php');
        }
        echo "[schedule] mercado {$value} notificado\n";
    }

    /** Lists today's games once, from 08:00 on. Days without games send nothing. */
    public function notifyDailyGames(?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        if ((int) $now->format('G') < self::DAILY_FROM_HOUR) {
            return;
        }

        $today = $now->format('Y-m-d');
        if ($this->getState('daily_games') === $today) {
            return;
        }

        $stmt = $this->pdo->prepare('
            SELECT g.played_at, hc.name AS home, ac.name AS away
            FROM games g
            JOIN clubs hc ON hc.id = g.home_club_id
            JOIN clubs ac ON ac.id = g.away_club_id
            WHERE g.played_at >= :start AND g.played_at < :end
            ORDER BY g.played_at, g.id
        ');
        $stmt->execute([':start' => $today . ' 00:00:00', ':end' => $today . ' 23:59:59']);
        $games = $stmt->fetchAll();
        if (!$games) {
            // games are synced every 10 min; keep trying until the day's list shows up
            return;
        }

        $this->setState('daily_games', $today);

        $list = implode(' / ', array_map(
            fn(array $g) => "{$g['home']} x {$g['away']} - " . substr((string) $g['played_at'], 11, 5),
            $games
        ));
        $this->send('Jogos do dia ' . $now->format('d/m/Y'), $list, '/jogos.php');
        echo '[schedule] jogos do dia ' . $today . " notificados\n";
    }

    private function send(string $title, string $body, string $url): void
    {
        PushSubscription::broadcast($this->push, $title, $body, $url);
    }

    private function getState(string $name): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM notification_state WHERE name = ?');
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    private function setState(string $name, string $value): void
    {
        $this->pdo->prepare('
            INSERT INTO notification_state (name, value) VALUES (:n, :v)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ')->execute([':n' => $name, ':v' => $value]);
    }
}
