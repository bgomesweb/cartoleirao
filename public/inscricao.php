<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\League;
use Cartoleirao\Models\Registrant;
use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\EmailService;

$leagues = League::all();
$defaultLeague = League::default();

$chosenLeagueId = (int) ($_POST['liga_id'] ?? $_GET['liga_id'] ?? ($defaultLeague['id'] ?? 0));

$league = null;
foreach ($leagues as $l) {
    if ((int) $l['id'] === $chosenLeagueId) {
        $league = $l;
        break;
    }
}
// fallback: if the id didn't match any valid league, fall back to the default one
if (!$league) {
    $league = $defaultLeague;
}

$error = null;
$success = false;

if (!$league) {
    $error = 'Nenhuma liga configurada no momento.';
}

if ($league && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::isValid($_POST['_csrf'] ?? null)) {
        $error = 'Sessão expirada, atualize a página e tente novamente.';
    } elseif (!empty($_POST['website'])) {
        // honeypot: invisible field that only bots fill in
        $error = 'Não foi possível processar sua inscrição.';
    } elseif (empty($league['registrations_open'])) {
        $error = 'As inscrições para esta liga estão encerradas no momento.';
    } else {
        $name = trim((string) ($_POST['nome'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $whatsapp = preg_replace('/\D/', '', (string) ($_POST['whatsapp'] ?? ''));
        $cartolaTeamName = trim((string) ($_POST['nome_time_cartola'] ?? ''));

        if (strlen($name) < 3) {
            $error = 'Informe seu nome completo.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um e-mail válido.';
        } elseif (strlen($whatsapp) < 10) {
            $error = 'Informe um WhatsApp válido, com DDD.';
        } else {
            $cartolaTeamId = null;
            $cartolaTeamSlug = null;

            if ($cartolaTeamName !== '') {
                try {
                    $candidates = (new CartolaApiClient())->searchTeamsByName($cartolaTeamName);
                    // only auto-links if there's exactly 1 result
                    // (ambiguous names stay pending for the admin to resolve manually)
                    if (count($candidates) === 1) {
                        $cartolaTeamId = $candidates[0]['team_id'];
                        $cartolaTeamSlug = $candidates[0]['slug'];
                    }
                } catch (\Throwable $e) {
                    // an unstable API can't block the registration — proceed without linking
                }
            }

            try {
                $registrantId = Registrant::create([
                    'league_id' => $league['id'],
                    'name' => $name,
                    'email' => $email,
                    'whatsapp' => $whatsapp,
                    'cartola_team_name' => $cartolaTeamName,
                    'cartola_team_id' => $cartolaTeamId,
                    'cartola_team_slug' => $cartolaTeamSlug,
                ]);

                // Confirmation e-mail + payment instructions. A failure here
                // (e.g. server without sendmail configured) can't take down
                // the registration, which was already saved successfully —
                // just log the error.
                try {
                    (new EmailService())->sendRegistrationReceived(
                        ['id' => $registrantId, 'name' => $name, 'email' => $email],
                        $league
                    );
                } catch (\Throwable $e) {
                    error_log('[inscricao] falha ao enviar e-mail de confirmação: ' . $e->getMessage());
                }

                $success = true;
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Inscrição';
require __DIR__ . '/includes/header.php';
?>

    <div class="container">
        <div class="card card-inscricao">
            <div class="card-header">
                <h3 class="mb-1"><i class="fa-solid fa-futbol me-1"></i> Inscreva-se</h3>
            </div>

            <?php if (count($leagues) > 1): ?>
                <div class="liga-pills-wrap">
                    <?php foreach ($leagues as $l): ?>
                        <a href="inscricao.php?liga_id=<?= (int) $l['id'] ?>"
                           class="liga-pill <?= (int) $league['id'] === (int) $l['id'] ? 'ativo' : '' ?> <?= empty($l['registrations_open']) ? 'fechada' : '' ?>">
                            <?= e($l['name']) ?>
                            <?php if (empty($l['registrations_open'])): ?>
                                <i class="fa-solid fa-lock small ms-1"></i>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card-body p-4">

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <strong>Inscrição recebida!</strong> Enviamos um e-mail com as instruções de
                        pagamento pra confirmar sua participação. Não achou? Confere a caixa de spam.
                    </div>
                    <a href="index.php" class="btn btn-cartoleirao w-100">Voltar para o início</a>

                <?php elseif ($error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php if (!empty($league['registrations_open'])): ?>
                        <a href="inscricao.php?liga_id=<?= (int) $league['id'] ?>" class="btn btn-cartoleirao w-100">Tentar novamente</a>
                    <?php endif; ?>

                <?php elseif (empty($league['registrations_open'])): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fa-solid fa-lock"></i> As inscrições para <strong><?= e($league['name']) ?></strong>
                        estão encerradas no momento.
                    </div>

                <?php else: ?>
                    <div id="erro-cliente" class="alert alert-danger d-none"></div>

                    <?php if (!empty($league['registration_fee'])): ?>
                        <div class="valor-inscricao-tag">
                            Inscrição: <strong>R$ <?= number_format((float) $league['registration_fee'], 2, ',', '.') ?></strong>
                        </div>
                    <?php endif; ?>

                    <form id="form-inscricao" method="post" novalidate>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="liga_id" value="<?= (int) $league['id'] ?>">

                        <!-- honeypot anti-spam, mantido oculto via CSS -->
                        <div style="position:absolute; left:-9999px;" aria-hidden="true">
                            <label for="website">Deixe em branco</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="mb-3">
                            <label for="nome" class="form-label">Nome completo *</label>
                            <input type="text" class="form-control" id="nome" name="nome" required
                                   value="<?= e($_POST['nome'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">E-mail *</label>
                            <input type="email" class="form-control" id="email" name="email" required
                                   value="<?= e($_POST['email'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label for="whatsapp" class="form-label">WhatsApp (com DDD) *</label>
                            <input type="text" class="form-control" id="whatsapp" name="whatsapp" required
                                   placeholder="(16) 99999-9999"
                                   value="<?= e($_POST['whatsapp'] ?? '') ?>">
                        </div>

                        <div class="mb-4">
                            <label for="nome_time_cartola" class="form-label">Nome do seu time no Cartola FC</label>
                            <input type="text" class="form-control" id="nome_time_cartola" name="nome_time_cartola"
                                   placeholder="Opcional"
                                   value="<?= e($_POST['nome_time_cartola'] ?? '') ?>">
                            <div class="form-text">
                                Se informar, tentamos vincular automaticamente ao seu time real do Cartola
                                pra já começar a pontuar. Se não souber o nome exato, pode deixar em branco
                                e o organizador vincula depois.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-cartoleirao w-100">Confirmar inscrição</button>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>

<?php
$extraScripts = ['assets/js/inscricao.js'];
require __DIR__ . '/includes/footer.php';
