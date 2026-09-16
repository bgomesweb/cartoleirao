<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\League;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Registrant;
use Cartoleirao\Services\SyncService;

$leagues = League::withCartolaLeagueSlug();
$leagueId = (int) ($_GET['liga_id'] ?? ($leagues[0]['id'] ?? 0));

$currentLeague = null;
foreach ($leagues as $l) {
    if ((int) $l['id'] === $leagueId) {
        $currentLeague = $l;
        break;
    }
}

$loginEnabled = (getenv('CARTOLA_OFFICIAL_LEAGUE_ENABLED') ?: (getenv('CARTOLA_LIGA_OFICIAL_HABILITADA') ?: 'false')) === 'true';

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::isValid($_POST['_csrf'] ?? null)) {
    // Main path: paste the JSON copied from DevTools (the already-logged-in
    // browser solved the captcha for being a real human accessing the
    // normal site).
    if (isset($_POST['importar_json']) && $currentLeague) {
        $pastedJson = trim((string) ($_POST['json_colado'] ?? ''));
        $decoded = json_decode($pastedJson, true);

        if ($pastedJson === '' || json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $error = 'JSON inválido — confira se copiou a resposta completa da requisição.';
        } else {
            $result = (new SyncService())->syncOfficialLeagueTeams(
                (int) $currentLeague['id'],
                $currentLeague['cartola_league_slug'],
                $decoded
            );

            if ($result === null || $result['total'] === 0) {
                $error = 'Não encontrei nenhum time reconhecível nesse JSON. Confira o formato — se a API mudou o formato dos campos, me avise pra ajustar o código.';
            } else {
                $message = "Importado: {$result['total']} times, {$result['vinculados']} já vinculados a uma inscrição, {$result['sem_correspondencia']} sem inscrição correspondente.";
                if (!empty($result['rodadas_importadas'])) {
                    $message .= " De bônus, {$result['rodadas_importadas']} rodadas de pontuação já vieram prontas nesse JSON (não precisa rodar sync_scores pra essas).";
                }
            }
        }
    }

    // Alternative path (automatic login) — only works if someone enables it
    // via .env, but we know it hits the captcha. Kept only for documentation.
    if ($loginEnabled && isset($_POST['sincronizar_agora']) && $currentLeague) {
        $result = (new SyncService())->syncOfficialLeagueTeams((int) $currentLeague['id'], $currentLeague['cartola_league_slug'], null, $currentLeague['type'] ?? 'classic');

        if ($result === null) {
            $error = 'Falha ao sincronizar via login — confira o log de sincronização (era esperado falhar por causa do captcha).';
        } else {
            $message = "Sincronizado via login: {$result['total']} times, {$result['vinculados']} vinculados, {$result['sem_correspondencia']} sem correspondência.";
        }
    }
}

$teams = $currentLeague ? OfficialLeagueTeam::byLeague((int) $currentLeague['id']) : [];
$unlinkedRegistrants = $currentLeague ? Registrant::unlinkedByLeague((int) $currentLeague['id']) : [];

$pageTitle = 'Liga Oficial';
require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-link"></i> Times da Liga Oficial</h2>

<?php if (empty($leagues)): ?>
    <div class="alert alert-info">
        Nenhuma liga tem o slug oficial configurado (coluna <code>cartola_league_slug</code> em <code>leagues</code>).
    </div>
<?php else: ?>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="mb-3">
        <form method="get">
            <select name="liga_id" class="form-select w-auto" onchange="this.form.submit()">
                <?php foreach ($leagues as $l): ?>
                    <option value="<?= (int) $l['id'] ?>" <?= $leagueId === (int) $l['id'] ? 'selected' : '' ?>>
                        <?= e($l['name']) ?> (<?= e($l['cartola_league_slug']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="alert alert-info small">
        <i class="fa-solid fa-circle-info"></i>
        Quer que a cron sincronize sozinha, sem colar JSON manualmente toda vez? Cole seu token de
        login em <a href="cartola-login.php">Login Globo</a> — depois disso a cron
        <code>sync_official_league</code> passa a reaproveitar esse token automaticamente.
    </div>

    <div class="card admin-sidebar-card mb-4">
        <div class="card-body">
            <h5 class="card-title"><i class="fa-solid fa-clipboard"></i> Importar times (colar JSON)</h5>
            <p class="text-muted small mb-1">
                Formato confirmado em produção: um objeto com a chave <code>participantes</code>
                (times da liga) e, em ligas de pontos corridos, também <code>confrontos</code>
                (histórico de pontuação por rodada, importado de bônus automaticamente).
            </p>
            <p class="text-muted small">
                O login automático da Globo exige captcha e não dá pra automatizar — mas
                você, logado normalmente no navegador, não passa por isso. Passo a passo:
            </p>
            <ol class="text-muted small">
                <li>Faça login em <a href="https://cartola.globo.com" target="_blank" rel="noopener">cartola.globo.com</a> no seu navegador, normalmente</li>
                <li>Abra sua liga (<?= e($currentLeague['name'] ?? '') ?>) e o DevTools (F12) → aba <strong>Network</strong></li>
                <li>Recarregue a página da liga e ache a chamada pra <code>auth/liga/<?= e($currentLeague['cartola_league_slug'] ?? '') ?></code></li>
                <li>Clique nela → aba "Response" → copie o JSON inteiro</li>
                <li>Cole abaixo e clique em processar</li>
            </ol>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="liga_id" value="<?= $leagueId ?>">
                <textarea name="json_colado" class="form-control mb-2" rows="6"
                          placeholder='{"participantes": {"123": {"time_id": 123, "nome": "Nome do Time", "nome_cartola": "Dono"}}}'></textarea>
                <button type="submit" name="importar_json" value="1" class="btn btn-cartoleirao">
                    <i class="fa-solid fa-upload"></i> Processar JSON colado
                </button>
            </form>
        </div>
    </div>

    <?php if ($loginEnabled): ?>
        <div class="card admin-sidebar-card mb-4">
            <div class="card-body">
                <h5 class="card-title">Sincronização via login (habilitada manualmente)</h5>
                <p class="text-muted small mb-2">
                    Você habilitou <code>CARTOLA_OFFICIAL_LEAGUE_ENABLED=true</code> — mas sabemos
                    que isso falha com <code>captchaBlank</code>. Deixado aqui só pra referência.
                </p>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="liga_id" value="<?= $leagueId ?>">
                    <button type="submit" name="sincronizar_agora" value="1" class="btn btn-outline-secondary btn-sm">
                        Tentar sincronizar via login mesmo assim
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($teams)): ?>
        <div class="alert alert-info small">
            <i class="fa-solid fa-circle-info"></i>
            Times importados. Agora a pontuação de cada rodada entra sozinha via endpoint
            público — rode <code>docker compose exec app php cron/sync_scores.php</code>
            (ou espere o cron automático) pra puxar os pontos.
        </div>
    <?php endif; ?>

    <p class="text-muted small">
        Times marcados como <span class="badge badge-nao-vinculado">sem correspondência</span> estão
        na liga oficial mas ainda não fizeram a inscrição (controle de pagamento) no site — vincule
        manualmente a um participante já inscrito, se quiser cruzar as duas informações.
    </p>

    <div class="table-responsive">
        <table class="table table-hover align-middle bg-white table-admin">
            <thead>
            <tr>
                <th>Time (Cartola)</th>
                <th>Inscrição / pagamento</th>
                <th>Ação</th>
                <th>Sincronizado em</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($teams)): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Nenhum time importado ainda.</td></tr>
            <?php endif; ?>

            <?php foreach ($teams as $t): ?>
                <tr>
                    <td>
                        <?php if (!empty($t['crest_url'])): ?>
                            <img src="<?= e($t['crest_url']) ?>" class="escudo-time me-1" alt="">
                        <?php endif; ?>
                        <?= e($t['team_name']) ?>
                    </td>
                    <td>
                        <?php if ($t['registrant_id']): ?>
                            <?= e($t['registrant_name']) ?> (<?= e($t['registrant_email']) ?>)
                            <?php if ($t['registrant_paid']): ?>
                                <span class="badge badge-pago">pago</span>
                            <?php else: ?>
                                <span class="badge badge-pendente">pendente</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-nao-vinculado">sem correspondência</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$t['registrant_id'] && !empty($unlinkedRegistrants)): ?>
                            <form method="post" action="vincular_time_oficial.php" class="d-flex gap-1">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="time_liga_oficial_id" value="<?= (int) $t['id'] ?>">
                                <input type="hidden" name="liga_id" value="<?= $leagueId ?>">
                                <select name="participante_id" class="form-select form-select-sm" required>
                                    <option value="">Vincular a...</option>
                                    <?php foreach ($unlinkedRegistrants as $r): ?>
                                        <option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?> (<?= e($r['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Vincular</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= (new DateTime($t['synced_at']))->format('d/m H:i') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
