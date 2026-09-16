<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Database\Connection;

class CartolaAuthService
{
    // Token expira num prazo desconhecido (API não-oficial); renovamos por
    // segurança a cada 12h em vez de tentar adivinhar a validade real.
    private const TOKEN_VALIDO_HORAS = 12;

    private CartolaApiClient $api;
    private ?string $ultimoErro = null;

    public function __construct(?CartolaApiClient $api = null)
    {
        $this->api = $api ?? new CartolaApiClient();
    }

    public function getUltimoErro(): ?string
    {
        return $this->ultimoErro;
    }

    /**
     * Retorna um token válido (do cache, ou logando de novo se necessário).
     * Retorna null se não houver credenciais configuradas ou se o login falhar.
     */
    public function obterToken(): ?string
    {
        $tokenCache = $this->lerTokenCache();
        if ($tokenCache) {
            return $tokenCache;
        }

        $email = getenv('CARTOLA_LOGIN_EMAIL') ?: null;
        $senha = getenv('CARTOLA_LOGIN_SENHA') ?: null;

        if (!$email || !$senha) {
            $this->ultimoErro = 'CARTOLA_LOGIN_EMAIL / CARTOLA_LOGIN_SENHA não configurados no .env';
            return null;
        }

        $token = $this->api->login($email, $senha);

        if (!$token) {
            $this->ultimoErro = $this->api->getUltimoErro() ?? 'Login falhou por motivo desconhecido';
            return null;
        }

        $this->salvarTokenCache($token);

        return $token;
    }

    /** Força um novo login, ignorando qualquer token em cache (usado quando o token cacheado dá 401/403). */
    public function renovarToken(): ?string
    {
        $this->limparTokenCache();

        return $this->obterToken();
    }

    /**
     * Salva manualmente um token obtido fora do sistema (colado pelo admin,
     * capturado no DevTools do navegador dele já logado em cartola.globo.com
     * — o access_token OIDC enviado no header "Authorization: Bearer ...").
     * Esse é o caminho recomendado pra "logar" no sistema: como o login
     * automático esbarra em captcha, a gente aproveita a sessão que o
     * próprio admin já resolveu manualmente no navegador.
     *
     * Se o token colado for um JWT (é o caso do access_token OIDC atual),
     * lemos a expiração real do claim `exp` em vez de chutar
     * TOKEN_VALIDO_HORAS — assim o admin sabe exatamente quando precisa
     * colar um novo, sem depender de uma estimativa.
     *
     * @throws \InvalidArgumentException se o token vier vazio
     */
    public function salvarTokenManual(string $token): void
    {
        $token = trim($token);

        // Se colaram "Bearer eyJ..." por engano, aceita e remove o prefixo.
        if (stripos($token, 'Bearer ') === 0) {
            $token = trim(substr($token, 7));
        }

        if ($token === '') {
            throw new \InvalidArgumentException('Token vazio.');
        }

        $expiraEm = $this->extrairExpiracaoJwt($token);
        $this->salvarTokenCache($token, $expiraEm);
        $this->ultimoErro = null;
    }

    /**
     * Extrai o claim `exp` (timestamp Unix) do payload de um JWT, sem
     * validar assinatura (só precisamos ler a data de expiração declarada;
     * quem valida a assinatura de verdade é a própria API do Cartola quando
     * a gente usa o token). Retorna null se o token não for um JWT
     * reconhecível ou não tiver `exp`.
     */
    private function extrairExpiracaoJwt(string $token): ?string
    {
        $partes = explode('.', $token);
        if (count($partes) !== 3) {
            return null; // não é um JWT (ex: token opaco do fluxo antigo glbId)
        }

        $payloadBase64 = strtr($partes[1], '-_', '+/');
        $payloadJson = base64_decode(str_pad($payloadBase64, strlen($payloadBase64) % 4 === 0 ? strlen($payloadBase64) : strlen($payloadBase64) + (4 - strlen($payloadBase64) % 4), '='), true);

        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || empty($payload['exp']) || !is_numeric($payload['exp'])) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) $payload['exp']);
    }

    /** Descarta o token atual (força um novo login/colagem na próxima chamada). */
    public function invalidarToken(): void
    {
        $this->limparTokenCache();
    }

    /**
     * Estado atual do token pra exibir no admin: se existe, se ainda é
     * válido, e quando foi salvo/expira.
     *
     * @return array{presente:bool, valido:boolean, autenticado_em:?string, expira_em:?string, horas_restantes:?float}
     */
    public function statusToken(): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT token, autenticado_em, expira_em FROM cartola_auth WHERE id = 1');
        $row = $stmt->fetch();

        if (!$row || empty($row['token']) || empty($row['autenticado_em'])) {
            return [
                'presente' => false,
                'valido' => false,
                'autenticado_em' => null,
                'expira_em' => null,
                'horas_restantes' => null,
                'expiracao_real' => false,
            ];
        }

        $expiraEmTimestamp = $this->calcularExpiracao($row);
        $horasRestantes = ($expiraEmTimestamp - time()) / 3600;

        return [
            'presente' => true,
            'valido' => time() < $expiraEmTimestamp,
            'autenticado_em' => $row['autenticado_em'],
            'expira_em' => date('Y-m-d H:i:s', $expiraEmTimestamp),
            'horas_restantes' => round($horasRestantes, 1),
            // true = veio do claim `exp` do JWT (data exata); false = estimativa de TOKEN_VALIDO_HORAS
            'expiracao_real' => !empty($row['expira_em']),
        ];
    }

    /** Calcula o timestamp de expiração: usa expira_em (real, do JWT) se disponível, senão o fallback de TOKEN_VALIDO_HORAS. */
    private function calcularExpiracao(array $row): int
    {
        if (!empty($row['expira_em'])) {
            return (int) strtotime($row['expira_em']);
        }

        return (int) strtotime($row['autenticado_em']) + (self::TOKEN_VALIDO_HORAS * 3600);
    }

    private function lerTokenCache(): ?string
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT token, autenticado_em, expira_em FROM cartola_auth WHERE id = 1');
        $row = $stmt->fetch();

        if (!$row || empty($row['token']) || empty($row['autenticado_em'])) {
            return null;
        }

        if (time() >= $this->calcularExpiracao($row)) {
            return null;
        }

        return $row['token'];
    }

    private function salvarTokenCache(string $token, ?string $expiraEm = null): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE cartola_auth SET token = :token, autenticado_em = NOW(), expira_em = :expira_em WHERE id = 1
        ');
        $stmt->execute([':token' => $token, ':expira_em' => $expiraEm]);
    }

    private function limparTokenCache(): void
    {
        $pdo = Connection::get();
        $pdo->exec('UPDATE cartola_auth SET token = NULL, autenticado_em = NULL, expira_em = NULL WHERE id = 1');
    }
}
