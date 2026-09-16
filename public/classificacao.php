<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Models\Liga;
use Cartoleirao\Models\Pontuacao;
use Cartoleirao\Models\Rodada;
use Cartoleirao\Models\RodadaBonus;
use Cartoleirao\Models\TimeLigaOficial;

/**
 * Monta o texto de compartilhamento pro WhatsApp: nome do time no Cartola
 * (fonte da verdade é a liga oficial) + pontuação.
 */
function montarTextoCompartilhar(string $titulo, string $ligaNome, array $ranking, string $campoPontos): string
{
    $linhas = ["🏆 *{$titulo}*", $ligaNome, ''];

    foreach ($ranking as $i => $r) {
        $posicao = $i + 1;
        $prefixo = match ($posicao) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => "{$posicao}º",
        };
        $nomeTime = $r['nome_cartola'] ?: ('Time ' . $r['cartola_time_id']);
        $pontos = number_format((float) ($r[$campoPontos] ?? 0), 2, ',', '.');
        $linhas[] = "{$prefixo} {$nomeTime} — {$pontos} pts";
    }

    $linhas[] = '';
    $linhas[] = '⚽ Cartoleirão';

    return implode("\n", $linhas);
}

/** Compartilhamento no formato "tabela de resultados" (P/V/E/D/PC), pra ligas de pontos corridos. */
function montarTextoCompartilharTabela(string $ligaNome, array $tabela): string
{
    $linhas = ["🏆 *Tabela de Resultados*", $ligaNome, ''];

    foreach ($tabela as $t) {
        $posicao = (int) ($t['pc_posicao'] ?? 0);
        $prefixo = match ($posicao) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => $posicao > 0 ? "{$posicao}º" : '—',
        };
        $nomeTime = $t['nome_cartola'] ?: ('Time ' . $t['cartola_time_id']);
        $pc = number_format((float) ($t['pc_pontos_cartola'] ?? 0), 2, ',', '.');
        $linhas[] = "{$prefixo} {$nomeTime} — {$t['pc_pontos']} pts (V{$t['pc_vitorias']} E{$t['pc_empates']} D{$t['pc_derrotas']}) — {$pc} PC";
    }

    $linhas[] = '';
    $linhas[] = '⚽ Cartoleirão';

    return implode("\n", $linhas);
}

$ligas = Liga::todas();
$ligaId = isset($_GET['liga_id']) ? (int) $_GET['liga_id'] : ($ligas[0]['id'] ?? 0);
$modo = ($_GET['modo'] ?? 'geral') === 'rodada' ? 'rodada' : 'geral';

$ligaAtual = null;
foreach ($ligas as $liga) {
    if ((int) $liga['id'] === $ligaId) {
        $ligaAtual = $liga;
        break;
    }
}

$ehPontosCorridos = $ligaAtual && $ligaAtual['tipo'] === 'pontos_corridos';

$tituloPagina = 'Classificação';
require __DIR__ . '/includes/header.php';

// ==========================================================================
// LIGA DE PONTOS CORRIDOS: layout "Tabela de Resultados" (igual ao Cartola)
// ==========================================================================
if ($ehPontosCorridos):
    $tabela = TimeLigaOficial::porLigaOrdenadoPontosCorridos($ligaId);
    $textoCompartilharTabela = !empty($tabela) ? montarTextoCompartilharTabela($ligaAtual['nome'], $tabela) : null;
    ?>

    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <h1 class="tabela-pc-titulo mb-0">Tabela de Resultados</h1>
            <?php if ($textoCompartilharTabela): ?>
                <button type="button" class="btn btn-whatsapp"
                        data-compartilhar-imagem="#captura-tabela-pc"
                        data-titulo="Tabela de Resultados — <?= e($ligaAtual['nome']) ?>"
                        data-texto="<?= e($textoCompartilharTabela) ?>"
                        data-arquivo="tabela-resultados-<?= (int) $ligaAtual['id'] ?>">
                    <i class="fa-brands fa-whatsapp"></i> Compartilhar tabela
                </button>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <form method="get">
                <select name="liga_id" class="form-select w-auto" onchange="this.form.submit()">
                    <?php foreach ($ligas as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= $ligaId === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if (empty($tabela) || $tabela[0]['pc_posicao'] === null): ?>
            <p class="text-center text-muted">
                Ainda não há tabela importada pra essa liga. O organizador precisa
                importar o JSON da liga oficial em <code>admin/liga-oficial.php</code> primeiro.
            </p>
        <?php else: ?>
            <div id="captura-tabela-pc" class="captura-card">
                <div class="captura-card-header">
                    <span class="captura-card-trofeu">🏆</span>
                    <div>
                        <div class="captura-card-titulo">Tabela de Resultados</div>
                        <div class="captura-card-liga"><?= e($ligaAtual['nome']) ?></div>
                    </div>
                </div>

            <div class="tabela-pc-card">
                <div class="tabela-pc-header-row">
                    <div class="tabela-pc-col-time">TIMES</div>
                    <div class="tabela-pc-col-stat">P</div>
                    <div class="tabela-pc-col-stat">V</div>
                    <div class="tabela-pc-col-stat">E</div>
                    <div class="tabela-pc-col-stat">D</div>
                    <div class="tabela-pc-col-stat">PC</div>
                </div>

                <?php foreach ($tabela as $t): ?>
                    <?php if ($t['pc_posicao'] === null) continue; ?>
                    <?php $variacao = (int) $t['pc_variacao']; ?>
                    <?php $classePodio = ['ouro', 'prata', 'bronze'][((int) $t['pc_posicao']) - 1] ?? ''; ?>
                    <div class="tabela-pc-row <?= $classePodio ? 'tabela-pc-row-' . $classePodio : '' ?>">
                        <div class="tabela-pc-variacao">
                            <?php if ($variacao > 0): ?>
                                <i class="fa-solid fa-caret-up variacao-up"></i><span class="variacao-up small"><?= $variacao ?></span>
                            <?php elseif ($variacao < 0): ?>
                                <i class="fa-solid fa-caret-down variacao-down"></i><span class="variacao-down small"><?= abs($variacao) ?></span>
                            <?php else: ?>
                                <span class="tabela-pc-sem-variacao">▪</span>
                            <?php endif; ?>
                        </div>
                        <div class="tabela-pc-posicao">
                            <span class="tabela-pc-posicao-badge <?= $classePodio ? 'is-' . $classePodio : '' ?>"><?= (int) $t['pc_posicao'] ?>º</span>
                        </div>
                        <div class="tabela-pc-col-time d-flex align-items-center">
                            <?php if (!empty($t['escudo_url'])): ?>
                                <img src="<?= e($t['escudo_url']) ?>" class="tabela-pc-escudo" alt="">
                            <?php endif; ?>
                            <div>
                                <div class="tabela-pc-nome-time">
                                    <?= e($t['nome_cartola'] ?: ('Time ' . $t['cartola_time_id'])) ?>
                                    <?php if (!empty($t['assinante'])): ?>
                                        <span class="pc-badge-pro">PRO</span>
                                    <?php endif; ?>
                                </div>
                                <div class="tabela-pc-dono">
                                    <?= e($t['nome_dono'] ?? '') ?>
                                    <?php if (!empty($t['participante_id'])): ?>
                                        <?php if ($t['participante_pago']): ?>
                                            <span class="badge badge-pago">pago</span>
                                        <?php else: ?>
                                            <span class="badge badge-pendente">pendente</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="tabela-pc-col-stat"><?= (int) $t['pc_pontos'] ?></div>
                        <div class="tabela-pc-col-stat"><?= (int) $t['pc_vitorias'] ?></div>
                        <div class="tabela-pc-col-stat"><?= (int) $t['pc_empates'] ?></div>
                        <div class="tabela-pc-col-stat"><?= (int) $t['pc_derrotas'] ?></div>
                        <div class="tabela-pc-col-stat"><?= number_format((float) $t['pc_pontos_cartola'], 2, ',', '.') ?></div>
                    </div>
                <?php endforeach; ?>

                <div class="tabela-pc-legenda">
                    <strong>P</strong> - Pontos, <strong>V</strong> - Vitórias, <strong>E</strong> - Empates,
                    <strong>D</strong> - Derrotas, <strong>PC</strong> - Pontuação no Cartola
                </div>
            </div>
            </div>
        <?php endif; ?>
    </div>

<?php
// ==========================================================================
// LIGA CLÁSSICA: layout genérico (geral / por rodada)
// ==========================================================================
else:
    $rodadas = Rodada::todasOrdenadas();
    $numerosComDados = $ligaId ? Pontuacao::numerosRodadasComDados($ligaId) : [];

    $numeroRodadaSelecionada = isset($_GET['rodada']) ? (int) $_GET['rodada'] : null;
    if ($numeroRodadaSelecionada) {
        $rodadaSelecionada = Rodada::porNumero($numeroRodadaSelecionada);
    } elseif (!empty($numerosComDados)) {
        // padrão: última rodada que TEM pontuação pra essa liga (evita cair
        // numa rodada futura/atual que já existe na tabela `rodadas` — por
        // causa dos jogos — mas ainda não tem pontuação nenhuma)
        $rodadaSelecionada = Rodada::porNumero($numerosComDados[0]);
    } else {
        $rodadaSelecionada = Rodada::maisRecente();
    }

    $ranking = [];
    $mapaPosicoesAnterior = [];

    if ($ligaId) {
        if ($modo === 'rodada' && $rodadaSelecionada) {
            $ranking = Pontuacao::rankingRodada($ligaId, (int) $rodadaSelecionada['id']);

            $numeroAnterior = null;
            foreach ($numerosComDados as $n) {
                if ($n < (int) $rodadaSelecionada['numero']) {
                    $numeroAnterior = $n;
                    break;
                }
            }
            if ($numeroAnterior) {
                $rodadaAnterior = Rodada::porNumero($numeroAnterior);
                if ($rodadaAnterior) {
                    $mapaPosicoesAnterior = Pontuacao::mapaDePosicoes(Pontuacao::rankingRodada($ligaId, (int) $rodadaAnterior['id']));
                }
            }
        } else {
            $ranking = Pontuacao::rankingGeral($ligaId);

            if (count($numerosComDados) >= 2) {
                $penultima = $numerosComDados[1];
                $mapaPosicoesAnterior = Pontuacao::mapaDePosicoes(Pontuacao::rankingGeralAteRodada($ligaId, $penultima));
            }
        }
    }

    // Rodada bônus: se a rodada selecionada (no modo "por rodada") estiver
    // marcada como bônus pra essa liga, o 1º colocado do ranking daquela
    // rodada é destacado em dourado como "campeão da rodada bônus".
    $rodadaBonusAtual = null;
    if ($ligaId && $modo === 'rodada' && $rodadaSelecionada) {
        $rodadaBonusAtual = RodadaBonus::ehBonus($ligaId, (int) $rodadaSelecionada['numero']);
    }

    $textoCompartilharRodada = null;
    if ($ligaAtual && $rodadaSelecionada) {
        $rankingParaShareRodada = ($modo === 'rodada') ? $ranking : Pontuacao::rankingRodada($ligaId, (int) $rodadaSelecionada['id']);
        if (!empty($rankingParaShareRodada)) {
            $textoCompartilharRodada = montarTextoCompartilhar(
                "Classificação da Rodada {$rodadaSelecionada['numero']}",
                $ligaAtual['nome'],
                $rankingParaShareRodada,
                'pontos'
            );
        }
    }

    $textoCompartilharGeral = null;
    if ($ligaAtual) {
        $rankingParaShareGeral = ($modo === 'geral') ? $ranking : Pontuacao::rankingGeral($ligaId);
        if (!empty($rankingParaShareGeral)) {
            $textoCompartilharGeral = montarTextoCompartilhar(
                'Classificação Geral',
                $ligaAtual['nome'],
                $rankingParaShareGeral,
                'pontos_total'
            );
        }
    }
    ?>

    <div class="container py-5">
        <h2 class="page-title justify-content-center mb-4"><i class="fa-solid fa-ranking-star"></i> Classificação</h2>

        <form method="get" class="row g-2 justify-content-center mb-3">
            <div class="col-auto">
                <select name="liga_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($ligas as $liga): ?>
                        <option value="<?= (int) $liga['id'] ?>" <?= $ligaId === (int) $liga['id'] ? 'selected' : '' ?>>
                            <?= e($liga['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="modo" class="form-select" onchange="this.form.submit()">
                    <option value="geral" <?= $modo === 'geral' ? 'selected' : '' ?>>Geral (acumulado)</option>
                    <option value="rodada" <?= $modo === 'rodada' ? 'selected' : '' ?>>Por rodada</option>
                </select>
            </div>
            <?php if ($modo === 'rodada' && !empty($rodadas)): ?>
                <div class="col-auto">
                    <select name="rodada" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($rodadas as $r): ?>
                            <option value="<?= (int) $r['numero'] ?>" <?= $rodadaSelecionada && (int) $rodadaSelecionada['numero'] === (int) $r['numero'] ? 'selected' : '' ?>>
                                Rodada <?= (int) $r['numero'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </form>

        <?php
        // O botão de compartilhar sempre captura a tabela que está de fato
        // visível na tela (modo "geral" ou "rodada"), então usa o texto
        // correspondente ao modo atual.
        $textoCompartilharAtual = $modo === 'rodada' ? $textoCompartilharRodada : $textoCompartilharGeral;
        $tituloCompartilharAtual = $modo === 'rodada' && $rodadaSelecionada
            ? "Classificação da Rodada {$rodadaSelecionada['numero']} — {$ligaAtual['nome']}"
            : "Classificação Geral — " . ($ligaAtual['nome'] ?? '');
        $labelCompartilharAtual = $modo === 'rodada' && $rodadaSelecionada
            ? 'Compartilhar rodada ' . (int) $rodadaSelecionada['numero']
            : 'Compartilhar geral';
        ?>
        <div class="d-flex justify-content-center gap-2 flex-wrap mb-4">
            <?php if ($textoCompartilharAtual): ?>
                <button type="button" class="btn btn-whatsapp btn-sm"
                        data-compartilhar-imagem="#captura-classificacao"
                        data-titulo="<?= e($tituloCompartilharAtual) ?>"
                        data-texto="<?= e($textoCompartilharAtual) ?>"
                        data-arquivo="classificacao-<?= (int) $ligaId ?>-<?= $modo ?>">
                    <i class="fa-brands fa-whatsapp"></i> <?= e($labelCompartilharAtual) ?>
                </button>
            <?php endif; ?>
        </div>

        <?php if ($rodadaBonusAtual): ?>
            <div class="faixa-rodada-bonus mb-4">
                <span class="faixa-rodada-bonus-trofeu">🏆</span>
                <div>
                    <div class="faixa-rodada-bonus-titulo">Rodada <?= (int) $rodadaBonusAtual['rodada_numero'] ?> é Bônus!</div>
                    <div class="faixa-rodada-bonus-sub">
                        <?php if (!empty($rodadaBonusAtual['valor_premio'])): ?>
                            O 1º colocado dessa rodada leva mais
                            <strong>R$ <?= number_format((float) $rodadaBonusAtual['valor_premio'], 2, ',', '.') ?></strong>
                        <?php else: ?>
                            O 1º colocado dessa rodada leva um prêmio extra
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($ranking) && Pontuacao::numerosRodadasComDados($ligaId) === []): ?>
            <p class="text-center text-muted">
                Ainda não há times/pontuação cadastrados pra essa liga.
                <?php if (!empty($ligaAtual['cartola_liga_slug'])): ?>
                    O organizador precisa importar a lista de times em <code>admin/liga-oficial.php</code> primeiro.
                <?php endif; ?>
            </p>
        <?php elseif ($modo === 'rodada' && empty($rodadas)): ?>
            <div class="alert alert-info text-center">Ainda não há rodadas sincronizadas.</div>
        <?php elseif (empty($ranking)): ?>
            <p class="text-center text-muted">Ainda não há pontuações registradas para essa rodada.</p>
        <?php else: ?>
            <div id="captura-classificacao" class="captura-card">
                <div class="captura-card-header">
                    <span class="captura-card-trofeu">🏆</span>
                    <div>
                        <div class="captura-card-titulo">
                            <?= $modo === 'rodada' && $rodadaSelecionada ? 'Classificação da Rodada ' . (int) $rodadaSelecionada['numero'] : 'Classificação Geral' ?>
                        </div>
                        <div class="captura-card-liga"><?= e($ligaAtual['nome'] ?? '') ?></div>
                    </div>
                </div>

            <div class="ranking-flex-card">
                <div class="ranking-flex-header-row">
                    <div class="ranking-flex-variacao">Var.</div>
                    <div class="ranking-flex-pos">#</div>
                    <div class="ranking-flex-time">Time no Cartola</div>
                    <div class="ranking-flex-pontos"><?= $modo === 'rodada' ? 'Pontos na rodada' : 'Pontos (total)' ?></div>
                    <?php if ($modo === 'geral'): ?>
                        <div class="ranking-flex-rodadas">Rodadas</div>
                    <?php endif; ?>
                </div>

                <?php foreach ($ranking as $i => $r): ?>
                    <?php
                    $posicaoAtual = $i + 1;
                    $variacao = Pontuacao::variacaoPosicao($mapaPosicoesAnterior, (int) $r['time_liga_oficial_id'], $posicaoAtual);
                    $ehCampeaoRodadaBonus = $rodadaBonusAtual && $posicaoAtual === 1;
                    $classeTop = $posicaoAtual <= 8 ? 'top-' . $posicaoAtual : '';
                    ?>
                    <div class="ranking-flex-row <?= $classeTop ?> <?= $ehCampeaoRodadaBonus ? 'linha-campeao-bonus' : '' ?>">
                        <div class="ranking-flex-variacao">
                            <?php if ($variacao === null): ?>
                                <span class="variacao-same" title="Sem dado anterior pra comparar">novo</span>
                            <?php elseif ($variacao > 0): ?>
                                <i class="fa-solid fa-caret-up variacao-up"></i><span class="variacao-up small"><?= $variacao ?></span>
                            <?php elseif ($variacao < 0): ?>
                                <i class="fa-solid fa-caret-down variacao-down"></i><span class="variacao-down small"><?= abs($variacao) ?></span>
                            <?php else: ?>
                                <span class="tabela-pc-sem-variacao">▪</span>
                            <?php endif; ?>
                        </div>
                        <div class="ranking-flex-pos">
                            <span class="ranking-posicao-badge <?= $classeTop ? 'is-' . $classeTop : '' ?>"><?= $posicaoAtual ?></span>
                        </div>
                        <div class="ranking-flex-time d-flex align-items-center">
                            <?php if (!empty($r['escudo_url'])): ?>
                                <img src="<?= e($r['escudo_url']) ?>" class="ranking-flex-escudo" alt="">
                            <?php endif; ?>
                            <div>
                                <div class="ranking-flex-nome-time">
                                    <?= e($r['nome_cartola'] ?: ('Time ' . $r['cartola_time_id'])) ?>
                                    <?php if ($ehCampeaoRodadaBonus): ?>
                                        <span class="selo-campeao-bonus"><i class="fa-solid fa-trophy"></i> Campeão da Rodada Bônus</span>
                                    <?php endif; ?>
                                </div>
                                <div class="ranking-flex-sub">
                                    <?php if (!empty($r['participante_nome'])): ?>
                                        <?= e($r['participante_nome']) ?>
                                        <?php if ($r['pago']): ?>
                                            <span class="badge badge-pago">pago</span>
                                        <?php else: ?>
                                            <span class="badge badge-pendente">pendente</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span>— sem inscrição —</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="ranking-flex-pontos">
                            <?= number_format((float) ($r['pontos'] ?? $r['pontos_total']), 2, ',', '.') ?>
                        </div>
                        <?php if ($modo === 'geral'): ?>
                            <div class="ranking-flex-rodadas"><?= (int) $r['rodadas_pontuadas'] ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
