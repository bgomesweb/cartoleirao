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

    public function gamesForRound(int $round): ?array
    {
        return $this->get($this->baseUrl . '/partidas/' . $round);
    }

    public function teamScoreForRound(int $teamId, int $round): ?array
    {
        return $this->get($this->baseUrl . '/time/id/' . $teamId . '/' . $round);
    }

    /**
     * Authenticates against Globo's login and returns the token (glbId) used
     * by the /auth/* endpoints of the Cartola API (e.g. auth/liga/{slug}).
     * Returns null on failure (invalid credentials, API down, etc).
     */
    public function login(string $email, string $password): ?string
    {
        $this->lastError = null;

        $body = json_encode([
            'payload' => [
                'email' => $email,
                'password' => $password,
                'serviceId' => 4728, // Cartola FC's service id in Globo's login
            ],
        ]);

        // NOTE (confirmed in production, Aug/2026): this legacy endpoint
        // (login.globo.com/api/authentication -> glbId) appears to have
        // been replaced by OIDC login via goidc.globo.com — the
        // cartola.globo.com site now authenticates API calls with
        // "Authorization: Bearer <OIDC access_token>" + x-glb-auth/x-glb-app
        // headers (see officialLeague() below), not X-GLB-Token anymore.
        // Left this method as-is for compatibility, but it likely doesn't
        // work anymore — the real path today is pasting the OIDC
        // access_token manually (see CartolaAuthService).
        $response = $this->request('POST', 'https://login.globo.com/api/authentication', $body, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Cache-Control: no-cache',
            'Origin: https://cartola.globo.com',
            'Referer: https://cartola.globo.com/',
        ], 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');

        if ($response === null) {
            return null;
        }

        if (empty($response['glbId'])) {
            $message = $response['userMessage'] ?? $response['message'] ?? 'resposta sem glbId';
            $this->lastError = "Login falhou: {$message}";
            return null;
        }

        return (string) $response['glbId'];
    }

    /**
     * Queries the OFFICIAL Cartola league (teams really registered in the
     * league created on cartola.globo.com). Requires the OIDC access_token
     * obtained by logging in on cartola.globo.com (see CartolaAuthService)
     * — confirmed in production that the API expects
     * "Authorization: Bearer <token>" plus the x-glb-auth/x-glb-app headers,
     * NOT the old X-GLB-Token.
     *
     * There's no official documentation for the response shape; we
     * defensively accept a few format variations (see SyncService).
     */
    public function officialLeague(string $slug, string $token, int $page = 1): ?array
    {
        $url = $this->baseUrl . '/auth/liga/' . rawurlencode($slug) . '?page=' . $page;

        return $this->request('GET', $url, null, [
            'Authorization: Bearer ' . $token,
            'x-glb-auth: oidc',
            'x-glb-app: cartola_web',
            'Origin: https://cartola.globo.com',
            'Referer: https://cartola.globo.com/',
        ]);
    }

    /**
     * Queries a "Pontos Corridos" (round-robin) competition — the newer
     * Cartola format, with an opaque ID instead of a readable slug (e.g.
     * URL cartola.globo.com/#!/competicoes/pontoscorridos/{id}). Endpoint
     * confirmed in production (Aug/2026): GET
     * /auth/competicoes/pontoscorridos/slug/{id} (the "slug" path segment
     * is fixed/literal, the {id} is the URL hash, not an actual slug).
     * Unlike auth/liga/{slug}, this response already comes complete (all
     * participants at once, no pagination) and already includes
     * `confrontos` (matchups) and `ranking`.
     */
    public function roundRobinCompetition(string $id, string $token): ?array
    {
        $url = $this->baseUrl . '/auth/competicoes/pontoscorridos/slug/' . rawurlencode($id);

        return $this->request('GET', $url, null, [
            'Authorization: Bearer ' . $token,
            'x-glb-auth: oidc',
            'x-glb-app: cartola_web',
            'Origin: https://cartola.globo.com',
            'Referer: https://cartola.globo.com/',
        ]);
    }

    /**
     * Searches (the authenticated user's) leagues by name — only useful to
     * diagnose what the real slug of a league is when the slug saved in
     * the admin panel is 404ing on auth/liga/{slug}. Not used in the
     * normal sync flow.
     */
    public function searchLeaguesByName(string $name, string $token): ?array
    {
        $url = $this->baseUrl . '/ligas?q=' . rawurlencode($name);

        return $this->request('GET', $url, null, [
            'Authorization: Bearer ' . $token,
            'x-glb-auth: oidc',
            'x-glb-app: cartola_web',
            'Origin: https://cartola.globo.com',
            'Referer: https://cartola.globo.com/',
        ]);
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
