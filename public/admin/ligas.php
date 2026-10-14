<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\League;

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::isValid($_POST['_csrf'] ?? null)) {
    $leagueId = (int) ($_POST['liga_id'] ?? 0);

    if ($leagueId > 0) {
        $settingsData = [
            'registrations_open' => isset($_POST['inscricoes_abertas']) ? 1 : 0,
            'registration_fee' => trim((string) ($_POST['valor_inscricao'] ?? '')),
            'prize_description' => trim((string) ($_POST['premiacao_texto'] ?? '')),
        ];
        foreach (range(1, 8) as $i) {
            $settingsData["prize_{$i}"] = trim((string) ($_POST["premio_{$i}"] ?? ''));
        }
        League::updateSettings($leagueId, $settingsData);
        $message = 'Liga atualizada com sucesso.';
    }
}

$leagues = League::all();

$pageTitle = 'Ligas e premiações';
$pageSubtitle = 'Inscrições, valores e premiação de cada liga';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($message) ?></div>
<?php endif; ?>

<div class="row g-4">
    <?php foreach ($leagues as $league): ?>
        <?php $lid = (int) $league['id']; $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉']; ?>
        <div class="col-xl-6" id="liga-<?= $lid ?>">
            <form method="post" class="panel h-100">
                <?= Csrf::field() ?>
                <input type="hidden" name="liga_id" value="<?= $lid ?>">

                <div class="panel-head">
                    <h3><?= e($league['name']) ?></h3>
                    <?php if ($league['is_default']): ?><span class="chip chip-accent">padrão</span><?php endif; ?>
                    <span class="chip ms-auto"><?= $league['type'] === 'classic' ? 'Liga Clássica' : 'Pontos Corridos' ?></span>
                </div>

                <div class="panel-body">
                    <div class="form-section d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <div class="fw-semibold">Inscrições abertas</div>
                            <div class="text-muted small">Ligado, o site público mostra só a inscrição. Desligado (em todas as ligas), a inscrição some e voltam Início, Jogos e Classificação.</div>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="inscricoes_abertas"
                                   id="abertas-<?= $lid ?>" <?= $league['registrations_open'] ? 'checked' : '' ?>>
                        </div>
                    </div>

                    <div class="form-section">
                        <label class="form-label" for="valor-<?= $lid ?>">Valor da inscrição</label>
                        <div class="input-group" style="max-width: 220px;">
                            <span class="input-group-text">R$</span>
                            <input type="number" step="0.01" min="0" class="form-control" id="valor-<?= $lid ?>" name="valor_inscricao"
                                   value="<?= e((string) ($league['registration_fee'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="form-section">
                        <label class="form-label"><i class="fa-solid fa-trophy text-warning"></i> Premiação <span class="text-muted fw-normal">(até 8 posições)</span></label>
                        <div class="row g-2">
                            <?php foreach (range(1, 8) as $i): ?>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small text-muted mb-1"><?= $medals[$i] ?? '🏅' ?> <?= $i ?>º lugar</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" step="0.01" min="0" class="form-control" name="premio_<?= $i ?>"
                                               value="<?= e((string) ($league["prize_{$i}"] ?? '')) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Deixe em branco as posições sem prêmio.</div>
                    </div>

                    <div class="form-section">
                        <label class="form-label">Observações extras <span class="text-muted fw-normal">(opcional — aparece embaixo da premiação na home)</span></label>
                        <textarea class="form-control" name="premiacao_texto" rows="2"
                                  placeholder="Ex: premiação especial pro artilheiro da rodada"><?= e($league['prize_description'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-brand w-100">
                        <i class="fa-solid fa-floppy-disk"></i> Salvar alterações
                    </button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
