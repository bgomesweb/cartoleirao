<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Models\League;
use Cartoleirao\Models\Registrant;

$leagues = League::all();
$selectedLeagueId = (int) ($_GET['liga_id'] ?? ($leagues[0]['id'] ?? 0));
$paidFilter = $_GET['pago'] ?? null;

$registrants = $selectedLeagueId ? Registrant::findByLeague($selectedLeagueId, $paidFilter) : [];
$counters = $selectedLeagueId ? Registrant::counters($selectedLeagueId) : ['total' => 0, 'paid' => 0, 'pending' => 0, 'linked' => 0];

$fmt = static fn (?string $d): string => $d ? (new DateTime($d))->format('d/m/Y H:i') : '—';
$initial = static fn (string $n): string => mb_strtoupper(mb_substr(trim($n), 0, 1)) ?: '?';
$filterUrl = static fn (?string $f): string => '?liga_id=' . $selectedLeagueId . ($f ? '&pago=' . $f : '');

$pageTitle = 'Inscrições';
$pageSubtitle = 'Gerencie pagamentos, vínculos com o Cartola e observações';

require __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
    <form method="get" class="d-flex gap-2">
        <?php if ($paidFilter): ?><input type="hidden" name="pago" value="<?= e($paidFilter) ?>"><?php endif; ?>
        <select name="liga_id" class="form-select" onchange="this.form.submit()" aria-label="Liga">
            <?php foreach ($leagues as $league): ?>
                <option value="<?= (int) $league['id'] ?>" <?= $selectedLeagueId === (int) $league['id'] ? 'selected' : '' ?>>
                    <?= e($league['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="segmented" role="group" aria-label="Filtro de pagamento">
        <a href="<?= e($filterUrl(null)) ?>" class="<?= !$paidFilter ? 'active' : '' ?>">Todos <?= (int) $counters['total'] ?></a>
        <a href="<?= e($filterUrl('paid')) ?>" class="<?= $paidFilter === 'paid' ? 'active' : '' ?>">Pagos <?= (int) $counters['paid'] ?></a>
        <a href="<?= e($filterUrl('pending')) ?>" class="<?= $paidFilter === 'pending' ? 'active' : '' ?>">Pendentes <?= (int) $counters['pending'] ?></a>
    </div>

    <div class="search ms-auto">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" id="filtro-busca" class="form-control" placeholder="Buscar por nome, e-mail ou time…" autocomplete="off">
    </div>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table" id="tabela-inscricoes">
            <thead>
            <tr>
                <th>Participante</th>
                <th>Contato</th>
                <th>Time no Cartola</th>
                <th>Pagamento</th>
                <th>Datas</th>
                <th>Observações</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($registrants as $r): ?>
                <tr data-id="<?= (int) $r['id'] ?>">
                    <td>
                        <div class="cell-person">
                            <span class="avatar"><?= e($initial($r['name'])) ?></span>
                            <div>
                                <div class="fw-semibold"><?= e($r['name']) ?></div>
                                <div class="cell-sub"><?= e($r['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="nowrap">
                        <a href="https://wa.me/55<?= e(preg_replace('/\D+/', '', $r['whatsapp'])) ?>" target="_blank" rel="noopener">
                            <i class="fa-brands fa-whatsapp text-success"></i> <?= e($r['whatsapp']) ?>
                        </a>
                    </td>
                    <td>
                        <div><?= e($r['cartola_team_name'] ?? '—') ?></div>
                        <div class="mt-1">
                            <?php if ($r['linked_to_api']): ?>
                                <span class="chip chip-blue"><i class="fa-solid fa-link"></i> Vinculado</span>
                            <?php else: ?>
                                <span class="chip">Não vinculado</span>
                                <?php if (!empty($r['cartola_team_name'])): ?>
                                    <button type="button" class="btn btn-sm btn-soft btn-vincular"
                                            data-id="<?= (int) $r['id'] ?>"
                                            data-nome="<?= e($r['cartola_team_name']) ?>">
                                        Vincular
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <button type="button"
                                class="btn-pay <?= $r['paid'] ? 'is-paid' : 'is-pending' ?> btn-toggle-pago"
                                data-id="<?= (int) $r['id'] ?>"
                                title="Clique para alternar o status de pagamento">
                            <?= $r['paid'] ? 'Pago ✓' : 'Pendente' ?>
                        </button>
                    </td>
                    <td class="nowrap">
                        <div class="cell-sub">Inscrito em</div>
                        <div><?= e($fmt($r['created_at'])) ?></div>
                        <div class="cell-sub mt-1">Pago em</div>
                        <div class="paid-at"><?= e($fmt($r['paid_at'] ?? null)) ?></div>
                    </td>
                    <td style="min-width: 260px;">
                        <div class="d-flex gap-2 align-items-center">
                            <input type="text" class="form-control form-control-sm input-observacao"
                                   maxlength="255" placeholder="Adicionar observação…"
                                   data-id="<?= (int) $r['id'] ?>" data-original="<?= e($r['notes'] ?? '') ?>"
                                   value="<?= e($r['notes'] ?? '') ?>">
                            <button type="button" class="btn btn-sm btn-soft btn-cobrar nowrap"
                                    data-id="<?= (int) $r['id'] ?>"
                                    data-nome="<?= e($r['name']) ?>"
                                    title="Enviar e-mail cobrando o pagamento">
                                <i class="fa-regular fa-envelope"></i> Cobrar
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="empty-state" id="vazio-inscricoes" <?= empty($registrants) ? '' : 'hidden' ?>>
        <i class="fa-regular fa-folder-open"></i>
        <span id="vazio-texto"><?= empty($registrants) ? 'Nenhuma inscrição encontrada.' : 'Nenhum resultado para a busca.' ?></span>
    </div>
</div>

<!-- Modal de vinculação manual com a API do Cartola FC -->
<div class="modal fade" id="modalVincular" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
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
