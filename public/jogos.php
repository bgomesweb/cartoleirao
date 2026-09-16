<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Models\Jogo;
use Cartoleirao\Models\Rodada;

$rodadas = Rodada::todasOrdenadas();
$numeroSelecionado = isset($_GET['rodada']) ? (int) $_GET['rodada'] : null;

$rodadaSelecionada = null;
if ($numeroSelecionado) {
    $rodadaSelecionada = Rodada::porNumero($numeroSelecionado);
}
if (!$rodadaSelecionada) {
    $rodadaSelecionada = Rodada::maisRecente();
}

$jogos = $rodadaSelecionada ? Jogo::porRodada((int) $rodadaSelecionada['id']) : [];

/**
 * Monta o texto de compartilhamento dos jogos da rodada pro WhatsApp.
 */
function montarTextoJogos(array $rodada, array $jogos): string
{
    $linhas = ["⚽ *Jogos da Rodada {$rodada['numero']}* — Cartoleirão", ''];

    foreach ($jogos as $jogo) {
        $casa = $jogo['casa_abreviacao'] ?: $jogo['casa_nome'];
        $visitante = $jogo['visitante_abreviacao'] ?: $jogo['visitante_nome'];

        if ($jogo['placar_casa'] !== null && $jogo['placar_visitante'] !== null) {
            $confronto = "{$casa} {$jogo['placar_casa']} x {$jogo['placar_visitante']} {$visitante}";
        } else {
            $confronto = "{$casa} x {$visitante}";
        }

        if (!empty($jogo['partida_data'])) {
            $confronto .= ' (' . (new DateTime($jogo['partida_data']))->format('d/m H:i') . ')';
        }

        $linhas[] = $confronto;
    }

    return implode("\n", $linhas);
}

$textoCompartilharJogos = ($rodadaSelecionada && !empty($jogos)) ? montarTextoJogos($rodadaSelecionada, $jogos) : null;

$tituloPagina = 'Jogos';
require __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <h2 class="page-title justify-content-center mb-4"><i class="fa-solid fa-futbol"></i> Jogos do Brasileirão</h2>

    <?php if (empty($rodadas)): ?>
        <div class="alert alert-info text-center">
            Ainda não sincronizamos nenhuma rodada com a API do Cartola. Isso acontece
            automaticamente a cada 10 minutos, ou pode ser forçado rodando
            <code>cron/sync_jogos.php</code>.
        </div>
    <?php else: ?>
        <form method="get" class="d-flex justify-content-center mb-4">
            <select name="rodada" class="form-select w-auto" onchange="this.form.submit()">
                <?php foreach ($rodadas as $r): ?>
                    <option value="<?= (int) $r['numero'] ?>" <?= (int) $rodadaSelecionada['numero'] === (int) $r['numero'] ? 'selected' : '' ?>>
                        Rodada <?= (int) $r['numero'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($textoCompartilharJogos): ?>
            <div class="text-center mb-4">
                <a class="btn btn-whatsapp btn-sm" target="_blank" rel="noopener"
                   href="https://wa.me/?text=<?= rawurlencode($textoCompartilharJogos) ?>">
                    <i class="fa-brands fa-whatsapp"></i> Compartilhar rodada <?= (int) $rodadaSelecionada['numero'] ?>
                </a>
            </div>
        <?php endif; ?>

        <?php if (empty($jogos)): ?>
            <p class="text-center text-muted">Nenhum jogo encontrado para essa rodada.</p>
        <?php else: ?>
            <div class="row g-3 justify-content-center">
                <?php foreach ($jogos as $jogo): ?>
                    <div class="col-md-6 col-lg-5">
                        <div class="card jogo-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-center flex-fill">
                                        <?php if (!empty($jogo['casa_escudo'])): ?>
                                            <img src="<?= e($jogo['casa_escudo']) ?>" class="escudo-time mb-1" alt="">
                                        <?php endif; ?>
                                        <div class="small fw-semibold"><?= e($jogo['casa_abreviacao'] ?? $jogo['casa_nome']) ?></div>
                                    </div>

                                    <div class="px-3 text-center">
                                        <?php if ($jogo['placar_casa'] !== null && $jogo['placar_visitante'] !== null): ?>
                                            <div class="fs-4 fw-bold"><?= (int) $jogo['placar_casa'] ?> x <?= (int) $jogo['placar_visitante'] ?></div>
                                        <?php else: ?>
                                            <div class="fs-5 fw-bold text-muted">x</div>
                                        <?php endif; ?>
                                        <?php if (!empty($jogo['partida_data'])): ?>
                                            <div class="small text-muted"><?= (new DateTime($jogo['partida_data']))->format('d/m H:i') ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="text-center flex-fill">
                                        <?php if (!empty($jogo['visitante_escudo'])): ?>
                                            <img src="<?= e($jogo['visitante_escudo']) ?>" class="escudo-time mb-1" alt="">
                                        <?php endif; ?>
                                        <div class="small fw-semibold"><?= e($jogo['visitante_abreviacao'] ?? $jogo['visitante_nome']) ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($jogo['local'])): ?>
                                    <div class="text-center small text-muted mt-2"><?= e($jogo['local']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
