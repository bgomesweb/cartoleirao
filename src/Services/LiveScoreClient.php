<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

/**
 * Live scores for goal notifications, from sources fresher than the Cartola API
 * (whose /partidas answer is cached for 5 minutes):
 *
 *  - ge.globo.com (api.globoesporte.globo.com): score and status of every game in a round,
 *    cached for 60s. Game and club ids are the same ones Cartola uses.
 *  - placar.uol.com.br: the match page embeds the goals with their scorers.
 */
class LiveScoreClient
{
    private const GE_TABLE = 'd1a37fa4-e948-43a6-ba53-ab24ab3a45b1';
    private const GE_PHASE = 'fase-unica-campeonato-brasileiro-2026';
    private const UOL_CACHE_SECONDS = 8;
    private const GAME_WINDOW_BEFORE = 600;
    private const GAME_WINDOW_AFTER = 11700;
    /** ge name => UOL slug, for the clubs the two sites name differently. */
    private const UOL_SLUGS = ['Bragantino' => 'red-bull-bragantino'];

    /** @var array<int, array{at: int, page: ?array}> */
    private array $goalsCache = [];

    /**
     * @return array<int, array>|null games of the round: id, start (timestamp), home_id, away_id,
     *                                home, away, home_score, away_score (null = no score yet), finished
     */
    public function roundGames(int $round): ?array
    {
        $url = sprintf(
            'https://api.globoesporte.globo.com/tabela/%s/fase/%s/rodada/%d/jogos/',
            self::GE_TABLE,
            self::GE_PHASE,
            $round
        );
        $list = json_decode((string) $this->fetch($url), true);
        if (!is_array($list)) {
            return null;
        }

        $games = [];
        foreach ($list as $g) {
            if (empty($g['id']) || empty($g['equipes']['mandante']['id']) || empty($g['equipes']['visitante']['id'])) {
                continue;
            }
            $games[] = [
                'id' => (int) $g['id'],
                'start' => strtotime((string) ($g['data_realizacao'] ?? '')) ?: null,
                'home_id' => (int) $g['equipes']['mandante']['id'],
                'away_id' => (int) $g['equipes']['visitante']['id'],
                'home' => (string) $g['equipes']['mandante']['nome_popular'],
                'away' => (string) $g['equipes']['visitante']['nome_popular'],
                'home_score' => $g['placar_oficial_mandante'] ?? null,
                'away_score' => $g['placar_oficial_visitante'] ?? null,
                'finished' => ($g['transmissao']['broadcast']['id'] ?? '') === 'ENCERRADA',
            ];
        }

        // The ge API lags minutes behind the real game; the UOL match page is fresher, so for games
        // under way its score and status win.
        foreach ($games as &$game) {
            $now = time();
            if ($game['finished'] || !$game['start'] || $now < $game['start'] - self::GAME_WINDOW_BEFORE || $now > $game['start'] + self::GAME_WINDOW_AFTER) {
                continue;
            }
            $snapshot = $this->page($game)['snapshot'] ?? null;
            if ($snapshot === null) {
                continue;
            }
            if ($snapshot['home'] !== null && $snapshot['away'] !== null) {
                $game['home_score'] = $snapshot['home'];
                $game['away_score'] = $snapshot['away'];
            }
            $game['finished'] = $snapshot['ended'];
        }
        unset($game);

        return $games;
    }

    /**
     * Goals of a game in chronological order, annulled ones excluded.
     * Each: scorer (nickname), side ('home'|'away' = the side the goal counts for), own (own goal?).
     * Null when the match page can't be read.
     */
    public function goals(array $game): ?array
    {
        return $this->page($game)['goals'] ?? null;
    }

    /** @return array{snapshot: ?array, goals: ?array}|null cached for a few seconds */
    private function page(array $game): ?array
    {
        $cached = $this->goalsCache[$game['id']] ?? null;
        if ($cached && time() - $cached['at'] < self::UOL_CACHE_SECONDS) {
            return $cached['page'];
        }

        $page = $this->fetchPage($game);
        $this->goalsCache[$game['id']] = ['at' => time(), 'page' => $page];

        return $page;
    }

    private function fetchPage(array $game): ?array
    {
        if (!$game['start']) {
            return null;
        }

        $url = sprintf(
            'https://placar.uol.com.br/esporte/futebol/brasileirao/%s/%s-x-%s.htm',
            date('Y/m/d', $game['start']),
            self::slug($game['home']),
            self::slug($game['away'])
        );
        $html = $this->fetch($url);
        if (!$html || !preg_match('#<script id="VUELAND_STATE" type="application/json">(.*?)</script>#s', $html, $m)) {
            return null;
        }

        $board = json_decode($m[1], true)['football-scoreboard'] ?? null;
        if (!is_array($board)) {
            return null;
        }

        $snap = $board['jsonScoreAndStatusSnapshot'] ?? null;
        $snapshot = is_array($snap) ? [
            'home' => isset($snap['scr']['g']['h']) ? (int) $snap['scr']['g']['h'] : null,
            'away' => isset($snap['scr']['g']['a']) ? (int) $snap['scr']['g']['a'] : null,
            'ended' => ($snap['s'] ?? '') === 'match-ended',
        ] : null;

        return ['snapshot' => $snapshot, 'goals' => isset($board['goals']) ? $this->parseGoals($board) : null];
    }

    private function parseGoals(array $board): array
    {
        $raw = array_values(array_filter((array) $board['goals'], fn ($g) => ($g['isGoal'] ?? true) !== false));
        usort($raw, fn ($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        $homeLineup = array_flip($board['lineup']['home'] ?? []);
        $awayLineup = array_flip($board['lineup']['away'] ?? []);
        $homeTeamId = $board['teams']['home']['id'] ?? null;

        $goals = [];
        foreach ($raw as $g) {
            $playerId = $g['playerId'] ?? null;
            $own = !empty($g['ownGoal']);

            // Side of the player; an own goal counts for the other side.
            if ($playerId !== null && isset($homeLineup[$playerId])) {
                $playerSide = 'home';
            } elseif ($playerId !== null && isset($awayLineup[$playerId])) {
                $playerSide = 'away';
            } else {
                $playerSide = ($g['teamId'] ?? null) === $homeTeamId ? 'home' : 'away';
            }
            $side = $own ? ($playerSide === 'home' ? 'away' : 'home') : $playerSide;

            $player = $board['players'][$playerId] ?? [];
            $goals[] = [
                'scorer' => (string) ($player['nickname'] ?? $player['name'] ?? ''),
                'side' => $side,
                'own' => $own,
            ];
        }

        return $goals;
    }

    /** "Atlético-MG" -> "atletico-mg", "Red Bull Bragantino" -> "red-bull-bragantino" (UOL URL style). */
    public static function slug(string $name): string
    {
        if (isset(self::UOL_SLUGS[$name])) {
            return self::UOL_SLUGS[$name];
        }
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $ascii = preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii));

        return trim((string) $ascii, '-');
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $body !== false && $status === 200 ? (string) $body : null;
    }
}
