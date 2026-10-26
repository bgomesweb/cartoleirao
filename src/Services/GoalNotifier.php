<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Database\Connection;
use Cartoleirao\Models\PushSubscription;
use PDO;

/**
 * Detects goals (score changes from ge.globo.com) and full-time results in the current round and
 * sends push notifications:
 *
 *   "Goool: Corinthians 1 x 0 Palmeiras - Yuri Alberto"
 *   "O gol de Yuri Alberto foi anulado: Corinthians 0 x 0 Palmeiras"
 *   "Fim de jogo: Corinthians 3 x 1 Palmeiras - Gols: Yuri Alberto (2), Raniele, Flaco Lopes"
 *
 * Score and status come from the UOL realtime feed (the one behind the UOL app); scorers come from
 * the UOL match page, which can lag the score a few seconds: a notification waits a little for its
 * scorer and is then sent without the name.
 * Runs while the Cartola market is closed (i.e. the round is being played); when the market reopens
 * it keeps watching the previous round for a few hours, so its last "Fim de jogo" isn't lost.
 */
class GoalNotifier
{
    private const MARKET_OPEN = 1;
    private const MARKET_CLOSED = 2;
    /** A game first seen this soon after kick-off starts from 0 x 0 (so a late first run still announces its goals). */
    private const SEED_FROM_ZERO_SECONDS = 900;
    private const SCORER_WAIT_SECONDS = 25;
    private const END_WAIT_SECONDS = 120;
    /** Events older than this are dropped silently (e.g. cron was down). */
    private const STALE_SECONDS = 1800;
    private const GAME_WINDOW_BEFORE = 600;   // starts watching 10 min before kick-off
    private const GAME_WINDOW_AFTER = 11700;  // gives up 3h15 after kick-off

    private PDO $pdo;
    private bool $hasWork = false;

    public function __construct(private CartolaApiClient $cartola, private LiveScoreClient $live, private ?WebPush $push)
    {
        $this->pdo = Connection::get();
    }

    /** Round being played: the current one with the market closed, the previous one once it reopens; null if unknown. */
    public function activeRound(): ?int
    {
        $status = $this->cartola->marketStatus();
        if (!$status || empty($status['rodada_atual'])) {
            return null;
        }

        $market = (int) ($status['status_mercado'] ?? 0);
        $round = (int) $status['rodada_atual'];
        if ($market === self::MARKET_CLOSED) {
            return $round;
        }

        return $market === self::MARKET_OPEN && $round > 1 ? $round - 1 : null;
    }

    /** Whether another run soon is worthwhile (a game in progress or a notification still pending). */
    public function hasWork(): bool
    {
        return $this->hasWork;
    }

    public function run(int $round): string
    {
        $this->hasWork = false;

        if ($this->push === null) {
            return 'chaves VAPID não configuradas';
        }

        $games = $this->live->roundGames($round);
        if (!$games) {
            return "sem jogos na rodada {$round} (ou ge fora do ar)";
        }

        $this->seedNewGames($round, $games);
        $detected = $this->detectGoals($round, $games);
        $detected += $this->detectFinishes($round, $games);
        $sent = $this->dispatch($round, $games);

        $this->hasWork = $this->hasPendingEvents($round) || $this->hasGameInProgress($games);

        $feed = $this->live->feedAvailable() ? 'ok' : 'FORA DO AR (usando ge)';

        return "rodada {$round}, feed UOL: {$feed}, gols/fins detectados: {$detected}, avisos enviados: {$sent}";
    }

    /** First time a game is seen: records its current score/status (no notification), unless it just kicked off. */
    private function seedNewGames(int $round, array $games): void
    {
        $known = $this->pdo->prepare('SELECT cartola_game_id FROM goal_game_state WHERE round_number = ?');
        $known->execute([$round]);
        $known = array_flip($known->fetchAll(PDO::FETCH_COLUMN));

        $insert = $this->pdo->prepare('INSERT IGNORE INTO goal_game_state (round_number, cartola_game_id, home_score, away_score, finished) VALUES (?, ?, ?, ?, ?)');
        foreach ($games as $g) {
            if (isset($known[$g['id']])) {
                continue;
            }
            $justStarted = !$g['finished'] && $g['start'] && time() - $g['start'] < self::SEED_FROM_ZERO_SECONDS;
            $insert->execute([$round, $g['id'], $justStarted ? 0 : (int) $g['home_score'], $justStarted ? 0 : (int) $g['away_score'], (int) $g['finished']]);
        }
    }

    private function gameStates(int $round): array
    {
        $stmt = $this->pdo->prepare('SELECT cartola_game_id, home_score, away_score, finished FROM goal_game_state WHERE round_number = ?');
        $stmt->execute([$round]);
        $state = [];
        foreach ($stmt->fetchAll() as $row) {
            $state[$row['cartola_game_id']] = $row;
        }

        return $state;
    }

    private function detectGoals(int $round, array $games): int
    {
        $state = $this->gameStates($round);

        $addEvent = $this->pdo->prepare('
            INSERT INTO goal_events (round_number, cartola_game_id, home_club_id, away_club_id, home_score, away_score, scoring_club_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $update = $this->pdo->prepare('UPDATE goal_game_state SET home_score = ?, away_score = ? WHERE round_number = ? AND cartola_game_id = ?');

        $total = 0;
        foreach ($games as $g) {
            $prev = $state[$g['id']] ?? null;
            if (!$prev) {
                continue;
            }

            // No score published (yet/momentarily): never read it as 0 x 0, or it would look like a cancelled goal.
            if ($g['home_score'] === null || $g['away_score'] === null) {
                continue;
            }

            $home = (int) $g['home_score'];
            $away = (int) $g['away_score'];
            $prevHome = (int) $prev['home_score'];
            $prevAway = (int) $prev['away_score'];

            $total += $this->cancelGoals($round, $g, $home, $away, $prevHome, $prevAway);

            for ($h = $prevHome + 1; $h <= $home; $h++) {
                $addEvent->execute([$round, $g['id'], $g['home_id'], $g['away_id'], $h, $prevAway, $g['home_id']]);
                $total++;
            }
            for ($a = $prevAway + 1; $a <= $away; $a++) {
                $addEvent->execute([$round, $g['id'], $g['home_id'], $g['away_id'], max($home, $prevHome), $a, $g['away_id']]);
                $total++;
            }

            if ($home !== $prevHome || $away !== $prevAway) {
                $update->execute([$home, $away, $round, $g['id']]);
            }
        }

        return $total;
    }

    /**
     * The score went down (goal annulled, e.g. by VAR). Each goal undone becomes a 'cancel' event
     * carrying the original scorer. A goal that was never announced is just dropped silently.
     */
    private function cancelGoals(int $round, array $g, int $home, int $away, int $prevHome, int $prevAway): int
    {
        $total = 0;
        foreach ([[$g['home_id'], $prevHome - $home], [$g['away_id'], $prevAway - $away]] as [$clubId, $drop]) {
            if ($drop <= 0) {
                continue;
            }

            $stmt = $this->pdo->prepare("
                SELECT id, scorer, sent_at FROM goal_events
                WHERE round_number = ? AND cartola_game_id = ? AND scoring_club_id = ? AND kind = 'goal'
                ORDER BY id DESC LIMIT " . (int) $drop);
            $stmt->execute([$round, $g['id'], $clubId]);

            foreach ($stmt->fetchAll() as $orig) {
                if ($orig['sent_at'] === null) {
                    // Never announced: no need to announce the cancellation either.
                    $this->pdo->prepare("UPDATE goal_events SET kind = 'cancelled', sent_at = NOW() WHERE id = ?")->execute([$orig['id']]);
                    continue;
                }

                $this->pdo->prepare("UPDATE goal_events SET kind = 'cancelled' WHERE id = ?")->execute([$orig['id']]);
                $this->pdo->prepare("
                    INSERT INTO goal_events (round_number, cartola_game_id, home_club_id, away_club_id, home_score, away_score, scoring_club_id, scorer, kind)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'cancel')
                ")->execute([$round, $g['id'], $g['home_id'], $g['away_id'], $home, $away, $clubId, $orig['scorer']]);
                $total++;
            }
        }

        return $total;
    }

    /** A game that just ended (it was not over when first seen) gets one 'end' event. */
    private function detectFinishes(int $round, array $games): int
    {
        $state = $this->gameStates($round);
        $addEvent = $this->pdo->prepare("
            INSERT INTO goal_events (round_number, cartola_game_id, home_club_id, away_club_id, home_score, away_score, scoring_club_id, kind)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'end')
        ");
        $markFinished = $this->pdo->prepare('UPDATE goal_game_state SET finished = 1 WHERE round_number = ? AND cartola_game_id = ?');

        $total = 0;
        foreach ($games as $g) {
            $prev = $state[$g['id']] ?? null;
            if (!$prev || (int) $prev['finished'] || !$g['finished'] || $g['home_score'] === null || $g['away_score'] === null) {
                continue;
            }

            // Long over (e.g. state from before this feature existed): just record it, no notification.
            if ($g['start'] && time() > $g['start'] + self::GAME_WINDOW_AFTER) {
                $markFinished->execute([$round, $g['id']]);
                continue;
            }

            $addEvent->execute([$round, $g['id'], $g['home_id'], $g['away_id'], (int) $g['home_score'], (int) $g['away_score'], $g['home_id']]);
            $markFinished->execute([$round, $g['id']]);
            $total++;
        }

        return $total;
    }

    private function dispatch(int $round, array $games): int
    {
        // Drop events that went stale (cron was down) so they don't flood the group.
        $this->pdo->prepare('UPDATE goal_events SET sent_at = NOW() WHERE sent_at IS NULL AND TIMESTAMPDIFF(SECOND, detected_at, NOW()) > ?')
            ->execute([self::STALE_SECONDS]);

        $events = $this->pdo->prepare('
            SELECT *, TIMESTAMPDIFF(SECOND, detected_at, NOW()) AS age FROM goal_events
            WHERE sent_at IS NULL AND round_number = ? ORDER BY id
        ');
        $events->execute([$round]);

        $byId = [];
        foreach ($games as $g) {
            $byId[$g['id']] = $g;
        }

        $markSent = $this->pdo->prepare('UPDATE goal_events SET sent_at = NOW() WHERE id = ?');
        $setScorer = $this->pdo->prepare('UPDATE goal_events SET scorer = ? WHERE id = ?');
        $blocked = []; // games with an earlier event still waiting: keeps the order (the "fim de jogo" comes last)

        $sent = 0;
        foreach ($events->fetchAll() as $ev) {
            $game = $byId[(int) $ev['cartola_game_id']] ?? null;
            if (!$game) {
                $markSent->execute([$ev['id']]);
                continue;
            }
            if (isset($blocked[$game['id']]) && $ev['kind'] === 'end') {
                continue;
            }

            $score = "{$game['home']} {$ev['home_score']} x {$ev['away_score']} {$game['away']}";

            if ($ev['kind'] === 'cancel') {
                $markSent->execute([$ev['id']]);
                $scoring = (int) $ev['scoring_club_id'] === $game['home_id'] ? $game['home'] : $game['away'];
                $who = $ev['scorer'] ? "O gol de {$ev['scorer']}" : "O gol do {$scoring}";
                $message = "{$who} foi anulado: {$score}";
            } elseif ($ev['kind'] === 'end') {
                $total = (int) $ev['home_score'] + (int) $ev['away_score'];
                $goals = $this->live->goals($game, $total);
                $complete = $total === 0 || ($goals !== null && count($goals) === $total);
                if (!$complete && (int) $ev['age'] < self::END_WAIT_SECONDS) {
                    continue; // the match page hasn't caught up with the final score yet
                }

                $markSent->execute([$ev['id']]);
                $message = "Fim de jogo: {$score}" . ($goals ? ' - Gols: ' . $this->summarizeGoals($goals) : '');
            } else {
                $scorer = $this->scorerOf($ev, $game);
                if ($scorer === null && (int) $ev['age'] < self::SCORER_WAIT_SECONDS) {
                    $blocked[$game['id']] = true;
                    continue; // the match page hasn't caught up yet
                }

                // Marked before sending: a failure must never turn into a repeated notification.
                $markSent->execute([$ev['id']]);
                $setScorer->execute([$scorer, $ev['id']]);
                $message = "Goool: {$score}" . ($scorer ? " - {$scorer}" : '');
            }

            PushSubscription::broadcast($this->push, '⚽ Cartoleirão', $message);
            $sent++;
        }

        return $sent;
    }

    /**
     * The scorer of a goal event: the Nth goal of that side on the match page, N being the side's
     * score right after the goal. Counting this way stays correct after annulled goals.
     */
    private function scorerOf(array $ev, array $game): ?string
    {
        $goals = $this->live->goals($game, (int) $ev['home_score'] + (int) $ev['away_score']);
        if ($goals === null) {
            return null;
        }

        $isHome = (int) $ev['scoring_club_id'] === $game['home_id'];
        $side = array_values(array_filter($goals, fn ($g) => $g['side'] === ($isHome ? 'home' : 'away')));
        $goal = $side[((int) ($isHome ? $ev['home_score'] : $ev['away_score'])) - 1] ?? null;
        if (!$goal || $goal['scorer'] === '') {
            return null;
        }

        return $this->label($goal);
    }

    private function label(array $goal): string
    {
        return $goal['scorer'] . ($goal['own'] ? ' (contra)' : '');
    }

    /** "Yuri Alberto (2), Raniele, Flaco Lopes" — scorers in order of their first goal. */
    private function summarizeGoals(array $goals): string
    {
        $counts = [];
        foreach ($goals as $goal) {
            $label = $goal['scorer'] !== '' ? $this->label($goal) : 'gol sem autor';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $label => $n) {
            $parts[] = $n > 1 ? "{$label} ({$n})" : $label;
        }

        return implode(', ', $parts);
    }

    private function hasPendingEvents(int $round): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM goal_events WHERE sent_at IS NULL AND round_number = ? LIMIT 1');
        $stmt->execute([$round]);

        return (bool) $stmt->fetchColumn();
    }

    private function hasGameInProgress(array $games): bool
    {
        $now = time();
        foreach ($games as $g) {
            if (!$g['finished'] && $g['start'] && $now >= $g['start'] - self::GAME_WINDOW_BEFORE && $now <= $g['start'] + self::GAME_WINDOW_AFTER) {
                return true;
            }
        }

        return false;
    }

    public static function lock(): bool
    {
        return (bool) Connection::get()->query("SELECT GET_LOCK('goal_notifier', 0)")->fetchColumn();
    }
}
