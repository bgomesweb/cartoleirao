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
        League::updateCartolaLeagueSlug($leagueId, trim((string) ($_POST['cartola_liga_slug'] ?? '')) ?: null);
        $message = 'Liga atualizada com sucesso.';
    }
}

$leagues = League::all();

require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-trophy"></i> Ligas</h2>

<?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
<?php endif; ?>

<div class="row g-4">
    <?php foreach ($leagues as $league): ?>
        <div class="col-md-6">
            <div class="card admin-sidebar-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <h5 class="card-title text-cartoleirao-navy mb-0"><?= e($league['name']) ?></h5>
                        <?php if ($league['is_default']): ?>
                            <span class="badge badge-laranja">padrão</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mb-4">
                        Tipo: <?= $league['type'] === 'classic' ? 'Liga Clássica' : 'Pontos Corridos' ?>
                    </p>

                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="liga_id" value="<?= (int) $league['id'] ?>">

                        <div class="admin-form-secao">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="abertas-<?= (int) $league['id'] ?>" name="inscricoes_abertas"
                                       <?= $league['registrations_open'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="abertas-<?= (int) $league['id'] ?>">
                                    Inscrições abertas
                                </label>
                            </div>
                        </div>

                        <div class="admin-form-secao">
                            <label class="form-label fw-semibold">Valor da inscrição</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="number" step="0.01" min="0" class="form-control" name="valor_inscricao"
                                       value="<?= e((string) ($league['registration_fee'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="admin-form-secao">
                            <label class="form-label fw-semibold mb-2">
                                <i class="fa-solid fa-trophy text-warning"></i> Premiação (até 8 posições)
                            </label>
                            <div class="row g-2">
                                <?php $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉']; ?>
                                <?php foreach (range(1, 8) as $i): ?>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small text-muted mb-1">
                                            <?= $medals[$i] ?? '🏅' ?> <?= $i ?>º colocado
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">R$</span>
                                            <input type="number" step="0.01" min="0" class="form-control" name="premio_<?= $i ?>"
                                                   value="<?= e((string) ($league["prize_{$i}"] ?? '')) ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Deixe em branco as posições que não têm prêmio.</div>
                        </div>

                        <div class="admin-form-secao">
                            <label class="form-label fw-semibold">Observações extras <span class="text-muted fw-normal">(opcional, aparece embaixo da premiação na home)</span></label>
                            <textarea class="form-control" name="premiacao_texto" rows="2"
                                      placeholder="Ex: premiação especial pro artilheiro da rodada"><?= e($league['prize_description'] ?? '') ?></textarea>
                        </div>

                        <div class="admin-form-secao">
                            <label class="form-label fw-semibold">
                                <?= $league['type'] === 'round_robin' ? 'ID da competição no Cartola' : 'Slug da liga oficial no Cartola' ?>
                                <i class="fa-solid fa-circle-info text-muted" title="A parte final da URL em cartola.globo.com/#!/competicoes/.../SLUG"></i>
                            </label>
                            <input type="text" class="form-control" name="cartola_liga_slug"
                                   placeholder="<?= $league['type'] === 'round_robin' ? 'ex: d5j59k2k58ms73fgkfi0' : 'ex: cartoleirao-2026' ?>"
                                   value="<?= e($league['cartola_league_slug'] ?? '') ?>">
                            <?php if ($league['type'] === 'round_robin'): ?>
                                <div class="form-text">
                                    Copie o trecho depois de <code>competicoes/pontoscorridos/</code> na URL —
                                    ex: em <code>cartola.globo.com/#!/competicoes/pontoscorridos/d5j59k2k58ms73fgkfi0</code>
                                    o valor é <code>d5j59k2k58ms73fgkfi0</code>. Não é o nome da liga, é um ID
                                    opaco mesmo.
                                </div>
                            <?php else: ?>
                                <div class="form-text">
                                    Usado pra importar a lista de times em <code>admin/liga-oficial.php</code>
                                    (colando o JSON copiado do navegador — o login automático não funciona
                                    por causa do captcha da Globo).
                                </div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-cartoleirao w-100">
                            <i class="fa-solid fa-floppy-disk"></i> Salvar
                        </button>
                    </form>

                    <?php if (!empty($league['cartola_league_slug'])): ?>
                        <a href="liga-oficial.php?liga_id=<?= (int) $league['id'] ?>" class="btn btn-outline-navy w-100 mt-2">
                            <i class="fa-solid fa-people-group"></i> Ver times da liga oficial
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
