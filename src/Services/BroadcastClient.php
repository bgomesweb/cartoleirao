<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

/**
 * Where a game is broadcast, from 365Scores (neither the Cartola nor the ge API say it).
 * The day's list gives the game id; the game's own answer carries the TV networks.
 */
class BroadcastClient
{
    private const BASE = 'https://webws.365scores.com/web/';
    private const PARAMS = 'appTypeId=5&langId=31&timezoneName=America/Sao_Paulo&userCountryId=21';

    /** @var array<string, array[]|null> day (Y-m-d) => games of that day */
    private array $days = [];
    /** @var array<string, string|null> "day|home|away" => "Premiere, Sportv" */
    private array $channels = [];

    /** "Premiere, Sportv", or null when the games' channels aren't known (or the source is down). */
    public function channels(string $home, string $away, string $day): ?string
    {
        $key = "{$day}|{$home}|{$away}";
        if (array_key_exists($key, $this->channels)) {
            return $this->channels[$key];
        }

        $id = null;
        foreach ($this->day($day) ?? [] as $g) {
            if (!empty($g['hasTVNetworks'])
                && self::sameTeam($home, (string) ($g['homeCompetitor']['name'] ?? ''))
                && self::sameTeam($away, (string) ($g['awayCompetitor']['name'] ?? ''))) {
                $id = (int) $g['id'];
                break;
            }
        }

        $names = [];
        if ($id) {
            $game = $this->get('game/?' . self::PARAMS . '&gameId=' . $id)['game'] ?? [];
            $names = array_values(array_unique(array_filter(array_column($game['tvNetworks'] ?? [], 'name'))));
        }

        return $this->channels[$key] = $names ? implode(', ', $names) : null;
    }

    private function day(string $day): ?array
    {
        if (!array_key_exists($day, $this->days)) {
            $d = date('d/m/Y', strtotime($day));
            $this->days[$day] = $this->get('games/allscores/?' . self::PARAMS . "&startDate={$d}&endDate={$d}&sports=1")['games'] ?? null;
        }

        return $this->days[$day];
    }

    private static function sameTeam(string $a, string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    private static function normalize(string $name): string
    {
        $name = strtolower(strtr($name, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']));

        return (string) preg_replace('/[^a-z0-9]/', '', $name);
    }

    private function get(string $path): ?array
    {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $data = $body !== false && $status === 200 ? json_decode((string) $body, true) : null;

        return is_array($data) ? $data : null;
    }
}
