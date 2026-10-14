<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Database\Connection;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\MarketStatus;
use Cartoleirao\Services\CartolaApiClient;

$apiBase = getenv('CARTOLA_API_BASE') ?: 'https://api.cartola.globo.com';
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::isValid($_POST['_csrf'] ?? null) && isset($_POST['testar_api'])) {
    $api = new CartolaApiClient();
    $started = microtime(true);
    try {
        $status = $api->marketStatus();
    } catch (\Throwable $e) {
        $status = null;
    }
    $testResult = [
        'ok' => (bool) $status,
        'ms' => (int) round((microtime(true) - $started) * 1000),
        'round' => $status['rodada_atual'] ?? null,
        'error' => $status ? null : ($api->getLastError() ?? 'Sem resposta da API.'),
    ];
}

$market = MarketStatus::current();

// Last run of each sync type (sync_log), to show whether the crons are alive
$syncs = [];
try {
    $syncs = Connection::get()->query('
        SELECT l.type, l.success, l.message, l.created_at
        FROM sync_log l
        JOIN (SELECT type, MAX(id) AS id FROM sync_log GROUP BY type) m ON m.id = l.id
        ORDER BY l.created_at DESC
    ')->fetchAll();
} catch (\Throwable $e) {
    // table missing/unavailable: just shows the empty state
}

$syncLabels = ['mercado_status' => 'Status do mercado', 'clubes' => 'Clubes', 'jogos' => 'Jogos', 'pontuacao' => 'Pontuação dos times'];

$fromEmail = getenv('EMAIL_FROM_ADDRESS') ?: '— (padrão do servidor)';
$fromName = getenv('EMAIL_FROM_NAME') ?: 'Cartoleirão';
$notifyEmail = getenv('ADMIN_NOTIFY_EMAIL') ?: 'bgomesweb@gmail.com';

$pageTitle = 'Integrações';
$pageSubtitle = 'Cartola FC e e-mails do sistema';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="panel h-100">
            <div class="panel-head">
                <h3><i class="fa-solid fa-futbol"></i> API do Cartola FC</h3>
                <span class="chip chip-green ms-auto">API pública</span>
            </div>
            <div class="panel-body">
                <p class="text-muted small">
                    O sistema usa somente a API pública do Cartola FC (sem login): busca de times por nome,
                    status do mercado, jogos e pontuação das rodadas.
                </p>

                <dl class="row small mb-3">
                    <dt class="col-sm-4 text-muted fw-normal">Endereço</dt>
                    <dd class="col-sm-8"><code><?= e($apiBase) ?></code></dd>
                    <dt class="col-sm-4 text-muted fw-normal">Mercado</dt>
                    <dd class="col-sm-8">
                        <?php if ($market): ?>
                            <?= e($market['label']) ?> · rodada <?= (int) ($market['current_round'] ?? 0) ?>
                            <span class="text-muted">(atualizado em <?= e((new DateTime($market['updated_at']))->format('d/m H:i')) ?>)</span>
                        <?php else: ?>
                            <span class="text-muted">ainda não sincronizado</span>
                        <?php endif; ?>
                    </dd>
                </dl>

                <form method="post">
                    <?= Csrf::field() ?>
                    <button type="submit" name="testar_api" value="1" class="btn btn-brand">
                        <i class="fa-solid fa-satellite-dish"></i> Testar conexão agora
                    </button>
                </form>

                <?php if ($testResult): ?>
                    <?php if ($testResult['ok']): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            <i class="fa-solid fa-circle-check"></i>
                            Conexão ok em <?= (int) $testResult['ms'] ?> ms — rodada atual <?= e((string) $testResult['round']) ?>.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger mt-3 mb-0">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            Falha ao consultar a API: <?= e((string) $testResult['error']) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="panel h-100">
            <div class="panel-head">
                <h3><i class="fa-solid fa-envelope"></i> E-mails</h3>
            </div>
            <div class="panel-body">
                <p class="text-muted small">
                    Valores definidos no <code>config.local.php</code> do servidor. Para alterar, edite esse arquivo.
                </p>
                <dl class="row small mb-0">
                    <dt class="col-sm-4 text-muted fw-normal">Remetente</dt>
                    <dd class="col-sm-8"><?= e($fromName) ?> &lt;<?= e($fromEmail) ?>&gt;</dd>
                    <dt class="col-sm-4 text-muted fw-normal">Aviso de nova inscrição</dt>
                    <dd class="col-sm-8"><?= e($notifyEmail) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Últimas sincronizações</h3></div>
            <?php if (empty($syncs)): ?>
                <div class="empty-state"><i class="fa-regular fa-clock"></i>Nenhuma sincronização registrada ainda. Confira se os cron jobs estão configurados.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Tipo</th><th>Resultado</th><th>Detalhe</th><th>Quando</th></tr></thead>
                        <tbody>
                        <?php foreach ($syncs as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($syncLabels[$row['type']] ?? $row['type']) ?></td>
                                <td>
                                    <?php if ($row['success']): ?>
                                        <span class="chip chip-green">Sucesso</span>
                                    <?php else: ?>
                                        <span class="chip chip-red">Falha</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-sub"><?= e($row['message'] ?? '') ?></td>
                                <td class="nowrap"><?= e((new DateTime($row['created_at']))->format('d/m/Y H:i')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
