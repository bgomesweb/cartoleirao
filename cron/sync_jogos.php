<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\SincronizacaoService;

$api = new CartolaApiClient();
$servico = new SincronizacaoService($api);

$statusMercado = $servico->sincronizarStatusMercado();
echo $statusMercado ? "[sync_jogos] status do mercado atualizado\n" : "[sync_jogos] falha ao atualizar status do mercado: " . ($api->getUltimoErro() ?? '') . "\n";

$totalClubes = $servico->sincronizarClubes();
echo "[sync_jogos] clubes sincronizados: {$totalClubes}\n";
if ($totalClubes === 0 && $api->getUltimoErro()) {
    echo "[sync_jogos] motivo da falha em /clubes: {$api->getUltimoErro()}\n";
}

$rodada = $servico->sincronizarJogosRodadaAtual();

if ($rodada === null) {
    echo "[sync_jogos] falhou ao sincronizar jogos (API indisponível ou rodada não identificada)\n";
    if ($api->getUltimoErro()) {
        echo "[sync_jogos] motivo: {$api->getUltimoErro()}\n";
    }
    exit(1);
}

echo "[sync_jogos] jogos da rodada {$rodada} sincronizados com sucesso\n";
