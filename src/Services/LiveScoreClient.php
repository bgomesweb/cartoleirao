<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

/**
 * Live scores for goal notifications, from sources fresher than the Cartola API
 * (whose /partidas answer is cached for 5 minutes):
 *
 *  - rtw.uol.com (channel "ss-lv"): the realtime feed behind the UOL app. One uncached request
 *    returns score and status of every game on air; it is the primary source for both.
 *  - ge.globo.com (api.globoesporte.globo.com): the list of games of a round (cached for 60s here).
 *    Game and club ids are the same ones Cartola uses. Its score is only a fallback.
 *  - placar.uol.com.br: the match page embeds the goals with their scorers (fetched with a
 *    cache-busting query, since the CDN keeps the page for 60s).
 */
class LiveScoreClient
{
    private const GE_TABLE = 'd1a37fa4-e948-43a6-ba53-ab24ab3a45b1';
    private const GE_PHASE = 'fase-unica-campeonato-brasileiro-2026';
    private const UOL_FEED = 'https://rtw.uol.com/sub?id=ss-lv';
    private const UOL_PAGE_CACHE_SECONDS = 3;
    private const UOL_PAGE_TRIES = 4;
    private const UOL_FEED_CACHE_SECONDS = 2;
    private const GE_CACHE_SECONDS = 60;
    private const UOL_STATUS_NOT_STARTED = 10;
    private const UOL_STATUS_ENDED = 11;
    /** ge name => UOL slug, for the clubs the two sites name differently. */
    private const UOL_SLUGS = ['Bragantino' => 'red-bull-bragantino'];

    /** @var array<int, array{at: int, goals: ?array}> */
    private array $goalsCache = [];
    /** @var array<int, array{at: int, list: ?array}> */
    private array $geCache = [];
    /** @var array{at: int, games: ?array}|null */
    private ?array $feedCache = null;

    /**
     * @return array<int, array>|null games of the round: id, start (timestamp), home_id, away_id,
     *                                home, away, home_score, away_score (null = no score yet), finished
     */
    public function roundGames(int $round): ?array
    {
        $list = $this->geGames($round);
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

        // The ge API lags minutes behind the real game; the UOL realtime feed is the same one the UOL
        // app shows, so for every game it knows about, its score and status win.
        $feed = $this->liveFeed();
        foreach ($games as &$game) {
            $live = $feed[self::slug($game['home']) . '|' . self::slug($game['away'])] ?? null;
            if ($live === null || $live['status'] === self::UOL_STATUS_NOT_STARTED) {
                continue;
            }
            if ($live['home'] !== null && $live['away'] !== null) {
                $game['home_score'] = $live['home'];
                $game['away_score'] = $live['away'];
            }
            $game['finished'] = $live['status'] === self::UOL_STATUS_ENDED;
        }
        unset($game);

        return $games;
    }

    /** Whether the realtime feed answered on the last call (for the log). */
    public function feedAvailable(): bool
    {
        return !empty($this->feedCache['games']);
    }

    /**
     * Goals of a game in chronological order, annulled ones excluded.
     * Each: scorer (nickname), side ('home'|'away' = the side the goal counts for), own (own goal?).
     * Null when the match page can't be read.
     *
     * The UOL page is served by replicas that lag each other by minutes, so when the caller knows how
     * many goals there must be ($expected), the page is requested again until one replica has them all.
     */
    public function goals(array $game, int $expected = 0): ?array
    {
        $cached = $this->goalsCache[$game['id']] ?? null;
        if ($cached && time() - $cached['at'] < self::UOL_PAGE_CACHE_SECONDS && count($cached['goals'] ?? []) >= $expected) {
            return $cached['goals'];
        }

        $best = null;
        for ($try = 0; $try < self::UOL_PAGE_TRIES; $try++) {
            $goals = $this->fetchGoals($game);
            if ($goals !== null && ($best === null || count($goals) > count($best))) {
                $best = $goals;
            }
            if ($best !== null && count($best) >= $expected) {
                break;
            }
        }
        $this->goalsCache[$game['id']] = ['at' => time(), 'goals' => $best];

        return $best;
    }

    /** @return array|null raw ge list of the round, cached for a minute */
    private function geGames(int $round): ?array
    {
        $cached = $this->geCache[$round] ?? null;
        if ($cached && time() - $cached['at'] < self::GE_CACHE_SECONDS) {
            return $cached['list'];
        }

        $url = sprintf(
            'https://api.globoesporte.globo.com/tabela/%s/fase/%s/rodada/%d/jogos/',
            self::GE_TABLE,
            self::GE_PHASE,
            $round
        );
        $list = json_decode((string) $this->fetch($url), true);
        $list = is_array($list) ? $list : null;
        // A failure is not cached, so the next call tries again.
        if ($list !== null) {
            $this->geCache[$round] = ['at' => time(), 'list' => $list];
        }

        return $list;
    }

    /**
     * Brasileirão games known to the UOL realtime feed, keyed "home-slug|away-slug" (a pairing only
     * happens once a season, so this also survives a rescheduled date).
     *
     * @return array<string, array{status: int, home: ?int, away: ?int}>
     */
    private function liveFeed(): array
    {
        if ($this->feedCache && time() - $this->feedCache['at'] < self::UOL_FEED_CACHE_SECONDS) {
            return $this->feedCache['games'] ?? [];
        }

        $games = null;
        $body = $this->fetch(self::UOL_FEED, 4);
        if ($body !== null && preg_match('/^[^(]*\((.*)\);?\s*$/s', $body, $m)) {
            $feed = json_decode($m[1], true)['subchannels'] ?? null;
            if (is_array($feed)) {
                $games = [];
                foreach ($feed as $match) {
                    $home = $match['t']['h'] ?? null;
                    $away = $match['t']['a'] ?? null;
                    if (($match['c']['sl'] ?? '') !== 'brasileirao' || empty($home['sl']) || empty($away['sl'])) {
                        continue;
                    }
                    $games[$home['sl'] . '|' . $away['sl']] = [
                        'status' => (int) ($match['st'] ?? 0),
                        'home' => isset($home['g']) ? (int) $home['g'] : null,
                        'away' => isset($away['g']) ? (int) $away['g'] : null,
                    ];
                }
            }
        }

        $this->feedCache = ['at' => time(), 'games' => $games];

        return $games ?? [];
    }

    private function fetchGoals(array $game): ?array
    {
        if (!$game['start']) {
            return null;
        }

        // The query string skips the CDN's 60s cache: without it a new goal's scorer shows up late.
        $url = sprintf(
            'https://placar.uol.com.br/esporte/futebol/brasileirao/%s/%s-x-%s.htm?_=%s',
            date('Y/m/d', $game['start']),
            self::slug($game['home']),
            self::slug($game['away']),
            uniqid()
        );
        $html = $this->fetch($url);
        if (!$html || !preg_match('#<script id="VUELAND_STATE" type="application/json">(.*?)</script>#s', $html, $m)) {
            return null;
        }

        $board = json_decode($m[1], true)['football-scoreboard'] ?? null;

        return is_array($board) && isset($board['goals']) ? $this->parseGoals($board) : null;
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

    private function fetch(string $url, int $timeout = 8): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
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
