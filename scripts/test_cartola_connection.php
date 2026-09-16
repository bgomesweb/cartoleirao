<?php

/**
 * Diagnóstico de conectividade com a API do Cartola FC — roda dentro do
 * container e testa, em camadas, onde exatamente a falha está:
 *   1. Resolução de DNS
 *   2. Conexão HTTPS genérica (sem depender da API do Cartola)
 *   3. Chamada real à API do Cartola
 *
 * Uso:
 *   docker compose exec app php scripts/test_cartola_connection.php
 */

echo "== 1. Resolução de DNS ==\n";
$ip = gethostbyname('api.cartola.globo.com');
if ($ip === 'api.cartola.globo.com') {
    echo "FALHOU: não resolveu o DNS de api.cartola.globo.com\n";
    echo "-> Provável causa: o container não tem acesso à internet (rede Docker restrita) ou DNS não configurado.\n";
} else {
    echo "OK: api.cartola.globo.com -> {$ip}\n";
}

echo "\n== 2. Conexão HTTPS genérica (https://www.google.com) ==\n";
$ch = curl_init('https://www.google.com');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true]);
$ok = curl_exec($ch);
$curlError = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($ok === false) {
    echo "FALHOU: {$curlError}\n";
    echo "-> Provável causa: sem saída de internet no container (egress bloqueado) ou problema de certificado SSL/CA.\n";
} else {
    echo "OK: HTTP {$status}\n";
}

echo "\n== 3. Chamada real: https://api.cartola.globo.com/mercado/status ==\n";
$ch = curl_init('https://api.cartola.globo.com/mercado/status');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_USERAGENT => 'Cartoleirao/1.0',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_VERBOSE => false,
]);
$body = curl_exec($ch);
$curlError = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($body === false) {
    echo "FALHOU (curl): {$curlError}\n";
} else {
    echo "HTTP {$status}\n";
    echo "Corpo da resposta (primeiros 500 chars):\n";
    echo substr($body, 0, 500) . "\n";
}

echo "\n== 4. Info do curl (versão, SSL) ==\n";
print_r(curl_version());
