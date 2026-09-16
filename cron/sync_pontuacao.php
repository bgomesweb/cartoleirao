<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\SincronizacaoService;

$api = new CartolaApiClient();
$servico = new SincronizacaoService($api);

// Sincroniza a pontuação da rodada atual pra TODOS os times cadastrados em
// times_liga_oficial (endpoint público, não depende de participante/inscrição
// nem de login). Pra isso ter dados, alguém precisa ter importado a lista de
// times antes em admin/liga-oficial.php.
try {
    $status = $api->statusMercado();
} catch (\Throwable $e) {
    echo "[sync_pontuacao] falha ao consultar status do mercado: {$e->getMessage()}\n";
    exit(1);
}

if (!$status) {
    echo "[sync_pontuacao] não foi possível consultar o status do mercado\n";
    if ($api->getUltimoErro()) {
        echo "[sync_pontuacao] motivo: {$api->getUltimoErro()}\n";
    }
    exit(1);
}

$numeroRodada = $status['rodada_atual'] ?? null;

if (!$numeroRodada) {
    echo "[sync_pontuacao] não foi possível determinar a rodada atual\n";
    exit(1);
}

// IMPORTANTE: "rodada_atual" do /mercado/status é a rodada com o MERCADO
// ABERTO pra escalação — ou seja, ainda vai acontecer, não tem pontuação real
// ainda. A API pública de pontuação por time, quando consultada pra uma
// rodada que ainda não rolou, não retorna erro — ela devolve a pontuação
// "congelada" da última rodada fechada, e isso gravava dado errado no banco
// (rodada nova aparecendo com os mesmos pontos da rodada anterior). Por isso
// NUNCA sincronizamos a rodada atual, só rodadas passadas (com uma janela de
// segurança de 2 rodadas pra garantir que nada fique pra trás).
$rodadasParaSincronizar = array_unique(array_filter([
    max(1, (int) $numeroRodada - 2),
    max(1, (int) $numeroRodada - 1),
]));

foreach ($rodadasParaSincronizar as $numero) {
    $resultado = $servico->sincronizarPontuacoesDaRodada($numero);
    echo "[sync_pontuacao] rodada {$numero}: {$resultado['sucesso']} times ok, {$resultado['falhas']} falhas\n";
}
