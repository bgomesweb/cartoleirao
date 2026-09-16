<?php

/**
 * Diagnóstico do login + liga oficial do Cartola.
 *
 * Uso:
 *   docker compose exec app php scripts/testar_login_cartola.php
 *
 * Usa CARTOLA_LOGIN_EMAIL / CARTOLA_LOGIN_SENHA do .env. Mostra o JSON cru
 * retornado por auth/liga/{slug} pra conferirmos se os nomes de campo que o
 * código espera (time_id, nome_cartola, slug, times) realmente batem —
 * como é API não-oficial, sem essa conferência é tentativa e erro.
 */

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Models\Liga;
use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\CartolaAuthService;

echo "== 1. Credenciais configuradas? ==\n";
$email = getenv('CARTOLA_LOGIN_EMAIL') ?: null;
$senha = getenv('CARTOLA_LOGIN_SENHA') ?: null;

if (!$email || !$senha) {
    echo "FALHOU: CARTOLA_LOGIN_EMAIL / CARTOLA_LOGIN_SENHA não configurados no .env\n";
    exit(1);
}
echo "OK: e-mail configurado ({$email})\n";

echo "\n== 2. Login ==\n";
$api = new CartolaApiClient();
$auth = new CartolaAuthService($api);
$token = $auth->obterToken();

if (!$token) {
    echo "FALHOU: " . ($auth->getUltimoErro() ?? 'motivo desconhecido') . "\n";
    exit(1);
}
echo "OK: token obtido (" . substr($token, 0, 12) . "...)\n";

echo "\n== 3. Ligas com slug oficial configurado ==\n";
$ligas = Liga::comSlugOficial();
if (empty($ligas)) {
    echo "Nenhuma liga com cartola_liga_slug configurado.\n";
    exit(1);
}
foreach ($ligas as $liga) {
    echo "- {$liga['nome']} -> slug: {$liga['cartola_liga_slug']}\n";
}

echo "\n== 4. Consultando auth/liga/{slug} da primeira liga (resposta crua) ==\n";
$slug = $ligas[0]['cartola_liga_slug'];
$resposta = $api->ligaOficial($slug, $token);

if ($resposta === null) {
    echo "FALHOU: " . ($api->getUltimoErro() ?? 'sem detalhes') . "\n";
    exit(1);
}

echo json_encode($resposta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n== 5. Chaves de nível raiz encontradas na resposta ==\n";
echo implode(', ', array_keys($resposta)) . "\n";
echo "\nSe o código não estiver casando os times corretamente, compare esse\n";
echo "JSON com o que SincronizacaoService::extrairTimesDaResposta() espera\n";
echo "(campo 'times' ou 'liga.times', cada item com time_id/nome_cartola/slug).\n";
