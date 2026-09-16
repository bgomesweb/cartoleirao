<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\League;
use Cartoleirao\Services\CartolaAuthService;
use Cartoleirao\Services\SyncService;

$auth = new CartolaAuthService();

$message = null;
$error = null;
$testResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::isValid($_POST['_csrf'] ?? null)) {
    if (isset($_POST['salvar_token'])) {
        $pastedToken = trim((string) ($_POST['token'] ?? ''));

        try {
            $auth->saveManualToken($pastedToken);
            $message = 'Token salvo. A cron [sync_official_league] vai usar esse token nas próximas '
                . 'chamadas (se CARTOLA_OFFICIAL_LEAGUE_ENABLED=true estiver configurado no ambiente).';
        } catch (\InvalidArgumentException $e) {
            $error = 'Cole o token antes de salvar.';
        }
    }

    if (isset($_POST['invalidar_token'])) {
        $auth->invalidateToken();
        $message = 'Token removido. A próxima sincronização vai exigir um novo token colado aqui.';
    }

    if (isset($_POST['testar_agora'])) {
        $leagues = League::withCartolaLeagueSlug();

        if (empty($leagues)) {
            $error = 'Nenhuma liga tem cartola_league_slug configurado — não tem o que testar.';
        } else {
            $service = new SyncService();
            $testResults = [];

            foreach ($leagues as $league) {
                $result = $service->syncOfficialLeagueTeams((int) $league['id'], $league['cartola_league_slug'], null, $league['type'] ?? 'classic');
                $testResults[] = [
                    'liga' => $league['name'],
                    'resultado' => $result,
                    'erro' => $result === null ? ($service->getLastApiError() ?? $auth->getLastError()) : null,
                ];
            }
        }
    }
}

$status = $auth->tokenStatus();
$loginEnabled = (getenv('CARTOLA_OFFICIAL_LEAGUE_ENABLED') ?: (getenv('CARTOLA_LIGA_OFICIAL_HABILITADA') ?: 'false')) === 'true';

$pageTitle = 'Login Globo/Cartola';
require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-right-to-bracket"></i> Login na Globo (Cartola)</h2>

<p class="text-muted">
    O login automático da Globo (email/senha) sempre esbarra em captcha — não dá pra automatizar
    isso de forma legítima. O caminho que funciona: você loga <strong>normalmente no seu navegador</strong>
    (aí é um humano de verdade resolvendo o captcha), pega o token de sessão que a Globo gerou pra
    você, e cola aqui. A partir daí, a cron <code>sync_official_league</code> reaproveita esse token —
    sem precisar logar de novo — até ele expirar.
</p>

<div class="alert alert-info small">
    <i class="fa-solid fa-wand-magic-sparkles"></i> Cansado de copiar do DevTools toda vez que expira?
    Tem um <a href="cartola-bookmarklet.php">bookmarklet</a> que captura o token com um clique, direto
    da aba do cartola.globo.com — sem abrir o DevTools.
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-5 mb-4">
        <div class="card admin-sidebar-card h-100">
            <div class="card-body">
                <h5 class="card-title">Status do token</h5>

                <?php if (!$status['present']): ?>
                    <div class="alert alert-warning small mb-3">
                        <i class="fa-solid fa-triangle-exclamation"></i> Nenhum token salvo ainda.
                    </div>
                <?php elseif ($status['valid']): ?>
                    <div class="alert alert-success small mb-3">
                        <i class="fa-solid fa-circle-check"></i> Token válido — expira em
                        <strong><?= e((string) $status['hours_remaining']) ?>h</strong>
                        (<?= e((string) $status['expires_at']) ?>)<?= $status['real_expiration'] ? '' : ' — estimativa' ?>.
                        <?php if ($status['real_expiration']): ?>
                            <div class="mt-1">Data lida direto do token (claim <code>exp</code>), não é chute.</div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger small mb-3">
                        <i class="fa-solid fa-circle-xmark"></i> Token expirou em
                        <?= e((string) $status['expires_at']) ?>. Cole um novo abaixo.
                    </div>
                <?php endif; ?>

                <?php if ($status['present']): ?>
                    <p class="text-muted small mb-1">Salvo em: <?= e((string) $status['authenticated_at']) ?></p>
                <?php endif; ?>

                <p class="small mb-2">
                    Cron habilitada (<code>CARTOLA_OFFICIAL_LEAGUE_ENABLED</code>):
                    <?php if ($loginEnabled): ?>
                        <span class="badge badge-pago">sim</span>
                    <?php else: ?>
                        <span class="badge badge-pendente">não</span>
                    <?php endif; ?>
                </p>
                <?php if (!$loginEnabled): ?>
                    <p class="text-muted small">
                        Mesmo com o token válido, a cron não roda essa parte enquanto
                        <code>CARTOLA_OFFICIAL_LEAGUE_ENABLED</code> não estiver <code>true</code> no
                        ambiente (variável de ambiente / docker-compose — isso não dá pra mudar por
                        aqui, é configuração de infraestrutura). Depois de colar um token válido, ative
                        essa variável e reinicie a cron.
                    </p>
                <?php endif; ?>

                <?php if ($status['present']): ?>
                    <form method="post" class="mt-3" onsubmit="return confirm('Remover o token salvo?');">
                        <?= Csrf::field() ?>
                        <button type="submit" name="invalidar_token" value="1" class="btn btn-sm btn-outline-danger">
                            <i class="fa-solid fa-trash"></i> Invalidar token
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($status['present'] && $status['valid']): ?>
                    <form method="post" class="mt-2">
                        <?= Csrf::field() ?>
                        <button type="submit" name="testar_agora" value="1" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-vial"></i> Testar agora (sincronizar todas as ligas)
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7 mb-4">
        <div class="card admin-sidebar-card h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="fa-solid fa-clipboard-list"></i> Como pegar o token</h5>
                <ol class="text-muted small">
                    <li>Faça login em <a href="https://cartola.globo.com" target="_blank" rel="noopener">cartola.globo.com</a> no seu navegador, normalmente (resolvendo o captcha se pedir)</li>
                    <li>Com a sessão aberta, aperte F12 (DevTools) → aba <strong>Network</strong></li>
                    <li>Recarregue a página da sua liga e ache uma chamada <strong>GET</strong> (status 200, não a <code>OPTIONS</code>) pra algo em <code>/auth/liga/&lt;slug&gt;</code></li>
                    <li>Clique nela → aba <strong>Headers</strong> → role até "Request Headers" → ache <code>authorization</code></li>
                    <li>Copie só o valor <strong>depois</strong> de "Bearer " (o token em si, um JWT longo começando com <code>eyJ...</code>) e cole no campo abaixo — tudo bem se colar com o "Bearer " junto, o sistema remove sozinho</li>
                </ol>
                <div class="alert alert-warning small">
                    <i class="fa-solid fa-shield-halved"></i> Esse token dá acesso à sua conta Globo.
                    Trate como senha: não compartilhe em prints, chats ou tickets de suporte — cole
                    só aqui, direto do DevTools pro campo abaixo.
                </div>
                <p class="text-muted small">
                    A gente lê a expiração real desse token automaticamente (não é mais uma estimativa
                    de 12h). Quando expirar, é só repetir o processo e colar de novo.
                </p>

                <form method="post">
                    <?= Csrf::field() ?>
                    <label for="token" class="form-label small fw-bold">Token (header <code>authorization</code>, sem o "Bearer ")</label>
                    <textarea id="token" name="token" class="form-control mb-2" rows="3"
                              placeholder="Cole aqui o access_token (JWT)" required></textarea>
                    <button type="submit" name="salvar_token" value="1" class="btn btn-cartoleirao">
                        <i class="fa-solid fa-floppy-disk"></i> Salvar token
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($testResults !== null): ?>
    <div class="card admin-sidebar-card mb-4">
        <div class="card-body">
            <h5 class="card-title">Resultado do teste</h5>
            <ul class="small mb-0">
                <?php foreach ($testResults as $item): ?>
                    <li class="mb-2">
                        <strong><?= e($item['liga']) ?></strong>:
                        <?php if ($item['resultado'] === null): ?>
                            <span class="text-danger">falhou — <?= e((string) ($item['erro'] ?? 'motivo desconhecido')) ?></span>
                        <?php else: ?>
                            <span class="text-success">
                                <?= (int) $item['resultado']['total'] ?> times,
                                <?= (int) $item['resultado']['vinculados'] ?> vinculados,
                                <?= (int) $item['resultado']['sem_correspondencia'] ?> sem correspondência
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<p class="text-muted small">
    Prefere não mexer com DevTools? A página <a href="liga-oficial.php">Liga Oficial</a> também aceita
    colar o JSON completo da resposta direto, sem precisar de token — mas aí é manual a cada
    sincronização, sem a cron rodar sozinha.
</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
