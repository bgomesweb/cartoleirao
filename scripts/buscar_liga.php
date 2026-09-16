<?php

declare(strict_types=1);

/**
 * Diagnóstico de slug de liga oficial: usa o token JÁ SALVO (via
 * cartola-login.php / bookmarklet) pra buscar ligas pelo nome e mostrar o
 * slug real que a API do Cartola reconhece — útil quando auth/liga/{slug}
 * está dando HTTP 404 e a suspeita é slug desatualizado/errado.
 *
 * Uso (dentro do container ou via SSH no cPanel):
 *   php scripts/buscar_liga.php "Cartoleirão Glória Eterna"
 */

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\CartolaAuthService;

$nomeBusca = $argv[1] ?? null;

if (!$nomeBusca) {
    echo "Uso: php buscar_liga.php \"nome ou parte do nome da liga\"\n";
    exit(1);
}

$auth = new CartolaAuthService();
$token = $auth->obterToken();

if (!$token) {
    echo "Sem token válido salvo: " . ($auth->getUltimoErro() ?? 'motivo desconhecido') . "\n";
    echo "-> Rode o bookmarklet de novo em admin/cartola-bookmarklet.php e tente de novo.\n";
    exit(1);
}

$api = new CartolaApiClient();
$resposta = $api->buscarLigasPorNome($nomeBusca, $token);

if ($resposta === null) {
    echo "Falha na busca: " . ($api->getUltimoErro() ?? 'sem detalhes') . "\n";
    exit(1);
}

// A resposta pode vir como lista direta ou dentro de uma chave (ex: 'ligas')
$ligas = $resposta['ligas'] ?? $resposta;

if (!is_array($ligas) || empty($ligas)) {
    echo "Nenhuma liga encontrada pra \"{$nomeBusca}\" com essa conta.\n";
    echo "Resposta bruta da API (pra conferência manual):\n";
    echo json_encode($resposta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Ligas encontradas:\n\n";
foreach ($ligas as $liga) {
    if (!is_array($liga)) {
        continue;
    }
    $nome = $liga['nome'] ?? '(sem nome)';
    $slug = $liga['slug'] ?? '(sem slug)';
    $id = $liga['liga_id'] ?? ($liga['id'] ?? '?');
    echo "- nome: {$nome}\n";
    echo "  slug: {$slug}   <- é esse valor que precisa estar em admin/ligas.php\n";
    echo "  id:   {$id}\n\n";
}
