<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Liga;
use Cartoleirao\Models\RodadaBonus;

$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::valido($_POST['_csrf'] ?? null)) {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'criar') {
        $ligaId = (int) ($_POST['liga_id'] ?? 0);
        $rodadasTexto = trim((string) ($_POST['rodadas'] ?? ''));
        $valorTexto = trim((string) ($_POST['valor_premio'] ?? ''));
        $valor = $valorTexto !== '' ? (float) str_replace(',', '.', $valorTexto) : null;

        // Aceita "10,19,26,37" (ou separado por espaço/quebra de linha)
        $numeros = preg_split('/[\s,;]+/', $rodadasTexto, -1, PREG_SPLIT_NO_EMPTY);
        $numeros = array_values(array_unique(array_map('intval', $numeros)));
        $numeros = array_filter($numeros, fn ($n) => $n > 0 && $n <= 60);

        if ($ligaId <= 0 || empty($numeros)) {
            $erro = 'Informe a liga e pelo menos um número de rodada válido.';
        } else {
            RodadaBonus::salvarLote($ligaId, $numeros, $valor);
            $mensagem = 'Rodada(s) bônus cadastrada(s): ' . implode(', ', $numeros) . '.';
        }
    } elseif ($acao === 'atualizar') {
        $id = (int) ($_POST['id'] ?? 0);
        $valorTexto = trim((string) ($_POST['valor_premio'] ?? ''));
        $valor = $valorTexto !== '' ? (float) str_replace(',', '.', $valorTexto) : null;

        if ($id > 0) {
            RodadaBonus::atualizarValor($id, $valor);
            $mensagem = 'Valor atualizado.';
        }
    } elseif ($acao === 'remover') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            RodadaBonus::remover($id);
            $mensagem = 'Rodada bônus removida.';
        }
    }
}

$ligas = Liga::todas();
$rodadasBonus = RodadaBonus::todasComLiga();

$tituloPagina = 'Rodadas Bônus';
require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-1"><i class="fa-solid fa-star"></i> Rodadas Bônus</h2>
<p class="text-muted mb-4">
    Marque rodadas com premiação extra pro time que fechar em 1º lugar
    <strong>naquela rodada</strong>. Um modal dourado avisa a galera quando o
    site abre na rodada bônus, e o campeão da rodada aparece destacado em
    dourado na classificação assim que ela fechar.
</p>

<?php if ($mensagem): ?>
    <div class="alert alert-success"><?= e($mensagem) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger"><?= e($erro) ?></div>
<?php endif; ?>

<div class="card admin-sidebar-card mb-4">
    <div class="card-body">
        <h5 class="card-title text-cartoleirao-navy mb-3">
            <i class="fa-solid fa-plus"></i> Nova rodada bônus
        </h5>
        <form method="post" class="row g-3 align-items-end">
            <?= Csrf::field() ?>
            <input type="hidden" name="acao" value="criar">

            <div class="col-md-4">
                <label class="form-label fw-semibold">Liga</label>
                <select name="liga_id" class="form-select" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($ligas as $liga): ?>
                        <option value="<?= (int) $liga['id'] ?>"><?= e($liga['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Rodada(s)</label>
                <input type="text" class="form-control" name="rodadas" placeholder="Ex: 10, 19, 26, 37" required>
                <div class="form-text">Separe por vírgula pra cadastrar várias de uma vez.</div>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Valor do prêmio</label>
                <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="number" step="0.01" min="0" class="form-control" name="valor_premio" placeholder="0,00">
                </div>
                <div class="form-text">Mesmo valor pra todas as rodadas informadas acima.</div>
            </div>

            <div class="col-md-1">
                <button type="submit" class="btn btn-cartoleirao w-100">
                    <i class="fa-solid fa-floppy-disk"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card admin-sidebar-card">
    <div class="card-body">
        <h5 class="card-title text-cartoleirao-navy mb-3">
            <i class="fa-solid fa-list"></i> Rodadas bônus cadastradas
        </h5>

        <?php if (empty($rodadasBonus)): ?>
            <p class="text-muted mb-0">Nenhuma rodada bônus cadastrada ainda.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-admin align-middle">
                    <thead>
                    <tr>
                        <th>Liga</th>
                        <th>Rodada</th>
                        <th>Valor do prêmio</th>
                        <th style="width: 220px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rodadasBonus as $rb): ?>
                        <tr>
                            <td><?= e($rb['liga_nome']) ?></td>
                            <td><span class="badge-mercado badge-mercado-aberto">Rodada <?= (int) $rb['rodada_numero'] ?></span></td>
                            <td>
                                <form method="post" class="d-flex align-items-center gap-2">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="acao" value="atualizar">
                                    <input type="hidden" name="id" value="<?= (int) $rb['id'] ?>">
                                    <div class="input-group input-group-sm" style="max-width: 160px;">
                                        <span class="input-group-text">R$</span>
                                        <input type="number" step="0.01" min="0" class="form-control" name="valor_premio"
                                               value="<?= e((string) ($rb['valor_premio'] ?? '')) ?>">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-outline-navy" title="Salvar valor">
                                        <i class="fa-solid fa-check"></i>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Remover a rodada <?= (int) $rb['rodada_numero'] ?> como bônus?');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="acao" value="remover">
                                    <input type="hidden" name="id" value="<?= (int) $rb['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fa-solid fa-trash"></i> Remover
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
