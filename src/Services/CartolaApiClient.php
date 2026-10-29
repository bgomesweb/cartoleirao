<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

class CartolaApiClient
{
    private string $baseUrl;
    private ?string $lastError = null;

    public function __construct(?string $baseUrl = null)
    {
        // The API changed domains in 2026: api.cartolafc.globo.com now only
        // 301-redirects to api.cartola.globo.com (without "fc").
        $this->baseUrl = $baseUrl ?? (getenv('CARTOLA_API_BASE') ?: 'https://api.cartola.globo.com');
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Searches teams by name (used to try matching the "Cartola team name"
     * given at sign-up to a real time_id from the API).
     *
     * Returns a list of candidate teams: [['team_id' => ..., 'name' => ..., 'cartola_name' => ..., 'slug' => ...], ...]
     * Returns [] on network/timeout failure — the link stays pending for
     * the admin to resolve manually in that case.
     */
    public function searchTeamsByName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $url = $this->baseUrl . '/times?q=' . rawurlencode($name);
        $response = $this->get($url);

        if ($response === null || !is_array($response)) {
            return [];
        }

        $teams = [];
        foreach ($response as $item) {
            if (!isset($item['time_id'])) {
                continue;
            }

            $teams[] = [
                'team_id' => (int) $item['time_id'],
                'name' => $item['nome'] ?? '',
                'cartola_name' => $item['nome_cartola'] ?? ($item['nome'] ?? ''),
                'slug' => $item['slug'] ?? '',
                'crest' => $item['url_escudo_svg'] ?? ($item['escudo'] ?? null),
            ];
        }

        return $teams;
    }

    public function marketStatus(): ?array
    {
        return $this->get($this->baseUrl . '/mercado/status');
    }

    /**
     * Returns the club dictionary (key = club id in the API).
     */
    public function clubs(): ?array
    {
        return $this->get($this->baseUrl . '/clubes');
    }

    /** Games (with live score) of the round whose market is currently the "current" one. */
    public function currentGames(): ?array
    {
        return $this->get($this->baseUrl . '/partidas');
    }

    /** Athletes that already have scouts in the round being played (live). */
    public function scoredAthletes(): ?array
    {
        return $this->get($this->baseUrl . '/atletas/pontuados');
    }

    /** Current lineup of a team (the one for the round in progress). */
    public function teamLineup(int $teamId): ?array
    {
        return $this->get($this->baseUrl . '/time/id/' . $teamId);
    }

    /**
     * Lineups of several teams fetched in parallel.
     *
     * @param int[] $teamIds
     * @return array<int, array> teamId => response (teams that failed are left out)
     */
    public function teamLineups(array $teamIds): array
    {
        $result = [];
        foreach (array_chunk($teamIds, 10) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $id) {
                $ch = curl_init($this->baseUrl . '/time/id/' . $id);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$id] = $ch;
            }
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $id => $ch) {
                $decoded = json_decode((string) curl_multi_getcontent($ch), true);
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400 && is_array($decoded)) {
                    $result[$id] = $decoded;
                }
                curl_multi_remove_handle($mh, $ch);
            }
            curl_multi_close($mh);
        }

        return $result;
    }

    public function gamesForRound(int $round): ?array
    {
        return $this->get($this->baseUrl . '/partidas/' . $round);
    }

    public function teamScoreForRound(int $teamId, int $round): ?array
    {
        return $this->get($this->baseUrl . '/time/id/' . $teamId . '/' . $round);
    }

    private function get(string $url): ?array
    {
        return $this->request('GET', $url);
    }

    private function request(string $method, string $url, ?string $body = null, array $extraHeaders = [], ?string $userAgent = null): ?array
    {
        $this->lastError = null;

        $headers = array_merge(['Accept: application/json'], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $userAgent ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($responseBody === false || $curlError) {
            $this->lastError = "Erro de conexão (curl): {$curlError}";
            return null;
        }

        $decoded = json_decode($responseBody, true);

        if ($status >= 400) {
            $apiMessage = is_array($decoded) ? ($decoded['userMessage'] ?? $decoded['message'] ?? '') : '';
            if (!$apiMessage && $responseBody) {
                $apiMessage = substr(strip_tags((string) $responseBody), 0, 300);
            }
            $this->lastError = "HTTP {$status} ao acessar {$url}" . ($apiMessage ? " — {$apiMessage}" : '');
            return null;
        }

        if (!is_array($decoded)) {
            $this->lastError = 'Resposta da API não é um JSON válido: ' . substr((string) $responseBody, 0, 200);
            return null;
        }

        return $decoded;
    }
}
