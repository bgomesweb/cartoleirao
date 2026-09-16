<?php

declare(strict_types=1);

/**
 * Diagnóstico de slug de liga oficial: usa o token JÁ SALVO (via
 * cartola-login.php / bookmarklet) pra buscar ligas pelo nome e mostrar o
 * slug real que a API do Cartola reconhece — útil quando auth/liga/{slug}
 * está dando HTTP 404 e a suspeita é slug desatualizado/errado.
 *
 * Uso (dentro do container ou via SSH no cPanel):
 *   php scripts/find_league.php "Cartoleirão Glória Eterna"
 */

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\CartolaAuthService;

$searchName = $argv[1] ?? null;

if (!$searchName) {
    echo "Uso: php find_league.php \"nome ou parte do nome da liga\"\n";
    exit(1);
}

$auth = new CartolaAuthService();
$token = $auth->getToken();

if (!$token) {
    echo "Sem token válido salvo: " . ($auth->getLastError() ?? 'motivo desconhecido') . "\n";
    echo "-> Rode o bookmarklet de novo em admin/cartola-bookmarklet.php e tente de novo.\n";
    exit(1);
}

$api = new CartolaApiClient();
$response = $api->searchLeaguesByName($searchName, $token);

if ($response === null) {
    echo "Falha na busca: " . ($api->getLastError() ?? 'sem detalhes') . "\n";
    exit(1);
}

// The response may come as a direct list or nested under a key (e.g. 'ligas')
$leagues = $response['ligas'] ?? $response;

if (!is_array($leagues) || empty($leagues)) {
    echo "Nenhuma liga encontrada pra \"{$searchName}\" com essa conta.\n";
    echo "Resposta bruta da API (pra conferência manual):\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Ligas encontradas:\n\n";
foreach ($leagues as $league) {
    if (!is_array($league)) {
        continue;
    }
    $name = $league['nome'] ?? '(sem nome)';
    $slug = $league['slug'] ?? '(sem slug)';
    $id = $league['liga_id'] ?? ($league['id'] ?? '?');
    echo "- nome: {$name}\n";
    echo "  slug: {$slug}   <- é esse valor que precisa estar em admin/ligas.php\n";
    echo "  id:   {$id}\n\n";
}
