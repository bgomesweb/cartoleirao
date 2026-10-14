<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Models\League;
use Cartoleirao\Models\Registrant;

$leagues = League::all();
$selectedLeagueId = (int) ($_GET['liga_id'] ?? ($leagues[0]['id'] ?? 0));
$paidFilter = $_GET['pago'] ?? null;

$registrants = $selectedLeagueId ? Registrant::findByLeague($selectedLeagueId, $paidFilter) : [];

require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-clipboard-list"></i> Inscrições</h2>

<form method="get" class="row g-2 mb-4">
    <div class="col-auto">
        <select name="liga_id" class="form-select" onchange="this.form.submit()">
            <?php foreach ($leagues as $league): ?>
                <option value="<?= (int) $league['id'] ?>" <?= $selectedLeagueId === (int) $league['id'] ? 'selected' : '' ?>>
                    <?= e($league['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="pago" class="form-select" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <option value="paid" <?= $paidFilter === 'paid' ? 'selected' : '' ?>>Pagos</option>
            <option value="pending" <?= $paidFilter === 'pending' ? 'selected' : '' ?>>Pendentes</option>
        </select>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white table-admin">
        <thead>
        <tr>
            <th>Nome</th>
            <th>E-mail</th>
            <th>WhatsApp</th>
            <th>Time no Cartola</th>
            <th>Vinculação</th>
            <th>Pagamento</th>
            <th>Inscrito em</th>
            <th>Pago em</th>
            <th>Observações</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($registrants)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma inscrição encontrada.</td></tr>
        <?php endif; ?>

        <?php foreach ($registrants as $r): ?>
            <tr data-id="<?= (int) $r['id'] ?>">
                <td><?= e($r['name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td>
                    <a href="https://wa.me/55<?= e($r['whatsapp']) ?>" target="_blank" rel="noopener">
                        <?= e($r['whatsapp']) ?>
                    </a>
                </td>
                <td><?= e($r['cartola_team_name'] ?? '—') ?></td>
                <td>
                    <?php if ($r['linked_to_api']): ?>
                        <span class="badge badge-vinculado">Vinculado</span>
                    <?php else: ?>
                        <span class="badge badge-nao-vinculado">Não vinculado</span>
                        <?php if (!empty($r['cartola_team_name'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-vincular"
                                    data-id="<?= (int) $r['id'] ?>"
                                    data-nome="<?= e($r['cartola_team_name']) ?>">
                                Vincular
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button"
                            class="btn btn-sm <?= $r['paid'] ? 'btn-success' : 'btn-outline-danger' ?> btn-toggle-pago"
                            data-id="<?= (int) $r['id'] ?>">
                        <?= $r['paid'] ? 'Pago ✓' : 'Pendente' ?>
                    </button>
                </td>
                <td><?= (new DateTime($r['created_at']))->format('d/m/Y H:i') ?></td>
                <td class="paid-at"><?= !empty($r['paid_at']) ? (new DateTime($r['paid_at']))->format('d/m/Y H:i') : '—' ?></td>
                <td>
                    <input type="text" class="form-control form-control-sm input-observacao"
                           maxlength="255" style="min-width:200px"
                           data-id="<?= (int) $r['id'] ?>" data-original="<?= e($r['notes'] ?? '') ?>"
                           value="<?= e($r['notes'] ?? '') ?>">
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal de vinculação manual com a API do Cartola -->
<div class="modal fade" id="modalVincular" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Vincular time do Cartola</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Resultados encontrados na API do Cartola FC para o nome informado:</p>
                <div id="vincular-resultados">
                    <div class="text-center py-3">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
