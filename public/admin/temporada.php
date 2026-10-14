<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Season;

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::isValid($_POST['_csrf'] ?? null)) {
        $error = 'Sessão expirada, atualize a página e tente novamente.';
    } else {
        $action = (string) ($_POST['acao'] ?? '');
        $seasonId = (int) ($_POST['temporada_id'] ?? 0);

        try {
            if ($action === 'criar') {
                $year = (int) ($_POST['ano'] ?? 0);
                Season::create($year, (string) ($_POST['prazo'] ?? ''));
                $message = "Temporada {$year} criada. Ative quando for abrir as inscrições.";
            } elseif ($action === 'ativar' && Season::findById($seasonId)) {
                Season::activate($seasonId);
                $message = 'Temporada ativada e inscrições abertas em todas as ligas.';
            } elseif ($action === 'prazo' && Season::findById($seasonId)) {
                Season::setDeadline($seasonId, (string) ($_POST['prazo'] ?? ''));
                $message = 'Prazo de inscrição atualizado.';
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

$seasons = Season::all();
$registrationsOpen = Season::registrationsOpen();

$pageTitle = 'Temporada';
$pageSubtitle = 'Ano em andamento, abertura das inscrições e histórico de pontuações';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></div>
<?php endif; ?>

<div class="panel mb-4">
    <div class="panel-head">
        <h3>Como funciona</h3>
    </div>
    <div class="panel-body text-muted small">
        <ol class="mb-0 ps-3">
            <li><strong>Ativar</strong> uma temporada abre as inscrições de todas as ligas: o site público mostra só a página de inscrição.</li>
            <li>Ao desligar <em>Inscrições abertas</em> em <a href="ligas.php">Ligas e premiações</a> (ou quando o prazo vence), a inscrição some e voltam Início, Jogos e Classificação.</li>
            <li>Durante a temporada, a pontuação de cada time é gravada por rodada e no geral. Nada é apagado ao trocar de ano: o histórico fica em <strong>Ver histórico</strong>.</li>
            <li>Enquanto as inscrições estão abertas, jogos e pontuações não são sincronizados com o Cartola.</li>
        </ol>
    </div>
</div>

<div class="panel mb-4">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Temporada</th>
                <th>Situação</th>
                <th>Inscritos</th>
                <th>Times no ranking</th>
                <th>Rodadas pontuadas</th>
                <th>Prazo das inscrições</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($seasons as $season): ?>
                <?php
                $sid = (int) $season['id'];
                $isActive = (bool) $season['is_active'];
                $summary = Season::summary($sid);
                $deadlineValue = $season['registration_deadline'] ? date('Y-m-d\TH:i', strtotime($season['registration_deadline'])) : '';
                ?>
                <tr>
                    <td class="fw-semibold"><?= (int) $season['year'] ?></td>
                    <td>
                        <?php if ($isActive): ?>
                            <span class="chip chip-green"><i class="fa-solid fa-circle-check"></i> Ativa</span>
                            <?php if ($registrationsOpen): ?>
                                <span class="chip chip-accent">Inscrições abertas</span>
                            <?php else: ?>
                                <span class="chip">Em andamento</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="chip">Encerrada / futura</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $summary['registrants'] ?></td>
                    <td><?= $summary['teams'] ?></td>
                    <td><?= $summary['rounds_scored'] ?></td>
                    <td>
                        <form method="post" class="d-flex gap-2">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="acao" value="prazo">
                            <input type="hidden" name="temporada_id" value="<?= $sid ?>">
                            <input type="datetime-local" name="prazo" class="form-control form-control-sm" value="<?= e($deadlineValue) ?>" style="min-width: 190px;">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Salvar prazo"><i class="fa-solid fa-floppy-disk"></i></button>
                        </form>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="temporada-historico.php?id=<?= $sid ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-clock-rotate-left"></i> Ver histórico
                        </a>
                        <?php if (!$isActive): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Ativar a temporada <?= (int) $season['year'] ?>? O site passa a mostrar só a inscrição e as inscrições de todas as ligas são abertas.');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="acao" value="ativar">
                                <input type="hidden" name="temporada_id" value="<?= $sid ?>">
                                <button type="submit" class="btn btn-sm btn-brand"><i class="fa-solid fa-play"></i> Ativar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Nova temporada</h3></div>
    <div class="panel-body">
        <form method="post" class="row g-3 align-items-end">
            <?= Csrf::field() ?>
            <input type="hidden" name="acao" value="criar">
            <div class="col-sm-3 col-md-2">
                <label class="form-label" for="ano">Ano</label>
                <input type="number" class="form-control" id="ano" name="ano" min="2020" max="2100"
                       value="<?= (int) (($seasons[0]['year'] ?? date('Y')) + 1) ?>" required>
            </div>
            <div class="col-sm-5 col-md-4">
                <label class="form-label" for="prazo-novo">Prazo das inscrições <span class="text-muted fw-normal">(opcional)</span></label>
                <input type="datetime-local" class="form-control" id="prazo-novo" name="prazo">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Criar temporada</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
