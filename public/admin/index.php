<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Models\League;
use Cartoleirao\Models\Registrant;

$leagues = League::all();

$pageTitle = 'Dashboard';
$pageSubtitle = 'Resumo das inscrições por liga';
require __DIR__ . '/includes/header.php';
?>

<?php if (empty($leagues)): ?>
    <div class="panel"><div class="empty-state"><i class="fa-solid fa-trophy"></i>Nenhuma liga cadastrada ainda.</div></div>
<?php endif; ?>

<div class="row g-4">
    <?php foreach ($leagues as $league): ?>
        <?php
        $counters = Registrant::counters((int) $league['id']);
        $total = (int) $counters['total'];
        $paid = (int) $counters['paid'];
        $percent = $total > 0 ? (int) round($paid / $total * 100) : 0;
        $fee = $league['registration_fee'] ?? null;
        ?>
        <div class="col-xl-6">
            <div class="panel h-100">
                <div class="panel-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <div>
                            <h3 class="h5 fw-bold mb-1"><?= e($league['name']) ?></h3>
                            <div class="text-muted small"><?= e($league['description'] ?? '') ?></div>
                        </div>
                        <?php if ($league['registrations_open']): ?>
                            <span class="chip chip-green"><i class="fa-solid fa-circle-check"></i> Inscrições abertas</span>
                        <?php else: ?>
                            <span class="chip chip-red"><i class="fa-solid fa-lock"></i> Encerradas</span>
                        <?php endif; ?>
                    </div>

                    <div class="stat-grid my-4">
                        <div class="stat is-navy"><div class="stat-value"><?= $total ?></div><div class="stat-label">Inscritos</div></div>
                        <div class="stat is-green"><div class="stat-value"><?= $paid ?></div><div class="stat-label">Pagos</div></div>
                        <div class="stat is-red"><div class="stat-value"><?= (int) $counters['pending'] ?></div><div class="stat-label">Pendentes</div></div>
                        <div class="stat is-accent"><div class="stat-value"><?= (int) $counters['linked'] ?></div><div class="stat-label">Vinculados</div></div>
                    </div>

                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Pagamentos confirmados</span>
                        <span><strong class="text-body"><?= $percent ?>%</strong><?php if ($fee !== null && $fee !== ''): ?> · arrecadado R$ <?= number_format($paid * (float) $fee, 2, ',', '.') ?><?php endif; ?></span>
                    </div>
                    <div class="progress-slim"><span style="width: <?= $percent ?>%"></span></div>

                    <div class="d-flex gap-2 mt-4 flex-wrap">
                        <a href="inscricoes.php?liga_id=<?= (int) $league['id'] ?>" class="btn btn-brand">
                            <i class="fa-solid fa-users"></i> Ver inscrições
                        </a>
                        <a href="ligas.php#liga-<?= (int) $league['id'] ?>" class="btn btn-soft">
                            <i class="fa-solid fa-sliders"></i> Configurar liga
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
