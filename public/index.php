<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Models\Liga;
use Cartoleirao\Models\Pontuacao;

/**
 * Reconhece linhas no padrão "1º Campeão: R$950,00" dentro do texto livre de
 * premiação, pra quem já tinha digitado a premiação assim antes dos campos
 * estruturados existirem. Retorna [posicao => ['label' => ..., 'valor' => ...]].
 */
function parsePremiacaoTexto(string $texto): array
{
    $resultado = [];
    foreach (preg_split('/\r\n|\r|\n/', $texto) as $linha) {
        if (preg_match('/^\s*(\d{1,2})\s*[ºo]\s*(.*?)\s*:\s*R\$\s*([\d.,]+)/ui', $linha, $m)) {
            $posicao = (int) $m[1];
            $valor = (float) str_replace(',', '.', str_replace('.', '', $m[3]));
            if ($posicao >= 1 && $posicao <= 20 && $valor > 0) {
                $resultado[$posicao] = ['label' => trim($m[2]) ?: "{$posicao}º Colocado", 'valor' => $valor];
            }
        }
    }
    ksort($resultado);

    return $resultado;
}

/**
 * Monta o texto que acompanha a imagem dos destaques no compartilhamento.
 */
function montarTextoDestaques(string $ligaNome, int $rodadaNumero, array $destaques): string
{
    $linhas = ["🏆 *Destaques da Rodada {$rodadaNumero}*", $ligaNome, ''];

    foreach ($destaques as $i => $d) {
        $posicao = $i + 1;
        $prefixo = match ($posicao) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => "{$posicao}º",
        };
        $nomeTime = $d['nome_cartola'] ?: ('Time ' . $d['cartola_time_id']);
        $pontos = number_format((float) $d['pontos'], 2, ',', '.');
        $linhas[] = "{$prefixo} {$nomeTime} — {$pontos} pts";
    }

    $linhas[] = '';
    $linhas[] = '⚽ Cartoleirão';

    return implode("\n", $linhas);
}

$ligas = [];
$ligaClassica = null;
$destaques = [];
$rodadaDestaques = null;
$erroBanco = null;
$textoCompartilharDestaques = null;

try {
    $ligas = Liga::todas();
    foreach ($ligas as $liga) {
        if ((int) $liga['eh_padrao'] === 1) {
            $ligaClassica = $liga;
            break;
        }
    }

    if ($ligaClassica) {
        $rodadaDestaques = Pontuacao::ultimaRodadaComDados((int) $ligaClassica['id']);
        if ($rodadaDestaques) {
            $destaques = Pontuacao::destaquesRodada((int) $ligaClassica['id'], (int) $rodadaDestaques['id'], 8);
            if (!empty($destaques)) {
                $textoCompartilharDestaques = montarTextoDestaques(
                    $ligaClassica['nome'],
                    (int) $rodadaDestaques['numero'],
                    $destaques
                );
            }
        }
    }
} catch (\Throwable $e) {
    $erroBanco = 'Não foi possível carregar os dados agora. Tente novamente em instantes.';
}

$tituloPagina = 'Início';
require __DIR__ . '/includes/header.php';
?>

<section class="hero-cartoleirao">
    <div class="container">
    <?php if ($erroBanco): ?>
        <div class="alert alert-warning"><?= e($erroBanco) ?></div>
    <?php endif; ?>
    </div>
    <div class="container d-flex flex-column flex-lg-row align-items-center gap-4">
        <div class="flex-grow-1">
            <h1 class="mb-3">Cartola, futebol e resenha ⚽🍻</h1>
            <p class="subtitulo mb-4">
                O Cartoleirão reúne a galera pra brigar ponto a ponto no Cartola FC
                durante todo o Brasileirão Série A — com ranking em tempo real,
                zoeira garantida no grupo e prêmio pra quem terminar no topo.
            </p>
            <a href="inscricao.php" class="btn btn-lg btn-cartoleirao">Quero me inscrever</a>
            <a href="classificacao.php" class="btn btn-lg btn-outline-light ms-2">Ver classificação</a>
        </div>
        <div class="text-center">
            <svg class="hero-ball" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                <circle cx="100" cy="100" r="95" fill="#ffffff"/>
                <g fill="#00165a">
                    <polygon points="100,55 122,71 113,97 87,97 78,71"/>
                    <polygon points="100,55 78,71 60,55 70,30 130,30 140,55 122,71"/>
                    <polygon points="87,97 113,97 121,122 100,140 79,122"/>
                    <polygon points="60,55 40,80 47,110 78,71"/>
                    <polygon points="140,55 160,80 153,110 122,71"/>
                    <polygon points="47,110 79,122 70,150 40,140"/>
                    <polygon points="153,110 121,122 130,150 160,140"/>
                </g>
                <circle cx="100" cy="100" r="95" fill="none" stroke="#00165a" stroke-width="4"/>
            </svg>
        </div>
    </div>
</section>

<div class="container py-5">
    <h2 class="page-title-home justify-content-center mb-4">Premiação das Ligas</h2>
    <div class="row g-4 justify-content-center">
        <?php foreach ($ligas as $liga): ?>
            <?php
            $premiosEstruturados = [];
            foreach (range(1, 8) as $i) {
                $valor = $liga["premio_{$i}"] ?? null;
                if ($valor !== null && (float) $valor > 0) {
                    $premiosEstruturados[$i] = ['label' => "{$i}º Colocado", 'valor' => (float) $valor];
                }
            }

            // Fallback: se ninguém preencheu os campos estruturados ainda,
            // tenta reconhecer o padrão "Nº Rótulo: R$valor" do texto livre
            // (assim o que já tinha sido digitado antes continua funcionando
            // com o visual novo, sem precisar redigitar no admin).
            if (empty($premiosEstruturados) && !empty($liga['premiacao_texto'])) {
                $premiosEstruturados = parsePremiacaoTexto($liga['premiacao_texto']);
            }

            $total = array_sum(array_column($premiosEstruturados, 'valor'));
            ?>
            <div class="col-md-5">
                <div class="premiacao-card-v2">
                    <div class="premiacao-v2-header">
                        <span class="premiacao-v2-icon">🏆</span>
                        <div>
                            <div class="premiacao-v2-titulo">Premiação</div>
                            <div class="premiacao-v2-liga"><?= e($liga['nome']) ?></div>
                        </div>
                    </div>

                    <?php if (empty($premiosEstruturados)): ?>
                        <p class="text-center text-muted my-4">
                            <?= !empty($liga['premiacao_texto']) ? nl2br(e($liga['premiacao_texto'])) : 'Premiação a definir pelo organizador.' ?>
                        </p>
                    <?php else: ?>
                        <div class="premiacao-v2-lista">
                            <?php
                            $medalhas = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                            $classes = [1 => 'ouro', 2 => 'prata', 3 => 'bronze'];
                            foreach ($premiosEstruturados as $posicao => $dados):
                                $classe = $classes[$posicao] ?? 'padrao';
                            ?>
                                <div class="premio-v2-row <?= $classe ?>">
                                    <span class="premio-v2-badge"><?= $medalhas[$posicao] ?? $posicao ?></span>
                                    <span class="premio-v2-label"><?= e($dados['label']) ?></span>
                                    <span class="premio-v2-valor">R$ <?= number_format($dados['valor'], 2, ',', '.') ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="premio-v2-total">
                            <span class="text-muted small">Total em prêmios</span>
                            <span class="premio-v2-total-valor">R$ <?= number_format($total, 2, ',', '.') ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="premiacao-v2-footer">
                        <?php if ($liga['inscricoes_abertas']): ?>
                            <span class="badge badge-laranja">Inscrições abertas</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inscrições encerradas</span>
                        <?php endif; ?>
                        <?php if (!empty($liga['valor_inscricao'])): ?>
                            <span class="text-muted small">Inscrição: R$ <?= number_format((float) $liga['valor_inscricao'], 2, ',', '.') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="container pb-5">
    <div class="destaques-topo mb-4">
        <h2 class="mb-0">
            Destaques da Rodada
            <?php if ($rodadaDestaques): ?>
                <small class="text-muted fs-6">(rodada <?= (int) $rodadaDestaques['numero'] ?> — <?= e($ligaClassica['nome']) ?>)</small>
            <?php endif; ?>
        </h2>
        <?php if ($textoCompartilharDestaques): ?>
            <button type="button" class="btn btn-whatsapp btn-sm"
                    data-compartilhar-imagem="#captura-destaques"
                    data-titulo="Destaques da Rodada <?= (int) $rodadaDestaques['numero'] ?> — Cartoleirão"
                    data-texto="<?= e($textoCompartilharDestaques) ?>"
                    data-arquivo="destaques-rodada-<?= (int) $rodadaDestaques['numero'] ?>">
                <i class="fa-brands fa-whatsapp"></i> Compartilhar
            </button>
        <?php endif; ?>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php if (empty($destaques)): ?>
                <p class="text-center text-muted">
                    Ainda não há pontuações registradas. Assim que a rodada fechar,
                    os destaques aparecem aqui.
                </p>
            <?php else: ?>
                <div id="captura-destaques" class="captura-card">
                    <div class="captura-card-header">
                        <span class="captura-card-trofeu">🏆</span>
                        <div>
                            <div class="captura-card-titulo">Destaques da Rodada <?= (int) $rodadaDestaques['numero'] ?></div>
                            <div class="captura-card-liga"><?= e($ligaClassica['nome']) ?></div>
                        </div>
                    </div>
                    <div class="captura-card-corpo">
                        <?php foreach ($destaques as $i => $d): ?>
                            <div class="destaque-item">
                                <div class="d-flex align-items-center">
                                    <span class="destaque-posicao"><?= $i + 1 ?></span>
                                    <?php if (!empty($d['escudo_url'])): ?>
                                        <img src="<?= e($d['escudo_url']) ?>" class="escudo-time me-2" alt="">
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold"><?= e($d['nome_cartola'] ?: ('Time ' . $d['cartola_time_id'])) ?></div>
                                        <?php if (!empty($d['participante_nome'])): ?>
                                            <div class="small text-muted"><?= e($d['participante_nome']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="fw-bold text-success"><?= number_format((float) $d['pontos'], 2, ',', '.') ?> pts</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="captura-card-footer">⚽ Cartoleirão</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
