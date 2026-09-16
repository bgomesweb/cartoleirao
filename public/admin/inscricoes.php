<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Models\Liga;
use Cartoleirao\Models\Participante;

$ligas = Liga::todas();
$ligaSelecionadaId = (int) ($_GET['liga_id'] ?? ($ligas[0]['id'] ?? 0));
$filtroPago = $_GET['pago'] ?? null;

$participantes = $ligaSelecionadaId ? Participante::porLiga($ligaSelecionadaId, $filtroPago) : [];

require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-clipboard-list"></i> Inscrições</h2>

<form method="get" class="row g-2 mb-4">
    <div class="col-auto">
        <select name="liga_id" class="form-select" onchange="this.form.submit()">
            <?php foreach ($ligas as $liga): ?>
                <option value="<?= (int) $liga['id'] ?>" <?= $ligaSelecionadaId === (int) $liga['id'] ? 'selected' : '' ?>>
                    <?= e($liga['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="pago" class="form-select" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <option value="pago" <?= $filtroPago === 'pago' ? 'selected' : '' ?>>Pagos</option>
            <option value="pendente" <?= $filtroPago === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
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
        </tr>
        </thead>
        <tbody>
        <?php if (empty($participantes)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma inscrição encontrada.</td></tr>
        <?php endif; ?>

        <?php foreach ($participantes as $p): ?>
            <tr data-id="<?= (int) $p['id'] ?>">
                <td><?= e($p['nome']) ?></td>
                <td><?= e($p['email']) ?></td>
                <td>
                    <a href="https://wa.me/55<?= e($p['whatsapp']) ?>" target="_blank" rel="noopener">
                        <?= e($p['whatsapp']) ?>
                    </a>
                </td>
                <td><?= e($p['nome_time_cartola'] ?? '—') ?></td>
                <td>
                    <?php if ($p['vinculado_api']): ?>
                        <span class="badge badge-vinculado">Vinculado</span>
                    <?php else: ?>
                        <span class="badge badge-nao-vinculado">Não vinculado</span>
                        <?php if (!empty($p['nome_time_cartola'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-vincular"
                                    data-id="<?= (int) $p['id'] ?>"
                                    data-nome="<?= e($p['nome_time_cartola']) ?>">
                                Vincular
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button"
                            class="btn btn-sm <?= $p['pago'] ? 'btn-success' : 'btn-outline-danger' ?> btn-toggle-pago"
                            data-id="<?= (int) $p['id'] ?>">
                        <?= $p['pago'] ? 'Pago ✓' : 'Pendente' ?>
                    </button>
                </td>
                <td><?= (new DateTime($p['created_at']))->format('d/m/Y H:i') ?></td>
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
