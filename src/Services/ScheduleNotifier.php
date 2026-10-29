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
 *   "O jogo entre Corinthians x Palmeiras vai começar! Fique ligado!" (10 minutes before kick-off)
 *
 * What was already announced lives in `notification_state`, so each change/day is sent only once.
 */
class ScheduleNotifier
{
    private const MARKET_OPEN = 1;
    private const MARKET_CLOSED = 2;
    /** The daily games notice goes out from this hour on (so it doesn't wake anyone up). */
    private const DAILY_FROM_HOUR = 8;
    private const KICKOFF_WARNING_MINUTES = 10;

    private PDO $pdo;

    /** Cartola slug => club name. The API only gives nicknames ("Galo"), which aren't what people expect in the text. */
    private const CLUB_NAMES = [
        'atletico-mg' => 'Atlético-MG', 'atletico-pr' => 'Athletico-PR', 'bahia' => 'Bahia',
        'botafogo' => 'Botafogo', 'bragantino' => 'Bragantino', 'chapecoense' => 'Chapecoense',
        'corinthians' => 'Corinthians', 'coritiba' => 'Coritiba', 'cruzeiro' => 'Cruzeiro',
        'flamengo' => 'Flamengo', 'fluminense' => 'Fluminense', 'gremio' => 'Grêmio',
        'internacional' => 'Internacional', 'mirassol' => 'Mirassol', 'palmeiras' => 'Palmeiras',
        'remo' => 'Remo', 'santos' => 'Santos', 'sao-paulo' => 'São Paulo', 'vasco' => 'Vasco',
        'vitoria' => 'Vitória',
    ];

    /** @var array[]|null games of the round in progress, fetched once per run */
    private ?array $games = null;

    public function __construct(private ?WebPush $push, private ?CartolaApiClient $cartola = null)
    {
        $this->pdo = Connection::get();
        $this->cartola ??= new CartolaApiClient();
    }

    private static function clubName(array $club): string
    {
        $slug = (string) ($club['slug'] ?? '');

        return self::CLUB_NAMES[$slug] ?? ($club['nome_fantasia'] ?? $club['nome'] ?? '?');
    }

    /**
     * Games straight from the Cartola API (/partidas), so the notices don't depend on the local
     * `games` table being synced.
     *
     * @return array<int, array{id: int, played_at: string, home: string, away: string}> ordered by kick-off
     */
    private function games(): array
    {
        if ($this->games !== null) {
            return $this->games;
        }

        $response = $this->cartola->currentGames();
        $clubs = $response['clubes'] ?? [];
        $games = [];
        foreach ($response['partidas'] ?? [] as $g) {
            if (empty($g['partida_data']) || empty($g['valida'])) {
                continue;
            }
            $games[] = [
                'id' => (int) $g['partida_id'],
                'played_at' => substr((string) $g['partida_data'], 0, 19),
                'home' => self::clubName($clubs[$g['clube_casa_id']] ?? []),
                'away' => self::clubName($clubs[$g['clube_visitante_id']] ?? []),
            ];
        }
        usort($games, fn($a, $b) => [$a['played_at'], $a['id']] <=> [$b['played_at'], $b['id']]);

        return $this->games = $games;
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

        $games = array_values(array_filter($this->games(), fn($g) => str_starts_with($g['played_at'], $today)));
        if (!$games) {
            // no games today (or the API is down): try again on the next run
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

    /** Warns about each game that kicks off within the next 10 minutes, once per game. */
    public function notifyKickoffs(?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));

        $from = $now->format('Y-m-d H:i:s');
        $to = $now->modify('+' . self::KICKOFF_WARNING_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        foreach ($this->games() as $g) {
            if ($g['played_at'] <= $from || $g['played_at'] > $to) {
                continue;
            }
            $key = 'kickoff_' . $g['id'];
            if ($this->getState($key) !== null) {
                continue;
            }
            $this->setState($key, $now->format('Y-m-d H:i:s'));
            $this->send('Vai começar!', "O jogo entre {$g['home']} x {$g['away']} vai começar! Fique ligado!", '/jogos.php');
            echo "[schedule] início do jogo {$g['home']} x {$g['away']} notificado\n";
        }
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
