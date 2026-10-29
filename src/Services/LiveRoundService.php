<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Models\Club;

/**
 * Partial (real-time) score of the teams while the Cartola market is closed: the lineup of each team
 * (/time/id/{id}) crossed with the points the athletes are making right now (/atletas/pontuados).
 * The captain counts 1.5x. Lineups barely change while the market is closed, so they are cached for
 * minutes; the athletes' points are cached for a few seconds, so many visitors cost one API call.
 * Substitutions are applied: a starter whose game ended without him playing is replaced by the bench
 * player of his position, once that one has played (the reserva de luxo counts 1.5x).
 */
class LiveRoundService
{
    private const SCORES_TTL = 15;
    private const LINEUP_TTL = 600;
    private const CAPTAIN_MULTIPLIER = 1.5;

    private string $dir;

    public function __construct(private CartolaApiClient $api)
    {
        $this->dir = sys_get_temp_dir() . '/cartoleirao-live';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    /**
     * @param int[] $cartolaTeamIds
     * @return array<int, array> cartolaTeamId => ['points' => float, 'players' => [...], 'president' => ?string]; absent if unavailable
     */
    public function partials(array $cartolaTeamIds, int $round): array
    {
        $scores = $this->athleteScores();
        if ($scores === null) {
            return [];
        }
        $scores['finished_clubs'] = $this->finishedClubs();

        $lineups = [];
        $missing = [];
        foreach ($cartolaTeamIds as $id) {
            $cached = $this->read("lineup2-{$round}-{$id}", self::LINEUP_TTL);
            if ($cached !== null) {
                $lineups[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }
        if ($missing) {
            foreach ($this->api->teamLineups($missing) as $id => $response) {
                $lineups[$id] = $this->compactLineup($response);
                $this->write("lineup2-{$round}-{$id}", $lineups[$id]);
            }
            // lineup fetch failed: fall back to a stale copy, if there is one
            foreach (array_diff($missing, array_keys($lineups)) as $id) {
                if (($stale = $this->read("lineup2-{$round}-{$id}", PHP_INT_MAX)) !== null) {
                    $lineups[$id] = $stale;
                }
            }
        }

        $result = [];
        foreach ($lineups as $id => $lineup) {
            $result[$id] = $this->score($lineup, $scores);
        }

        return $result;
    }

    private function compactLineup(array $response): array
    {
        $players = [];
        foreach ($response['atletas'] ?? [] as $a) {
            $players[] = [
                'id' => (int) $a['atleta_id'],
                'name' => $a['apelido'] ?? '',
                'photo' => $a['foto'] ?? null,
                'position_id' => (int) ($a['posicao_id'] ?? 0),
                'club_id' => (int) ($a['clube_id'] ?? 0),
            ];
        }

        $reserves = [];
        foreach ($response['reservas'] ?? [] as $a) {
            $reserves[] = [
                'id' => (int) $a['atleta_id'],
                'name' => $a['apelido'] ?? '',
                'photo' => $a['foto'] ?? null,
                'position_id' => (int) ($a['posicao_id'] ?? 0),
                'club_id' => (int) ($a['clube_id'] ?? 0),
            ];
        }

        return [
            'captain_id' => isset($response['capitao_id']) ? (int) $response['capitao_id'] : null,
            'luxury_id' => isset($response['reserva_luxo_id']) ? (int) $response['reserva_luxo_id'] : null,
            'president' => $response['time']['nome_cartola'] ?? null,
            'crest' => $response['time']['url_escudo_png'] ?? $response['time']['url_escudo_svg'] ?? null,
            'players' => $players,
            'reserves' => $reserves,
        ];
    }

    private function score(array $lineup, array $scores): array
    {
        $decorate = function (array $p) use ($scores): array {
            $live = $scores['athletes'][$p['id']] ?? null;

            return array_merge($p, [
                'photo' => self::photoUrl($live['foto'] ?? $p['photo']),
                'raw_points' => $live ? (float) $live['pontuacao'] : 0.0,
                'played' => $live !== null && $live['played'],
                'position' => $scores['positions'][$p['position_id']] ?? '',
                'club' => $scores['clubs'][$p['club_id']] ?? ['name' => '', 'crest' => null],
                'sub' => null,        // 'out' (starter replaced) | 'in' (bench player who entered)
                'sub_with' => null,   // name of the other player of the swap
                'luxury' => false,
            ]);
        };
        $starters = array_map($decorate, $lineup['players']);
        $reserves = array_map($decorate, $lineup['reserves']);

        foreach ($reserves as &$r) {
            $r['luxury'] = $r['id'] === $lineup['luxury_id'];
        }
        unset($r);

        // Bench player enters for the first starter of his position whose game ended without him playing.
        foreach ($reserves as &$r) {
            if (!$r['played']) {
                continue;
            }
            foreach ($starters as &$s) {
                if ($s['sub'] === null && $s['position_id'] === $r['position_id'] && !$s['played']
                    && in_array($s['club_id'], $scores['finished_clubs'], true)) {
                    $s['sub'] = 'out';
                    $s['sub_with'] = $r['name'];
                    $r['sub'] = 'in';
                    $r['sub_with'] = $s['name'];
                    break;
                }
            }
            unset($s);
        }
        unset($r);

        $total = 0.0;
        $finish = function (array $p, bool $isBench) use ($lineup, &$total): array {
            $p['captain'] = $p['id'] === $lineup['captain_id'];
            $multiplier = $p['captain'] || ($isBench && $p['luxury']) ? self::CAPTAIN_MULTIPLIER : 1.0;
            $p['points'] = $p['raw_points'] * $multiplier;
            // a replaced starter and a bench player who didn't enter don't count
            if ($isBench ? $p['sub'] === 'in' : $p['sub'] !== 'out') {
                $total += $p['points'];
            }

            return $p;
        };

        $players = array_map(fn($p) => $finish($p, false), $starters);
        $bench = array_map(fn($p) => $finish($p, true), $reserves);

        // goalkeeper → striker → coach, the usual order of the lineup
        usort($players, fn($a, $b) => $a['position_id'] <=> $b['position_id']);
        usort($bench, fn($a, $b) => $a['position_id'] <=> $b['position_id']);

        return [
            'points' => $total,
            'players' => $players,
            'reserves' => $bench,
            'president' => $lineup['president'],
            'crest' => $lineup['crest'] ?? null,
        ];
    }

    /** The API returns ".../FORMATO.png" as a placeholder for the size. */
    private static function photoUrl(?string $url): ?string
    {
        return $url ? str_replace('FORMATO', '220x220', $url) : null;
    }

    /** @return array{athletes: array, clubs: array, positions: array}|null */
    private function athleteScores(): ?array
    {
        $cached = $this->read('scores', self::SCORES_TTL);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->api->scoredAthletes();
        if (!$response || !isset($response['atletas'])) {
            return $this->read('scores', PHP_INT_MAX); // stale beats nothing
        }

        $data = ['athletes' => [], 'clubs' => [], 'positions' => []];
        foreach ($response['atletas'] as $id => $a) {
            $data['athletes'][(int) $id] = [
                'pontuacao' => $a['pontuacao'] ?? 0,
                'foto' => $a['foto'] ?? null,
                'played' => (bool) ($a['entrou_em_campo'] ?? false),
            ];
        }
        foreach ($response['clubes'] ?? [] as $id => $c) {
            $data['clubs'][(int) $id] = ['name' => $c['apelido'] ?? $c['nome'] ?? '', 'crest' => $c['escudos']['45x45'] ?? null];
        }
        // the response only has clubs already playing; the local table has them all
        foreach (Club::all() as $id => $c) {
            $data['clubs'][$id] = ['name' => $c['name'], 'crest' => $c['crest_url']];
        }
        foreach ($response['posicoes'] ?? [] as $id => $pos) {
            $data['positions'][(int) $id] = $pos['nome'] ?? '';
        }
        $this->write('scores', $data);

        return $data;
    }

    /** @return int[] clubs whose game of the round is over */
    private function finishedClubs(): array
    {
        $games = $this->read('games', self::SCORES_TTL);
        if ($games === null) {
            $response = $this->api->currentGames();
            if (!$response || !isset($response['partidas'])) {
                return $this->read('games', PHP_INT_MAX)['clubs'] ?? [];
            }
            $games = ['clubs' => []];
            foreach ($response['partidas'] as $g) {
                if (($g['status_transmissao_tr'] ?? '') === 'ENCERRADA') {
                    $games['clubs'][] = (int) $g['clube_casa_id'];
                    $games['clubs'][] = (int) $g['clube_visitante_id'];
                }
            }
            $this->write('games', $games);
        }

        return $games['clubs'];
    }

    private function read(string $key, int $ttl): ?array
    {
        $file = "{$this->dir}/{$key}.json";
        if (!is_file($file) || (time() - (int) filemtime($file)) > $ttl) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function write(string $key, array $data): void
    {
        $file = "{$this->dir}/{$key}.json";
        $tmp = $file . '.' . getmypid();
        if (@file_put_contents($tmp, json_encode($data)) !== false) {
            @rename($tmp, $file);
        }
    }
}
