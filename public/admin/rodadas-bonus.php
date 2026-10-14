<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\BonusRound;
use Cartoleirao\Models\League;

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::isValid($_POST['_csrf'] ?? null)) {
    $action = (string) ($_POST['acao'] ?? '');

    if ($action === 'criar') {
        $leagueId = (int) ($_POST['liga_id'] ?? 0);
        $roundsText = trim((string) ($_POST['rodadas'] ?? ''));
        $amountText = trim((string) ($_POST['valor_premio'] ?? ''));
        $amount = $amountText !== '' ? (float) str_replace(',', '.', $amountText) : null;

        // Accepts "10,19,26,37" (or separated by space/newline)
        $numbers = preg_split('/[\s,;]+/', $roundsText, -1, PREG_SPLIT_NO_EMPTY);
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $numbers = array_filter($numbers, fn ($n) => $n > 0 && $n <= 60);

        if ($leagueId <= 0 || empty($numbers)) {
            $error = 'Informe a liga e pelo menos um número de rodada válido.';
        } else {
            BonusRound::saveBatch($leagueId, $numbers, $amount);
            $message = 'Rodada(s) bônus cadastrada(s): ' . implode(', ', $numbers) . '.';
        }
    } elseif ($action === 'atualizar') {
        $id = (int) ($_POST['id'] ?? 0);
        $amountText = trim((string) ($_POST['valor_premio'] ?? ''));
        $amount = $amountText !== '' ? (float) str_replace(',', '.', $amountText) : null;

        if ($id > 0) {
            BonusRound::updatePrizeAmount($id, $amount);
            $message = 'Valor atualizado.';
        }
    } elseif ($action === 'remover') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            BonusRound::delete($id);
            $message = 'Rodada bônus removida.';
        }
    }
}

$leagues = League::all();
$bonusRounds = BonusRound::allWithLeague();

$pageTitle = 'Rodadas bônus';
$pageSubtitle = 'Premiação extra para o 1º lugar de rodadas específicas';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    Um modal dourado avisa a galera quando o site abre numa rodada bônus, e o campeão da rodada
    aparece destacado na classificação assim que ela fecha.
</div>

<div class="panel mb-4">
    <div class="panel-head"><h3><i class="fa-solid fa-plus"></i> Nova rodada bônus</h3></div>
    <div class="panel-body">
        <form method="post" class="row g-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="acao" value="criar">

            <div class="col-md-3">
                <label class="form-label">Liga</label>
                <select name="liga_id" class="form-select" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($leagues as $league): ?>
                        <option value="<?= (int) $league['id'] ?>"><?= e($league['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Rodada(s)</label>
                <input type="text" class="form-control" name="rodadas" placeholder="Ex: 10, 19, 26" required>
            </div>

            <div class="col-md-3">
                <label class="form-label">Prêmio</label>
                <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="number" step="0.01" min="0" class="form-control" name="valor_premio" placeholder="0,00">
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label d-none d-md-block">&nbsp;</label>
                <button type="submit" class="btn btn-brand w-100"><i class="fa-solid fa-plus"></i> Adicionar</button>
            </div>

            <div class="col-12 form-text mt-2">Separe as rodadas por vírgula para cadastrar várias de uma vez; o prêmio vale para todas.</div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-list"></i> Rodadas cadastradas</h3></div>

    <?php if (empty($bonusRounds)): ?>
        <div class="empty-state"><i class="fa-regular fa-star"></i>Nenhuma rodada bônus cadastrada ainda.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Liga</th>
                    <th>Rodada</th>
                    <th>Valor do prêmio</th>
                    <th style="width: 120px;"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($bonusRounds as $br): ?>
                    <tr>
                        <td><?= e($br['league_name']) ?></td>
                        <td><span class="chip chip-accent"><i class="fa-solid fa-star"></i> Rodada <?= (int) $br['round_number'] ?></span></td>
                        <td>
                            <form method="post" class="d-flex align-items-center gap-2">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="acao" value="atualizar">
                                <input type="hidden" name="id" value="<?= (int) $br['id'] ?>">
                                <div class="input-group input-group-sm" style="max-width: 170px;">
                                    <span class="input-group-text">R$</span>
                                    <input type="number" step="0.01" min="0" class="form-control" name="valor_premio"
                                           value="<?= e((string) ($br['prize_amount'] ?? '')) ?>">
                                </div>
                                <button type="submit" class="btn btn-sm btn-soft" title="Salvar valor"><i class="fa-solid fa-check"></i></button>
                            </form>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('Remover a rodada <?= (int) $br['round_number'] ?> como bônus?');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="acao" value="remover">
                                <input type="hidden" name="id" value="<?= (int) $br['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger-soft"><i class="fa-solid fa-trash"></i> Remover</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
