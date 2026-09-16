<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Liga;
use Cartoleirao\Models\Participante;
use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\EmailService;

$ligas = Liga::todas();
$ligaPadrao = Liga::padrao();

$ligaIdEscolhida = (int) ($_POST['liga_id'] ?? $_GET['liga_id'] ?? ($ligaPadrao['id'] ?? 0));

$liga = null;
foreach ($ligas as $l) {
    if ((int) $l['id'] === $ligaIdEscolhida) {
        $liga = $l;
        break;
    }
}
// fallback: se o id não bateu com nenhuma liga válida, cai pra padrão
if (!$liga) {
    $liga = $ligaPadrao;
}

$erro = null;
$sucesso = false;

if (!$liga) {
    $erro = 'Nenhuma liga configurada no momento.';
}

if ($liga && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::valido($_POST['_csrf'] ?? null)) {
        $erro = 'Sessão expirada, atualize a página e tente novamente.';
    } elseif (!empty($_POST['website'])) {
        // honeypot: campo invisível que só bots preenchem
        $erro = 'Não foi possível processar sua inscrição.';
    } elseif (empty($liga['inscricoes_abertas'])) {
        $erro = 'As inscrições para esta liga estão encerradas no momento.';
    } else {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $whatsapp = preg_replace('/\D/', '', (string) ($_POST['whatsapp'] ?? ''));
        $nomeTimeCartola = trim((string) ($_POST['nome_time_cartola'] ?? ''));

        if (strlen($nome) < 3) {
            $erro = 'Informe seu nome completo.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Informe um e-mail válido.';
        } elseif (strlen($whatsapp) < 10) {
            $erro = 'Informe um WhatsApp válido, com DDD.';
        } else {
            $cartolaTimeId = null;
            $cartolaTimeSlug = null;

            if ($nomeTimeCartola !== '') {
                try {
                    $candidatos = (new CartolaApiClient())->buscarTimesPorNome($nomeTimeCartola);
                    // só vincula automaticamente se houver exatamente 1 resultado
                    // (nomes ambíguos ficam pendentes pro admin resolver na mão)
                    if (count($candidatos) === 1) {
                        $cartolaTimeId = $candidatos[0]['time_id'];
                        $cartolaTimeSlug = $candidatos[0]['slug'];
                    }
                } catch (\Throwable $e) {
                    // API instável não pode travar a inscrição — segue sem vincular
                }
            }

            try {
                $participanteId = Participante::criar([
                    'liga_id' => $liga['id'],
                    'nome' => $nome,
                    'email' => $email,
                    'whatsapp' => $whatsapp,
                    'nome_time_cartola' => $nomeTimeCartola,
                    'cartola_time_id' => $cartolaTimeId,
                    'cartola_time_slug' => $cartolaTimeSlug,
                ]);

                // E-mail de confirmação + instruções de pagamento. Uma falha aqui
                // (ex: servidor sem sendmail configurado) não pode derrubar a
                // inscrição, que já foi salva com sucesso — só loga o erro.
                try {
                    (new EmailService())->enviarInscricaoRealizada(
                        ['id' => $participanteId, 'nome' => $nome, 'email' => $email],
                        $liga
                    );
                } catch (\Throwable $e) {
                    error_log('[inscricao] falha ao enviar e-mail de confirmação: ' . $e->getMessage());
                }

                $sucesso = true;
            } catch (\InvalidArgumentException $e) {
                $erro = $e->getMessage();
            }
        }
    }
}

$tituloPagina = 'Inscrição';
require __DIR__ . '/includes/header.php';
?>

    <div class="container">
        <div class="card card-inscricao">
            <div class="card-header">
                <h3 class="mb-1"><i class="fa-solid fa-futbol me-1"></i> Inscreva-se</h3>
            </div>

            <?php if (count($ligas) > 1): ?>
                <div class="liga-pills-wrap">
                    <?php foreach ($ligas as $l): ?>
                        <a href="inscricao.php?liga_id=<?= (int) $l['id'] ?>"
                           class="liga-pill <?= (int) $liga['id'] === (int) $l['id'] ? 'ativo' : '' ?> <?= empty($l['inscricoes_abertas']) ? 'fechada' : '' ?>">
                            <?= e($l['nome']) ?>
                            <?php if (empty($l['inscricoes_abertas'])): ?>
                                <i class="fa-solid fa-lock small ms-1"></i>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card-body p-4">

                <?php if ($sucesso): ?>
                    <div class="alert alert-success">
                        <strong>Inscrição recebida!</strong> Enviamos um e-mail com as instruções de
                        pagamento pra confirmar sua participação. Não achou? Confere a caixa de spam.
                    </div>
                    <a href="index.php" class="btn btn-cartoleirao w-100">Voltar para o início</a>

                <?php elseif ($erro): ?>
                    <div class="alert alert-danger"><?= e($erro) ?></div>
                    <?php if (!empty($liga['inscricoes_abertas'])): ?>
                        <a href="inscricao.php?liga_id=<?= (int) $liga['id'] ?>" class="btn btn-cartoleirao w-100">Tentar novamente</a>
                    <?php endif; ?>

                <?php elseif (empty($liga['inscricoes_abertas'])): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fa-solid fa-lock"></i> As inscrições para <strong><?= e($liga['nome']) ?></strong>
                        estão encerradas no momento.
                    </div>

                <?php else: ?>
                    <div id="erro-cliente" class="alert alert-danger d-none"></div>

                    <?php if (!empty($liga['valor_inscricao'])): ?>
                        <div class="valor-inscricao-tag">
                            Inscrição: <strong>R$ <?= number_format((float) $liga['valor_inscricao'], 2, ',', '.') ?></strong>
                        </div>
                    <?php endif; ?>

                    <form id="form-inscricao" method="post" novalidate>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="liga_id" value="<?= (int) $liga['id'] ?>">

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
$scriptsExtras = ['assets/js/inscricao.js'];
require __DIR__ . '/includes/footer.php';
