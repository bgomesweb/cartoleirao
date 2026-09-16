<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

class CartolaApiClient
{
    private string $baseUrl;
    private ?string $ultimoErro = null;

    public function __construct(?string $baseUrl = null)
    {
        // A API mudou de domínio em 2026: api.cartolafc.globo.com agora só
        // redireciona (301) pra api.cartola.globo.com (sem o "fc").
        $this->baseUrl = $baseUrl ?? (getenv('CARTOLA_API_BASE') ?: 'https://api.cartola.globo.com');
    }

    public function getUltimoErro(): ?string
    {
        return $this->ultimoErro;
    }

    /**
     * Busca times pelo nome (usado pra tentar vincular o "nome do time no Cartola"
     * informado na inscrição a um time_id real da API).
     *
     * Retorna uma lista de times candidatos: [['time_id' => ..., 'nome' => ..., 'nome_cartola' => ..., 'slug' => ...], ...]
     * Retorna [] em caso de falha de rede/timeout — a vinculação nesse caso fica
     * pendente pro admin resolver manualmente.
     */
    public function buscarTimesPorNome(string $nome): array
    {
        $nome = trim($nome);
        if ($nome === '') {
            return [];
        }

        $url = $this->baseUrl . '/times?q=' . rawurlencode($nome);
        $resposta = $this->get($url);

        if ($resposta === null || !is_array($resposta)) {
            return [];
        }

        $times = [];
        foreach ($resposta as $item) {
            if (!isset($item['time_id'])) {
                continue;
            }
            $times[] = [
                'time_id' => (int) $item['time_id'],
                'nome' => $item['nome'] ?? '',
                'nome_cartola' => $item['nome_cartola'] ?? ($item['nome'] ?? ''),
                'slug' => $item['slug'] ?? '',
                'escudo' => $item['url_escudo_svg'] ?? ($item['escudo'] ?? null),
            ];
        }

        return $times;
    }

    public function statusMercado(): ?array
    {
        return $this->get($this->baseUrl . '/mercado/status');
    }

    /**
     * Retorna o dicionário de clubes (chave = id do clube na API).
     */
    public function clubes(): ?array
    {
        return $this->get($this->baseUrl . '/clubes');
    }

    public function partidasDaRodada(int $rodada): ?array
    {
        return $this->get($this->baseUrl . '/partidas/' . $rodada);
    }

    public function pontuacaoTimeNaRodada(int $timeId, int $rodada): ?array
    {
        return $this->get($this->baseUrl . '/time/id/' . $timeId . '/' . $rodada);
    }

    /**
     * Autentica no login da Globo e retorna o token (glbId) usado nos
     * endpoints /auth/* da API do Cartola (ex: auth/liga/{slug}).
     * Retorna null em caso de falha (credenciais inválidas, API fora do ar, etc).
     */
    public function login(string $email, string $senha): ?string
    {
        $this->ultimoErro = null;

        $body = json_encode([
            'payload' => [
                'email' => $email,
                'password' => $senha,
                'serviceId' => 4728, // id de serviço do Cartola FC no login da Globo
            ],
        ]);

        // NOTA (confirmado em produção, ago/2026): esse endpoint legado
        // (login.globo.com/api/authentication -> glbId) parece ter sido
        // substituído pelo login OIDC via goidc.globo.com — o site
        // cartola.globo.com hoje autentica as chamadas à API com
        // "Authorization: Bearer <access_token OIDC>" + headers
        // x-glb-auth/x-glb-app (ver ligaOficial() abaixo), não mais com
        // X-GLB-Token. Deixamos esse método como estava por compatibilidade,
        // mas ele provavelmente já não funciona mais — o caminho real hoje é
        // colar o access_token OIDC manualmente (ver CartolaAuthService).
        $resposta = $this->request('POST', 'https://login.globo.com/api/authentication', $body, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Cache-Control: no-cache',
            'Origin: https://cartola.globo.com',
            'Referer: https://cartola.globo.com/',
        ], 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');

        if ($resposta === null) {
            return null;
        }

        if (empty($resposta['glbId'])) {
            $mensagem = $resposta['userMessage'] ?? $resposta['message'] ?? 'resposta sem glbId';
            $this->ultimoErro = "Login falhou: {$mensagem}";
            return null;
        }

        return (string) $resposta['glbId'];
    }

    /**
     * Consulta a liga OFICIAL do Cartola (times cadastrados de verdade na
     * liga criada em cartola.globo.com). Requer o access_token OIDC obtido
     * no login em cartola.globo.com (ver CartolaAuthService) — confirmado em
     * produção que a API espera "Authorization: Bearer <token>" mais os
     * headers x-glb-auth/x-glb-app, e NÃO o antigo X-GLB-Token.
     *
     * A resposta da API não tem documentação oficial; aceitamos algumas
     * variações de formato defensivamente (ver SincronizacaoService).
     */
    public function ligaOficial(string $slug, string $token, int $pagina = 1): ?array
    {
        $url = $this->baseUrl . '/auth/liga/' . rawurlencode($slug) . '?page=' . $pagina;

        return $this->request('GET', $url, null, [
            'Authorization: Bearer ' . $token,
            'x-glb-auth: oidc',
            'x-glb-app: cartola_web',
            'Origin: https://cartola.globo.com',
            'Referer: https://cartola.globo.com/',
        ]);
    }

    /**
     * Consulta uma competição do tipo "Pontos Corridos" (formato novo do
     * Cartola, com ID opaco em vez de slug legível — ex: URL
     * cartola.globo.com/#!/competicoes/pontoscorridos/{id}). Endpoint
     * confirmado em produção (ago/2026): GET
     * /auth/competicoes/pontoscorridos/slug/{id} (o segmento "slug" no path
     * é fixo/literal, o {id} é o hash da URL, não um slug de verdade).
     * Diferente de auth/liga/{slug}, essa resposta já vem completa (todos os
     * participantes de uma vez, sem paginação) e já traz `confrontos` e
     * `ranking` prontos.
     */
    public function competicaoPontosCorridos(string $id, string $token): ?array
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
     * Busca ligas (do usuário autenticado) pelo nome — útil só pra
     * diagnosticar qual é o slug real de uma liga quando o slug salvo no
     * admin está dando 404 no auth/liga/{slug}. Não é usado no fluxo normal
     * de sincronização.
     */
    public function buscarLigasPorNome(string $nome, string $token): ?array
    {
        $url = $this->baseUrl . '/ligas?q=' . rawurlencode($nome);

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

    private function request(string $metodo, string $url, ?string $body = null, array $headersExtra = [], ?string $userAgent = null): ?array
    {
        $this->ultimoErro = null;

        $headers = array_merge(['Accept: application/json'], $headersExtra);

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

        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $respostaBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);

        if ($respostaBody === false || $erroCurl) {
            $this->ultimoErro = "Erro de conexão (curl): {$erroCurl}";
            return null;
        }

        $decoded = json_decode($respostaBody, true);

        if ($status >= 400) {
            $mensagemApi = is_array($decoded) ? ($decoded['userMessage'] ?? $decoded['message'] ?? '') : '';
            if (!$mensagemApi && $respostaBody) {
                $mensagemApi = substr(strip_tags((string) $respostaBody), 0, 300);
            }
            $this->ultimoErro = "HTTP {$status} ao acessar {$url}" . ($mensagemApi ? " — {$mensagemApi}" : '');
            return null;
        }

        if (!is_array($decoded)) {
            $this->ultimoErro = 'Resposta da API não é um JSON válido: ' . substr((string) $respostaBody, 0, 200);
            return null;
        }

        return $decoded;
    }
}
