<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Liga;
use Cartoleirao\Models\Participante;
use Cartoleirao\Models\TimeLigaOficial;
use Cartoleirao\Services\SincronizacaoService;

$ligas = Liga::comSlugOficial();
$ligaId = (int) ($_GET['liga_id'] ?? ($ligas[0]['id'] ?? 0));

$ligaAtual = null;
foreach ($ligas as $l) {
    if ((int) $l['id'] === $ligaId) {
        $ligaAtual = $l;
        break;
    }
}

$loginHabilitado = (getenv('CARTOLA_LIGA_OFICIAL_HABILITADA') ?: 'false') === 'true';

$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::valido($_POST['_csrf'] ?? null)) {
    // Caminho principal: colar o JSON copiado do DevTools (browser já logado
    // resolveu o captcha por ser um humano de verdade acessando o site normal).
    if (isset($_POST['importar_json']) && $ligaAtual) {
        $jsonColado = trim((string) ($_POST['json_colado'] ?? ''));
        $decodificado = json_decode($jsonColado, true);

        if ($jsonColado === '' || json_last_error() !== JSON_ERROR_NONE || !is_array($decodificado)) {
            $erro = 'JSON inválido — confira se copiou a resposta completa da requisição.';
        } else {
            $resultado = (new SincronizacaoService())->sincronizarTimesDaLigaOficial(
                (int) $ligaAtual['id'],
                $ligaAtual['cartola_liga_slug'],
                $decodificado
            );

            if ($resultado === null || $resultado['total'] === 0) {
                $erro = 'Não encontrei nenhum time reconhecível nesse JSON. Confira o formato — se a API mudou o formato dos campos, me avise pra ajustar o código.';
            } else {
                $mensagem = "Importado: {$resultado['total']} times, {$resultado['vinculados']} já vinculados a uma inscrição, {$resultado['sem_correspondencia']} sem inscrição correspondente.";
                if (!empty($resultado['rodadas_importadas'])) {
                    $mensagem .= " De bônus, {$resultado['rodadas_importadas']} rodadas de pontuação já vieram prontas nesse JSON (não precisa rodar sync_pontuacao pra essas).";
                }
            }
        }
    }

    // Caminho alternativo (login automático) — só funciona se alguém habilitar
    // via .env, mas sabemos que trava no captcha. Mantido só por documentação.
    if ($loginHabilitado && isset($_POST['sincronizar_agora']) && $ligaAtual) {
        $resultado = (new SincronizacaoService())->sincronizarTimesDaLigaOficial((int) $ligaAtual['id'], $ligaAtual['cartola_liga_slug'], null, $ligaAtual['tipo'] ?? 'classica');

        if ($resultado === null) {
            $erro = 'Falha ao sincronizar via login — confira o log de sincronização (era esperado falhar por causa do captcha).';
        } else {
            $mensagem = "Sincronizado via login: {$resultado['total']} times, {$resultado['vinculados']} vinculados, {$resultado['sem_correspondencia']} sem correspondência.";
        }
    }
}

$times = $ligaAtual ? TimeLigaOficial::porLiga((int) $ligaAtual['id']) : [];
$naoVinculados = $ligaAtual ? Participante::naoVinculadosPorLiga((int) $ligaAtual['id']) : [];

$tituloPagina = 'Liga Oficial';
require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-link"></i> Times da Liga Oficial</h2>

<?php if (empty($ligas)): ?>
    <div class="alert alert-info">
        Nenhuma liga tem o slug oficial configurado (coluna <code>cartola_liga_slug</code> em <code>ligas</code>).
    </div>
<?php else: ?>

    <?php if ($mensagem): ?>
        <div class="alert alert-success"><?= e($mensagem) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="alert alert-danger"><?= e($erro) ?></div>
    <?php endif; ?>

    <div class="mb-3">
        <form method="get">
            <select name="liga_id" class="form-select w-auto" onchange="this.form.submit()">
                <?php foreach ($ligas as $l): ?>
                    <option value="<?= (int) $l['id'] ?>" <?= $ligaId === (int) $l['id'] ? 'selected' : '' ?>>
                        <?= e($l['nome']) ?> (<?= e($l['cartola_liga_slug']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="alert alert-info small">
        <i class="fa-solid fa-circle-info"></i>
        Quer que a cron sincronize sozinha, sem colar JSON manualmente toda vez? Cole seu token de
        login em <a href="cartola-login.php">Login Globo</a> — depois disso a cron
        <code>sync_liga_oficial</code> passa a reaproveitar esse token automaticamente.
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
                <li>Abra sua liga (<?= e($ligaAtual['nome'] ?? '') ?>) e o DevTools (F12) → aba <strong>Network</strong></li>
                <li>Recarregue a página da liga e ache a chamada pra <code>auth/liga/<?= e($ligaAtual['cartola_liga_slug'] ?? '') ?></code></li>
                <li>Clique nela → aba "Response" → copie o JSON inteiro</li>
                <li>Cole abaixo e clique em processar</li>
            </ol>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="liga_id" value="<?= $ligaId ?>">
                <textarea name="json_colado" class="form-control mb-2" rows="6"
                          placeholder='{"participantes": {"123": {"time_id": 123, "nome": "Nome do Time", "nome_cartola": "Dono"}}}'></textarea>
                <button type="submit" name="importar_json" value="1" class="btn btn-cartoleirao">
                    <i class="fa-solid fa-upload"></i> Processar JSON colado
                </button>
            </form>
        </div>
    </div>

    <?php if ($loginHabilitado): ?>
        <div class="card admin-sidebar-card mb-4">
            <div class="card-body">
                <h5 class="card-title">Sincronização via login (habilitada manualmente)</h5>
                <p class="text-muted small mb-2">
                    Você habilitou <code>CARTOLA_LIGA_OFICIAL_HABILITADA=true</code> — mas sabemos
                    que isso falha com <code>captchaBlank</code>. Deixado aqui só pra referência.
                </p>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="liga_id" value="<?= $ligaId ?>">
                    <button type="submit" name="sincronizar_agora" value="1" class="btn btn-outline-secondary btn-sm">
                        Tentar sincronizar via login mesmo assim
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($times)): ?>
        <div class="alert alert-info small">
            <i class="fa-solid fa-circle-info"></i>
            Times importados. Agora a pontuação de cada rodada entra sozinha via endpoint
            público — rode <code>docker compose exec app php cron/sync_pontuacao.php</code>
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
            <?php if (empty($times)): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Nenhum time importado ainda.</td></tr>
            <?php endif; ?>

            <?php foreach ($times as $t): ?>
                <tr>
                    <td>
                        <?php if (!empty($t['escudo_url'])): ?>
                            <img src="<?= e($t['escudo_url']) ?>" class="escudo-time me-1" alt="">
                        <?php endif; ?>
                        <?= e($t['nome_cartola']) ?>
                    </td>
                    <td>
                        <?php if ($t['participante_id']): ?>
                            <?= e($t['participante_nome']) ?> (<?= e($t['participante_email']) ?>)
                            <?php if ($t['participante_pago']): ?>
                                <span class="badge badge-pago">pago</span>
                            <?php else: ?>
                                <span class="badge badge-pendente">pendente</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-nao-vinculado">sem correspondência</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$t['participante_id'] && !empty($naoVinculados)): ?>
                            <form method="post" action="vincular_time_oficial.php" class="d-flex gap-1">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="time_liga_oficial_id" value="<?= (int) $t['id'] ?>">
                                <input type="hidden" name="liga_id" value="<?= $ligaId ?>">
                                <select name="participante_id" class="form-select form-select-sm" required>
                                    <option value="">Vincular a...</option>
                                    <?php foreach ($naoVinculados as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>"><?= e($p['nome']) ?> (<?= e($p['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Vincular</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= (new DateTime($t['sincronizado_em']))->format('d/m H:i') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
