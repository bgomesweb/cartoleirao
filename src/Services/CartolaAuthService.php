<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Database\Connection;

class CartolaAuthService
{
    // Token expires after an unknown period (unofficial API); we renew it
    // preemptively every 12h instead of trying to guess the real validity.
    private const TOKEN_VALID_HOURS = 12;

    private CartolaApiClient $api;
    private ?string $lastError = null;

    public function __construct(?CartolaApiClient $api = null)
    {
        $this->api = $api ?? new CartolaApiClient();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Returns a valid token (from cache, or by logging in again if
     * needed). Returns null if no credentials are configured or if the
     * login fails.
     */
    public function getToken(): ?string
    {
        $cachedToken = $this->readCachedToken();
        if ($cachedToken) {
            return $cachedToken;
        }

        $email = getenv('CARTOLA_LOGIN_EMAIL') ?: null;
        $password = getenv('CARTOLA_LOGIN_PASSWORD') ?: (getenv('CARTOLA_LOGIN_SENHA') ?: null);

        if (!$email || !$password) {
            $this->lastError = 'CARTOLA_LOGIN_EMAIL / CARTOLA_LOGIN_PASSWORD não configurados no .env';
            return null;
        }

        $token = $this->api->login($email, $password);

        if (!$token) {
            $this->lastError = $this->api->getLastError() ?? 'Login falhou por motivo desconhecido';
            return null;
        }

        $this->saveCachedToken($token);

        return $token;
    }

    /** Forces a new login, ignoring any cached token (used when the cached token gets a 401/403). */
    public function renewToken(): ?string
    {
        $this->clearCachedToken();

        return $this->getToken();
    }

    /**
     * Manually saves a token obtained outside the system (pasted by the
     * admin, captured in the DevTools of their browser already logged in
     * to cartola.globo.com — the OIDC access_token sent in the
     * "Authorization: Bearer ..." header). This is the recommended way to
     * "log in" to the system: since automated login hits a captcha, we
     * reuse the session the admin already solved manually in the browser.
     *
     * If the pasted token is a JWT (which the current OIDC access_token
     * is), we read the real expiration from the `exp` claim instead of
     * guessing TOKEN_VALID_HOURS — so the admin knows exactly when a new
     * one is needed, without relying on an estimate.
     *
     * @throws \InvalidArgumentException if the token is empty
     */
    public function saveManualToken(string $token): void
    {
        $token = trim($token);

        // If "Bearer eyJ..." was pasted by mistake, accept it and strip the prefix.
        if (stripos($token, 'Bearer ') === 0) {
            $token = trim(substr($token, 7));
        }

        if ($token === '') {
            throw new \InvalidArgumentException('Token vazio.');
        }

        $expiresAt = $this->extractJwtExpiration($token);
        $this->saveCachedToken($token, $expiresAt);
        $this->lastError = null;
    }

    /**
     * Extracts the `exp` claim (Unix timestamp) from a JWT's payload,
     * without validating the signature (we only need to read the declared
     * expiration date; the Cartola API itself validates the signature when
     * we actually use the token). Returns null if the token isn't a
     * recognizable JWT or has no `exp`.
     */
    private function extractJwtExpiration(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null; // not a JWT (e.g. the old glbId opaque token)
        }

        $payloadBase64 = strtr($parts[1], '-_', '+/');
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

    /** Discards the current token (forces a new login/paste on the next call). */
    public function invalidateToken(): void
    {
        $this->clearCachedToken();
    }

    /**
     * Current token state, for display in the admin panel: whether it
     * exists, whether it's still valid, and when it was saved/expires.
     *
     * @return array{present:bool, valid:boolean, authenticated_at:?string, expires_at:?string, hours_remaining:?float}
     */
    public function tokenStatus(): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT token, authenticated_at, expires_at FROM cartola_auth_token WHERE id = 1');
        $row = $stmt->fetch();

        if (!$row || empty($row['token']) || empty($row['authenticated_at'])) {
            return [
                'present' => false,
                'valid' => false,
                'authenticated_at' => null,
                'expires_at' => null,
                'hours_remaining' => null,
                'real_expiration' => false,
            ];
        }

        $expiresAtTimestamp = $this->computeExpiration($row);
        $hoursRemaining = ($expiresAtTimestamp - time()) / 3600;

        return [
            'present' => true,
            'valid' => time() < $expiresAtTimestamp,
            'authenticated_at' => $row['authenticated_at'],
            'expires_at' => date('Y-m-d H:i:s', $expiresAtTimestamp),
            'hours_remaining' => round($hoursRemaining, 1),
            // true = came from the JWT's `exp` claim (exact date); false = TOKEN_VALID_HOURS estimate
            'real_expiration' => !empty($row['expires_at']),
        ];
    }

    /** Computes the expiration timestamp: uses expires_at (real, from the JWT) if available, otherwise falls back to TOKEN_VALID_HOURS. */
    private function computeExpiration(array $row): int
    {
        if (!empty($row['expires_at'])) {
            return (int) strtotime($row['expires_at']);
        }

        return (int) strtotime($row['authenticated_at']) + (self::TOKEN_VALID_HOURS * 3600);
    }

    private function readCachedToken(): ?string
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT token, authenticated_at, expires_at FROM cartola_auth_token WHERE id = 1');
        $row = $stmt->fetch();

        if (!$row || empty($row['token']) || empty($row['authenticated_at'])) {
            return null;
        }

        if (time() >= $this->computeExpiration($row)) {
            return null;
        }

        return $row['token'];
    }

    private function saveCachedToken(string $token, ?string $expiresAt = null): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE cartola_auth_token SET token = :token, authenticated_at = NOW(), expires_at = :expires_at WHERE id = 1
        ');
        $stmt->execute([':token' => $token, ':expires_at' => $expiresAt]);
    }

    private function clearCachedToken(): void
    {
        $pdo = Connection::get();
        $pdo->exec('UPDATE cartola_auth_token SET token = NULL, authenticated_at = NULL, expires_at = NULL WHERE id = 1');
    }
}
