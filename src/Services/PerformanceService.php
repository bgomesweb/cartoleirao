<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

/**
 * Season performance of one team, built from the Cartola API's per-round view of the team
 * (/time/id/{id}/{round}): points, patrimony and its variation, starters and captain. Past rounds
 * never change, so each one is cached on disk after the first fetch.
 */
class PerformanceService
{
    private const ROUND_TTL = 21600;
    private const TOP = 5;

    private string $dir;

    public function __construct(private CartolaApiClient $api)
    {
        $this->dir = sys_get_temp_dir() . '/cartoleirao-perf';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    /**
     * @param int[] $rounds round numbers that have a score
     * @return array{rounds: array[], crest: ?string, most_picked: array[], most_captain: array[], missing: int}
     */
    public function forTeam(int $cartolaTeamId, array $rounds): array
    {
        $responses = [];
        $missing = [];
        foreach ($rounds as $n) {
            $cached = $this->read("{$cartolaTeamId}-{$n}");
            if ($cached !== null) {
                $responses[$n] = $cached;
            } else {
                $missing[] = $n;
            }
        }
        if ($missing) {
            foreach ($this->api->teamRounds($cartolaTeamId, $missing) as $n => $response) {
                $responses[$n] = $this->compact($response);
                $this->write("{$cartolaTeamId}-{$n}", $responses[$n]);
            }
        }
        ksort($responses);

        $picked = [];
        $captains = [];
        $series = [];
        $crest = null;
        foreach ($responses as $n => $r) {
            $crest = $r['crest'] ?? $crest;
            $series[] = ['round' => $n, 'points' => $r['points'], 'patrimony' => $r['patrimony'], 'variation' => $r['variation']];
            foreach ($r['players'] as $p) {
                $picked[$p['id']] ??= $p + ['count' => 0];
                $picked[$p['id']]['count']++;
                if ($p['id'] === $r['captain_id']) {
                    $captains[$p['id']] ??= $p + ['count' => 0];
                    $captains[$p['id']]['count']++;
                }
            }
        }

        return [
            'rounds' => $series,
            'crest' => $crest,
            'most_picked' => self::top($picked),
            'most_captain' => self::top($captains),
            'missing' => count($rounds) - count($responses),
        ];
    }

    private static function top(array $players): array
    {
        usort($players, fn($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return array_slice($players, 0, self::TOP);
    }

    private function compact(array $response): array
    {
        $players = [];
        foreach ($response['atletas'] ?? [] as $a) {
            $players[] = [
                'id' => (int) $a['atleta_id'],
                'name' => $a['apelido'] ?? '',
                'photo' => isset($a['foto']) ? str_replace('FORMATO', '220x220', $a['foto']) : null,
            ];
        }

        return [
            'points' => round((float) ($response['pontos'] ?? 0), 2),
            'patrimony' => isset($response['patrimonio']) ? round((float) $response['patrimonio'], 2) : null,
            'variation' => isset($response['variacao_patrimonio']) ? round((float) $response['variacao_patrimonio'], 2) : null,
            'crest' => $response['time']['url_escudo_png'] ?? $response['time']['url_escudo_svg'] ?? null,
            'captain_id' => isset($response['capitao_id']) ? (int) $response['capitao_id'] : null,
            'players' => $players,
        ];
    }

    private function read(string $key): ?array
    {
        $file = "{$this->dir}/{$key}.json";
        if (!is_file($file) || (time() - (int) filemtime($file)) > self::ROUND_TTL) {
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
