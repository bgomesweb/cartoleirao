<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Database\Connection;
use Cartoleirao\Models\PushSubscription;
use PDO;

/**
 * Detects goals in the current Cartola round (score changes in /partidas) and
 * sends "Goool: Casa 1 x 0 Fora - Autor" as a push notification.
 *
 * The scorer comes from the scouts in /atletas/pontuados, which lag a few
 * minutes behind the score: a goal waits up to SCORER_WAIT_SECONDS for its
 * scorer and is then sent without the name.
 */
class GoalNotifier
{
    private const SCORER_WAIT_SECONDS = 240;
    /** Goals older than this are dropped silently (e.g. cron was down). */
    private const STALE_SECONDS = 1800;
    private const GAME_WINDOW_BEFORE = 11700; // 3h15 after kick-off
    private const GAME_WINDOW_AFTER = 600;

    /** Display names by Cartola club id (the API only gives abbreviations/nicknames). */
    private const CLUB_NAMES = [
        262 => 'Flamengo', 263 => 'Botafogo', 264 => 'Corinthians', 265 => 'Bahia', 266 => 'Fluminense',
        267 => 'Vasco', 275 => 'Palmeiras', 276 => 'São Paulo', 277 => 'Santos', 280 => 'Red Bull Bragantino',
        282 => 'Atlético-MG', 283 => 'Cruzeiro', 284 => 'Grêmio', 285 => 'Internacional', 287 => 'Vitória',
        293 => 'Athletico-PR', 294 => 'Coritiba', 315 => 'Chapecoense', 364 => 'Remo', 2305 => 'Mirassol',
    ];

    private PDO $pdo;

    public function __construct(private CartolaApiClient $api, private ?WebPush $push)
    {
        $this->pdo = Connection::get();
    }

    public function run(): string
    {
        if ($this->push === null) {
            return 'chaves VAPID não configuradas';
        }

        $data = $this->api->currentGames();
        if (!$data || empty($data['partidas']) || empty($data['rodada'])) {
            return 'sem jogos na rodada atual';
        }

        $round = (int) $data['rodada'];
        $games = array_values(array_filter($data['partidas'], fn ($g) => !empty($g['partida_id'])));
        $clubNames = $this->clubNames($data['clubes'] ?? []);

        $seeded = $this->seedNewGames($round, $games);
        $detected = $this->detectGoals($round, $games);

        if (!$this->hasPendingEvents() && !$seeded && !$this->hasGameInProgress($games)) {
            return 'nenhum jogo em andamento';
        }

        $athletes = null;
        if ($this->hasPendingEvents() || $seeded) {
            $athletes = $this->api->scoredAthletes()['atletas'] ?? [];
            if ($seeded && !$this->athletesSeeded($round)) {
                $this->seedAthletes($round, $athletes);
            }
        }

        $sent = $athletes !== null ? $this->dispatch($round, (array) $athletes, $clubNames) : 0;

        return "gols detectados: {$detected}, avisos enviados: {$sent}";
    }

    /** First time a game is seen: only records its current score (no notification). */
    private function seedNewGames(int $round, array $games): bool
    {
        $known = $this->pdo->prepare('SELECT cartola_game_id FROM goal_game_state WHERE round_number = ?');
        $known->execute([$round]);
        $known = array_flip($known->fetchAll(PDO::FETCH_COLUMN));

        $insert = $this->pdo->prepare('INSERT IGNORE INTO goal_game_state (round_number, cartola_game_id, home_score, away_score) VALUES (?, ?, ?, ?)');
        $new = false;
        foreach ($games as $g) {
            if (!isset($known[$g['partida_id']])) {
                $insert->execute([$round, $g['partida_id'], (int) $g['placar_oficial_mandante'], (int) $g['placar_oficial_visitante']]);
                $new = true;
            }
        }

        return $new;
    }

    private function detectGoals(int $round, array $games): int
    {
        $stmt = $this->pdo->prepare('SELECT cartola_game_id, home_score, away_score FROM goal_game_state WHERE round_number = ?');
        $stmt->execute([$round]);
        $state = [];
        foreach ($stmt->fetchAll() as $row) {
            $state[$row['cartola_game_id']] = $row;
        }

        $addEvent = $this->pdo->prepare('
            INSERT INTO goal_events (round_number, cartola_game_id, home_club_id, away_club_id, home_score, away_score, scoring_club_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $update = $this->pdo->prepare('UPDATE goal_game_state SET home_score = ?, away_score = ? WHERE round_number = ? AND cartola_game_id = ?');

        $total = 0;
        foreach ($games as $g) {
            $prev = $state[$g['partida_id']] ?? null;
            if (!$prev) {
                continue;
            }

            // No score published (yet/momentarily): never read it as 0 x 0, or it would look like a cancelled goal.
            if ($g['placar_oficial_mandante'] === null || $g['placar_oficial_visitante'] === null) {
                continue;
            }

            $home = (int) $g['placar_oficial_mandante'];
            $away = (int) $g['placar_oficial_visitante'];
            $homeId = (int) $g['clube_casa_id'];
            $awayId = (int) $g['clube_visitante_id'];

            $total += $this->cancelGoals($round, $g['partida_id'], $homeId, $awayId, $home, $away, (int) $prev['home_score'], (int) $prev['away_score']);

            for ($h = (int) $prev['home_score'] + 1; $h <= $home; $h++) {
                $addEvent->execute([$round, $g['partida_id'], $homeId, $awayId, $h, (int) $prev['away_score'], $homeId]);
                $total++;
            }
            for ($a = (int) $prev['away_score'] + 1; $a <= $away; $a++) {
                $addEvent->execute([$round, $g['partida_id'], $homeId, $awayId, max($home, (int) $prev['home_score']), $a, $awayId]);
                $total++;
            }

            if ($home !== (int) $prev['home_score'] || $away !== (int) $prev['away_score']) {
                $update->execute([$home, $away, $round, $g['partida_id']]);
            }
        }

        return $total;
    }

    /**
     * The score went down (goal annulled, e.g. by VAR). Each goal undone becomes a 'cancel' event
     * carrying the original scorer. A goal that was never announced is just dropped silently.
     */
    private function cancelGoals(int $round, int|string $gameId, int $homeId, int $awayId, int $home, int $away, int $prevHome, int $prevAway): int
    {
        $total = 0;
        foreach ([[$homeId, $prevHome - $home], [$awayId, $prevAway - $away]] as [$clubId, $drop]) {
            if ($drop <= 0) {
                continue;
            }

            $stmt = $this->pdo->prepare("
                SELECT id, scorer, sent_at FROM goal_events
                WHERE round_number = ? AND cartola_game_id = ? AND scoring_club_id = ? AND kind = 'goal'
                ORDER BY id DESC LIMIT " . (int) $drop);
            $stmt->execute([$round, $gameId, $clubId]);

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
                ")->execute([$round, $gameId, $homeId, $awayId, $home, $away, $clubId, $orig['scorer']]);
                $total++;
            }
        }

        return $total;
    }

    private function dispatch(int $round, array $athletes, array $clubNames): int
    {
        // Drop goals that went stale (cron was down) so they don't flood the group.
        $this->pdo->prepare('UPDATE goal_events SET sent_at = NOW() WHERE sent_at IS NULL AND TIMESTAMPDIFF(SECOND, detected_at, NOW()) > ?')
            ->execute([self::STALE_SECONDS]);

        $events = $this->pdo->prepare('
            SELECT *, TIMESTAMPDIFF(SECOND, detected_at, NOW()) AS age FROM goal_events
            WHERE sent_at IS NULL AND round_number = ? ORDER BY id
        ');
        $events->execute([$round]);

        $claimed = $this->reconcileClaimed($round, $this->claimedGoals($round), $athletes);
        $markSent = $this->pdo->prepare('UPDATE goal_events SET sent_at = NOW() WHERE id = ?');
        $claim = $this->pdo->prepare('
            INSERT INTO goal_athlete_state (round_number, athlete_id, goals, own_goals) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE goals = VALUES(goals), own_goals = VALUES(own_goals)
        ');

        $sent = 0;
        foreach ($events->fetchAll() as $ev) {
            if ($ev['kind'] === 'cancel') {
                $markSent->execute([$ev['id']]);
                $home = $clubNames[(int) $ev['home_club_id']] ?? 'Mandante';
                $away = $clubNames[(int) $ev['away_club_id']] ?? 'Visitante';
                $scoringClub = $clubNames[(int) $ev['scoring_club_id']] ?? 'time';
                $who = $ev['scorer'] ? "O gol de {$ev['scorer']}" : "O gol do {$scoringClub}";
                PushSubscription::broadcast($this->push, '⚽ Cartoleirão', "{$who} foi anulado: {$home} {$ev['home_score']} x {$ev['away_score']} {$away}");
                $sent++;
                continue;
            }

            $found = $this->findScorer($ev, $athletes, $claimed);

            if ($found === null && (int) $ev['age'] < self::SCORER_WAIT_SECONDS) {
                continue; // scouts haven't caught up yet
            }

            $scorer = null;
            if ($found !== null) {
                [$athleteId, $scorer, $isOwnGoal] = $found;
                $claimed[$athleteId] ??= ['goals' => 0, 'own_goals' => 0];
                $claimed[$athleteId][$isOwnGoal ? 'own_goals' : 'goals']++;
                $claim->execute([$round, $athleteId, $claimed[$athleteId]['goals'], $claimed[$athleteId]['own_goals']]);
                if ($isOwnGoal) {
                    $scorer .= ' (contra)';
                }
            }

            // Marked before sending: a failure must never turn into a repeated notification.
            $markSent->execute([$ev['id']]);
            $this->pdo->prepare('UPDATE goal_events SET scorer = ? WHERE id = ?')->execute([$scorer, $ev['id']]);

            $home = $clubNames[(int) $ev['home_club_id']] ?? 'Mandante';
            $away = $clubNames[(int) $ev['away_club_id']] ?? 'Visitante';
            $message = "Goool: {$home} {$ev['home_score']} x {$ev['away_score']} {$away}" . ($scorer ? " - {$scorer}" : '');

            PushSubscription::broadcast($this->push, '⚽ Cartoleirão', $message);
            $sent++;
        }

        return $sent;
    }

    /** @return array{0: int, 1: string, 2: bool}|null [athlete id, name, own goal?] */
    private function findScorer(array $ev, array $athletes, array $claimed): ?array
    {
        $scoring = (int) $ev['scoring_club_id'];
        $opponent = $scoring === (int) $ev['home_club_id'] ? (int) $ev['away_club_id'] : (int) $ev['home_club_id'];

        foreach ($athletes as $id => $a) {
            $scouts = $a['scout'] ?? [];
            $clubId = (int) ($a['clube_id'] ?? 0);

            if ($clubId === $scoring && (int) ($scouts['G'] ?? 0) > ($claimed[$id]['goals'] ?? 0)) {
                return [(int) $id, (string) ($a['apelido'] ?? ''), false];
            }
            if ($clubId === $opponent && (int) ($scouts['GC'] ?? 0) > ($claimed[$id]['own_goals'] ?? 0)) {
                return [(int) $id, (string) ($a['apelido'] ?? ''), true];
            }
        }

        return null;
    }

    /**
     * When a goal is annulled the athlete's scout drops; lowering our claimed count with it keeps
     * a later goal by the same player attributable.
     */
    private function reconcileClaimed(int $round, array $claimed, array $athletes): array
    {
        $update = $this->pdo->prepare('UPDATE goal_athlete_state SET goals = ?, own_goals = ? WHERE round_number = ? AND athlete_id = ?');
        foreach ($claimed as $id => $c) {
            $scouts = $athletes[$id]['scout'] ?? [];
            $goals = min($c['goals'], (int) ($scouts['G'] ?? 0));
            $own = min($c['own_goals'], (int) ($scouts['GC'] ?? 0));
            if ($goals !== $c['goals'] || $own !== $c['own_goals']) {
                $claimed[$id] = ['goals' => $goals, 'own_goals' => $own];
                $update->execute([$goals, $own, $round, $id]);
            }
        }

        return $claimed;
    }

    private function claimedGoals(int $round): array
    {
        $stmt = $this->pdo->prepare('SELECT athlete_id, goals, own_goals FROM goal_athlete_state WHERE round_number = ?');
        $stmt->execute([$round]);
        $claimed = [];
        foreach ($stmt->fetchAll() as $row) {
            $claimed[(int) $row['athlete_id']] = ['goals' => (int) $row['goals'], 'own_goals' => (int) $row['own_goals']];
        }

        return $claimed;
    }

    private function athletesSeeded(int $round): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM goal_athlete_state WHERE round_number = ? LIMIT 1');
        $stmt->execute([$round]);

        return (bool) $stmt->fetchColumn();
    }

    /** Records the goals athletes already have, so past goals are never attributed to new ones. */
    private function seedAthletes(int $round, array $athletes): void
    {
        $insert = $this->pdo->prepare('INSERT IGNORE INTO goal_athlete_state (round_number, athlete_id, goals, own_goals) VALUES (?, ?, ?, ?)');
        foreach ($athletes as $id => $a) {
            $goals = (int) ($a['scout']['G'] ?? 0);
            $own = (int) ($a['scout']['GC'] ?? 0);
            if ($goals || $own) {
                $insert->execute([$round, (int) $id, $goals, $own]);
            }
        }
    }

    private function hasPendingEvents(): bool
    {
        return (bool) $this->pdo->query('SELECT 1 FROM goal_events WHERE sent_at IS NULL LIMIT 1')->fetchColumn();
    }

    private function hasGameInProgress(array $games): bool
    {
        $now = time();
        foreach ($games as $g) {
            $start = strtotime((string) ($g['partida_data'] ?? ''));
            if ($start && $now >= $start - self::GAME_WINDOW_AFTER && $now <= $start + self::GAME_WINDOW_BEFORE) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> club id => display name */
    private function clubNames(array $apiClubs): array
    {
        $names = self::CLUB_NAMES;
        foreach ($apiClubs as $club) {
            $id = (int) ($club['id'] ?? 0);
            if ($id && !isset($names[$id])) {
                $names[$id] = $club['slug'] ? mb_convert_case(str_replace('-', ' ', $club['slug']), MB_CASE_TITLE) : ($club['apelido'] ?? $club['nome']);
            }
        }

        return $names;
    }

    public static function lock(): bool
    {
        return (bool) Connection::get()->query("SELECT GET_LOCK('goal_notifier', 0)")->fetchColumn();
    }
}
