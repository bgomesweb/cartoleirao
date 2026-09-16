<?php

declare(strict_types=1);

/**
 * Puxa a pontuação de TODAS as rodadas já jogadas (da 1 até a última rodada
 * fechada) pra todos os times cadastrados em times_liga_oficial — útil pra
 * preencher o histórico completo de uma vez (o cron normal só sincroniza as
 * 2 últimas rodadas a cada execução, pra não sobrecarregar a API).
 *
 * Uso:
 *   docker compose exec app php scripts/backfill_pontuacao.php
 *   docker compose exec app php scripts/backfill_pontuacao.php 1 15   (rodadas 1 a 15 só)
 *
 * Roda uma vez só (não é um cron), demora um pouco porque bate na API
 * pública uma vez por time por rodada — tem uma pequena pausa entre
 * chamadas pra não sobrecarregar.
 */

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\SincronizacaoService;

$api = new CartolaApiClient();
$servico = new SincronizacaoService($api);

$rodadaInicial = isset($argv[1]) ? (int) $argv[1] : 1;
$rodadaFinal = null;

if (isset($argv[2])) {
    $rodadaFinal = (int) $argv[2];
} else {
    $status = $api->statusMercado();
    if (!$status || !isset($status['rodada_atual'])) {
        fwrite(STDERR, "Não consegui descobrir a rodada atual (" . ($api->getUltimoErro() ?? 'sem detalhes') . "). Informe manualmente: php backfill_pontuacao.php 1 20\n");
        exit(1);
    }
    // nunca inclui a rodada atual (ainda não jogada — ver bugfix no README)
    $rodadaFinal = max(1, (int) $status['rodada_atual'] - 1);
}

if ($rodadaInicial > $rodadaFinal) {
    fwrite(STDERR, "Rodada inicial ({$rodadaInicial}) maior que a final ({$rodadaFinal}), nada a fazer.\n");
    exit(1);
}

echo "[backfill] sincronizando rodadas {$rodadaInicial} até {$rodadaFinal}...\n";

$totalOk = 0;
$totalFalhas = 0;

for ($rodada = $rodadaInicial; $rodada <= $rodadaFinal; $rodada++) {
    $resultado = $servico->sincronizarPontuacoesDaRodada($rodada);
    echo "[backfill] rodada {$rodada}: {$resultado['sucesso']} ok, {$resultado['falhas']} falhas\n";
    $totalOk += $resultado['sucesso'];
    $totalFalhas += $resultado['falhas'];

    // pequena pausa entre rodadas pra não bater na API igual metralhadora
    usleep(300000); // 0.3s
}

echo "[backfill] concluído: {$totalOk} pontuações gravadas, {$totalFalhas} falhas no total.\n";
