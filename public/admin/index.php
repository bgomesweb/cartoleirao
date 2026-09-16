<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Models\Liga;
use Cartoleirao\Models\Participante;

$ligas = Liga::todas();

require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-gauge"></i> Dashboard</h2>

<div class="row g-4">
    <?php foreach ($ligas as $liga): ?>
        <?php $contadores = Participante::contadores((int) $liga['id']); ?>
        <div class="col-md-6">
            <div class="card admin-sidebar-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <h5 class="card-title mb-1 text-cartoleirao-navy"><?= e($liga['nome']) ?></h5>
                        <?php if ($liga['inscricoes_abertas']): ?>
                            <span class="badge badge-pago">Inscrições abertas</span>
                        <?php else: ?>
                            <span class="badge badge-pendente">Inscrições encerradas</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mb-3"><?= e($liga['descricao'] ?? '') ?></p>

                    <div class="row text-center g-2">
                        <div class="col-3">
                            <div class="stat-block stat-block-navy">
                                <div class="fs-4 fw-bold text-cartoleirao-navy"><?= (int) $contadores['total'] ?></div>
                                <div class="small text-muted">Inscritos</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="stat-block stat-block-verde">
                                <div class="fs-4 fw-bold text-success"><?= (int) $contadores['pagos'] ?></div>
                                <div class="small text-muted">Pagos</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="stat-block stat-block-vermelho">
                                <div class="fs-4 fw-bold text-danger"><?= (int) $contadores['pendentes'] ?></div>
                                <div class="small text-muted">Pendentes</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="stat-block stat-block-laranja">
                                <div class="fs-4 fw-bold text-cartoleirao-laranja"><?= (int) $contadores['vinculados'] ?></div>
                                <div class="small text-muted">Vinculados</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <a href="inscricoes.php?liga_id=<?= (int) $liga['id'] ?>" class="btn btn-cartoleirao flex-fill">
                            <i class="fa-solid fa-users"></i> Inscrições
                        </a>
                        <?php if (!empty($liga['cartola_liga_slug'])): ?>
                            <a href="liga-oficial.php?liga_id=<?= (int) $liga['id'] ?>" class="btn btn-outline-navy flex-fill">
                                <i class="fa-solid fa-people-group"></i> Liga Oficial
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
