<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Database\Connection;
use Cartoleirao\Models\Clube;
use Cartoleirao\Models\Jogo;
use Cartoleirao\Models\MercadoStatus;
use Cartoleirao\Models\Participante;
use Cartoleirao\Models\Pontuacao;
use Cartoleirao\Models\Rodada;
use Cartoleirao\Models\TimeLigaOficial;

class SincronizacaoService
{
    private CartolaApiClient $api;
    private CartolaAuthService $auth;

    public function __construct(?CartolaApiClient $api = null, ?CartolaAuthService $auth = null)
    {
        $this->api = $api ?? new CartolaApiClient();
        $this->auth = $auth ?? new CartolaAuthService($this->api);
    }

    /** Último erro HTTP/parse da chamada à API do Cartola (não confundir com erro de login/token). */
    public function getUltimoErroApi(): ?string
    {
        return $this->api->getUltimoErro();
    }

    /**
     * Consulta /mercado/status e cacheia localmente (status_mercado +
     * rodada_atual), pra exibir o badge ao lado do logo sem bater na API a
     * cada pageview.
     */
    public function sincronizarStatusMercado(?array $statusMercado = null): ?array
    {
        try {
            $statusMercado = $statusMercado ?? $this->api->statusMercado();
        } catch (\Throwable $e) {
            $this->log('mercado_status', null, false, $e->getMessage());
            return null;
        }

        if (!$statusMercado) {
            $this->log('mercado_status', null, false, 'API não retornou status do mercado');
            return null;
        }

        $status = isset($statusMercado['status_mercado']) ? (int) $statusMercado['status_mercado'] : null;
        $rodada = isset($statusMercado['rodada_atual']) ? (int) $statusMercado['rodada_atual'] : null;

        MercadoStatus::atualizar($status, $rodada);
        $this->log('mercado_status', $rodada, true, "status={$status}");

        return $statusMercado;
    }

    /**
     * Sincroniza o cache local de clubes. Roda com baixa frequência (os
     * clubes praticamente não mudam durante o campeonato).
     */
    public function sincronizarClubes(?array $dadosClubes = null): int
    {
        try {
            $dadosClubes = $dadosClubes ?? $this->api->clubes();
        } catch (\Throwable $e) {
            $this->log('clubes', null, false, $e->getMessage());
            return 0;
        }

        if (!$dadosClubes) {
            $this->log('clubes', null, false, 'API não retornou dados de clubes — ' . ($this->api->getUltimoErro() ?? 'sem detalhes'));
            return 0;
        }

        $total = Clube::upsertLote($dadosClubes);
        $this->log('clubes', null, true, "{$total} clubes sincronizados");

        return $total;
    }

    /**
     * Descobre a rodada atual (via /mercado/status), sincroniza os jogos
     * dessa rodada e devolve o número da rodada processada (ou null se falhou).
     */
    public function sincronizarJogosRodadaAtual(?array $statusMercado = null, ?array $partidas = null): ?int
    {
        try {
            $statusMercado = $statusMercado ?? $this->api->statusMercado();
        } catch (\Throwable $e) {
            $this->log('jogos', null, false, $e->getMessage());
            return null;
        }

        $numeroRodada = $statusMercado['rodada_atual'] ?? null;
        if (!$numeroRodada) {
            $this->log('jogos', null, false, 'Não foi possível determinar a rodada atual — ' . ($this->api->getUltimoErro() ?? 'resposta sem campo rodada_atual'));
            return null;
        }

        return $this->sincronizarJogosDaRodada((int) $numeroRodada, $partidas);
    }

    public function sincronizarJogosDaRodada(int $numeroRodada, ?array $partidas = null): ?int
    {
        // garante que temos clubes suficientes pra resolver as FKs dos jogos
        if (Clube::total() === 0) {
            $this->sincronizarClubes();
        }

        try {
            $respostaPartidas = $partidas ?? $this->api->partidasDaRodada($numeroRodada);
        } catch (\Throwable $e) {
            $this->log('jogos', $numeroRodada, false, $e->getMessage());
            return null;
        }

        if (!$respostaPartidas) {
            $this->log('jogos', $numeroRodada, false, 'API não retornou partidas para a rodada — ' . ($this->api->getUltimoErro() ?? 'sem detalhes'));
            return null;
        }

        $listaPartidas = $respostaPartidas['partidas'] ?? $respostaPartidas;

        $rodadaId = Rodada::upsert($numeroRodada, null, null, 'aberta');
        $total = Jogo::upsertLote($rodadaId, $listaPartidas);

        $this->log('jogos', $numeroRodada, true, "{$total} jogos sincronizados");

        return $numeroRodada;
    }

    /**
     * Para cada time cadastrado em `times_liga_oficial` (independente de ter
     * participante/inscrição correspondente), busca a pontuação da rodada
     * informada via endpoint público (`/time/id/{id}/{rodada}`, sem login) e
     * grava em `pontuacoes`.
     *
     * @return array{sucesso:int, falhas:int}
     */
    public function sincronizarPontuacoesDaRodada(int $numeroRodada): array
    {
        $rodada = Rodada::porNumero($numeroRodada) ?? ['id' => Rodada::upsert($numeroRodada)];
        $rodadaId = (int) $rodada['id'];

        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT id, cartola_time_id FROM times_liga_oficial');
        $times = $stmt->fetchAll();

        $sucesso = 0;
        $falhas = 0;

        foreach ($times as $time) {
            try {
                $dados = $this->api->pontuacaoTimeNaRodada((int) $time['cartola_time_id'], $numeroRodada);
            } catch (\Throwable $e) {
                $falhas++;
                continue;
            }

            if (!$dados || !isset($dados['pontos'])) {
                $falhas++;
                continue;
            }

            Pontuacao::upsert(
                (int) $time['id'],
                $rodadaId,
                (float) $dados['pontos'],
                isset($dados['patrimonio']) ? (float) $dados['patrimonio'] : null
            );
            $sucesso++;
        }

        $this->log('pontuacao', $numeroRodada, $falhas === 0, "{$sucesso} ok, {$falhas} falhas (de " . count($times) . " times cadastrados)");

        return ['sucesso' => $sucesso, 'falhas' => $falhas];
    }

    /**
     * Loga na conta configurada (.env) e consulta a liga OFICIAL do Cartola,
     * casando cada time encontrado com um participante do nosso sistema quando
     * possível (por time_id já vinculado, ou por nome do time informado na
     * inscrição). Times que não casam com ninguém ficam visíveis no admin como
     * "sem inscrição correspondente" — alguém entrou na liga oficial mas ainda
     * não preencheu nossa inscrição.
     *
     * @return array{total:int, vinculados:int, sem_correspondencia:int}|null null se não deu pra autenticar/consultar
     */
    public function sincronizarTimesDaLigaOficial(int $ligaId, string $slugLigaOficial, ?array $respostaForcada = null, string $tipo = 'classica'): ?array
    {
        $times = [];
        $respostaCompleta = null;

        if ($respostaForcada !== null) {
            $times = $this->extrairTimesDaResposta($respostaForcada);
            $respostaCompleta = $respostaForcada;
        } elseif ($tipo === 'pontos_corridos') {
            // Formato novo (ID opaco, ex: cartola.globo.com/#!/competicoes/pontoscorridos/{id}):
            // resposta única, sem paginação — já vem com participantes, confrontos e ranking prontos.
            $token = $this->auth->obterToken();
            if (!$token) {
                $this->log('liga_oficial', null, false, 'Sem token de autenticação: ' . ($this->auth->getUltimoErro() ?? 'motivo desconhecido'));
                return null;
            }

            $resposta = $this->api->competicaoPontosCorridos($slugLigaOficial, $token);

            if ($resposta === null) {
                $erro = $this->api->getUltimoErro() ?? '';
                if (str_contains($erro, 'HTTP 401') || str_contains($erro, 'HTTP 403')) {
                    $token = $this->auth->renovarToken();
                    if ($token) {
                        $resposta = $this->api->competicaoPontosCorridos($slugLigaOficial, $token);
                    }
                }
            }

            if ($resposta === null) {
                $this->log('liga_oficial', null, false, 'Falha ao consultar competição pontos corridos: ' . ($this->api->getUltimoErro() ?? 'sem detalhes'));
                return null;
            }

            $respostaCompleta = $resposta;
            $times = $this->extrairTimesDaResposta($resposta);
        } else {
            $token = $this->auth->obterToken();
            if (!$token) {
                $this->log('liga_oficial', null, false, 'Sem token de autenticação: ' . ($this->auth->getUltimoErro() ?? 'motivo desconhecido'));
                return null;
            }

            $pagina = 1;
            $maxPaginas = 25; // proteção contra loop infinito
            $tokenRenovado = false;

            while ($pagina <= $maxPaginas) {
                $resposta = $this->api->ligaOficial($slugLigaOficial, $token, $pagina);

                // token pode ter expirado -> tenta renovar uma vez
                if ($resposta === null && !$tokenRenovado) {
                    $erro = $this->api->getUltimoErro() ?? '';
                    if (str_contains($erro, 'HTTP 401') || str_contains($erro, 'HTTP 403')) {
                        $token = $this->auth->renovarToken();
                        $tokenRenovado = true;
                        if (!$token) {
                            $this->log('liga_oficial', null, false, 'Token expirou e a renovação falhou: ' . ($this->auth->getUltimoErro() ?? ''));
                            return null;
                        }
                        continue; // tenta de novo a mesma página com o token novo
                    }
                }

                if ($resposta === null) {
                    $this->log('liga_oficial', null, false, 'Falha ao consultar liga oficial: ' . ($this->api->getUltimoErro() ?? 'sem detalhes'));
                    return $times ? $this->processarTimesDaLigaOficial($ligaId, $times) : null;
                }

                $respostaCompleta = $resposta;
                $timesDaPagina = $this->extrairTimesDaResposta($resposta);
                if (empty($timesDaPagina)) {
                    break;
                }

                $times = array_merge($times, $timesDaPagina);
                $pagina++;
            }
        }

        if (empty($times)) {
            $this->log('liga_oficial', null, false, 'Nenhum time encontrado na liga oficial (slug: ' . $slugLigaOficial . ')');
            return ['total' => 0, 'vinculados' => 0, 'sem_correspondencia' => 0, 'rodadas_importadas' => 0];
        }

        $resultado = $this->processarTimesDaLigaOficial($ligaId, $times);

        // Bônus: se a resposta trouxer o histórico de confrontos (formato
        // "pontos corridos"), já aproveita e grava a pontuação de todas as
        // rodadas já jogadas de uma vez, sem precisar do endpoint público
        // rodada por rodada.
        $resultado['rodadas_importadas'] = $respostaCompleta
            ? $this->processarConfrontosDaResposta($ligaId, $respostaCompleta)
            : 0;

        // Bônus 2: o array `ranking` já traz a tabela oficial pronta
        // (posição, variação, V/E/D, pontos de tabela, pontuação acumulada) —
        // grava esse snapshot pra exibir igual ao site do Cartola.
        $resultado['ranking_atualizado'] = $respostaCompleta
            ? $this->processarRankingDaResposta($ligaId, $respostaCompleta)
            : 0;

        return $resultado;
    }

    /**
     * Bônus do formato "pontos corridos": a resposta traz um array `ranking`
     * já com a tabela oficial pronta (posição, variação, V/E/D, pontos de
     * tabela, pontuação acumulada do cartola) — exatamente o que o site do
     * Cartola exibe na "Tabela de Resultados". A gente só grava esse
     * snapshot, sem recalcular nada.
     */
    private function processarRankingDaResposta(int $ligaId, array $resposta): int
    {
        if (!isset($resposta['ranking']) || !is_array($resposta['ranking'])) {
            return 0;
        }

        $mapaIds = TimeLigaOficial::mapaCartolaIdParaId($ligaId);
        $total = 0;

        foreach ($resposta['ranking'] as $item) {
            if (!is_array($item) || !isset($item['time_id'])) {
                continue;
            }

            $cartolaTimeId = (int) $item['time_id'];
            if (!isset($mapaIds[$cartolaTimeId])) {
                continue; // time no ranking que não veio em `participantes` (não deveria acontecer)
            }

            TimeLigaOficial::atualizarSnapshotPontosCorridos(
                $ligaId,
                $cartolaTimeId,
                (int) ($item['posicao'] ?? 0),
                (int) ($item['variacao'] ?? 0),
                (int) ($item['pontos'] ?? 0),
                (int) ($item['vitorias'] ?? 0),
                (int) ($item['empates'] ?? 0),
                (int) ($item['derrotas'] ?? 0),
                (float) ($item['pontos_cartola'] ?? 0)
            );
            $total++;
        }

        $this->log('liga_oficial', null, true, "{$total} times com snapshot da tabela oficial atualizado");

        return $total;
    }

    /**
     * @param array<int,array{time_id:int,nome_raw:string,nome_cartola_raw:string,slug:string,escudo:?string}> $times
     * @return array{total:int, vinculados:int, sem_correspondencia:int}
     */
    private function processarTimesDaLigaOficial(int $ligaId, array $times): array
    {
        // A API do Cartola já teve os campos `nome`/`nome_cartola` observados
        // em ordens diferentes dependendo do formato da liga. Em vez de fixar
        // uma convenção no código, a gente decide dinamicamente, comparando
        // os dois campos com os nomes de time que os próprios inscritos
        // informaram no nosso formulário — quem tiver mais acertos é o campo
        // certo.
        $campoNomeTime = $this->decidirCampoNomeDoTime($ligaId, $times);
        $campoNomeDono = $campoNomeTime === 'nome_raw' ? 'nome_cartola_raw' : 'nome_raw';

        $vinculados = 0;
        $semCorrespondencia = 0;

        foreach ($times as $time) {
            $timeId = $time['time_id'];
            $participanteId = null;
            $nomeTime = $time[$campoNomeTime];
            $nomeDono = $time[$campoNomeDono];

            // 1) já existe alguém dessa liga vinculado a esse time_id?
            $participante = Participante::porLigaETimeId($ligaId, $timeId);

            // 2) senão, tenta casar pelo nome do time informado na inscrição
            //    — tenta primeiro o campo já identificado como "nome do
            //    time" e, se não achar, tenta o outro campo também (cobre o
            //    caso raro de a convenção variar dentro da mesma resposta).
            if (!$participante && $nomeTime !== '') {
                $participante = Participante::porLigaENomeTimeSemVinculo($ligaId, $nomeTime);
                if ($participante) {
                    Participante::vincularTimeCartola((int) $participante['id'], $timeId, $nomeTime, $time['slug']);
                }
            }

            if (!$participante && $nomeDono !== '') {
                $participante = Participante::porLigaENomeTimeSemVinculo($ligaId, $nomeDono);
                if ($participante) {
                    // achou pelo campo "trocado" — o nome de exibição do time
                    // pra esse participante específico é o outro campo
                    $nomeTime = $nomeDono;
                    Participante::vincularTimeCartola((int) $participante['id'], $timeId, $nomeTime, $time['slug']);
                }
            }

            if ($participante) {
                $participanteId = (int) $participante['id'];
                $vinculados++;
            } else {
                $semCorrespondencia++;
            }

            TimeLigaOficial::upsert(
                $ligaId,
                $timeId,
                $nomeTime,
                $time['slug'],
                $time['escudo'] ?? null,
                $participanteId,
                $nomeDono ?: null,
                $time['assinante'] ?? false
            );
        }

        $total = count($times);
        $this->log('liga_oficial', null, true, "{$total} times na liga oficial, {$vinculados} vinculados, {$semCorrespondencia} sem correspondência (campo detectado como nome do time: {$campoNomeTime})");

        return ['total' => $total, 'vinculados' => $vinculados, 'sem_correspondencia' => $semCorrespondencia];
    }

    /**
     * Decide qual dos dois campos vindos da API ("nome" ou "nome_cartola")
     * realmente contém o nome do time, comparando os valores com os nomes de
     * time que os participantes informaram na própria inscrição. Sempre que
     * houver dados suficientes pra decidir, essa detecção automática vale
     * mais do que qualquer suposição fixa sobre o formato da API — o formato
     * já mudou de convenção antes e pode voltar a mudar.
     *
     * @param array<int,array{nome_raw:string,nome_cartola_raw:string}> $times
     */
    private function decidirCampoNomeDoTime(int $ligaId, array $times): string
    {
        $nomesRegistrados = Participante::nomesTimeCartolaPorLiga($ligaId);

        if (empty($nomesRegistrados)) {
            // ninguém se inscreveu ainda (ou não informou nome de time) —
            // não tem com o que comparar, mantém a convenção documentada
            // como padrão de segurança
            return 'nome_raw';
        }

        $acertosNome = 0;
        $acertosNomeCartola = 0;

        foreach ($times as $time) {
            $nome = mb_strtolower(trim((string) ($time['nome_raw'] ?? '')));
            $nomeCartola = mb_strtolower(trim((string) ($time['nome_cartola_raw'] ?? '')));

            if ($nome !== '' && in_array($nome, $nomesRegistrados, true)) {
                $acertosNome++;
            }
            if ($nomeCartola !== '' && in_array($nomeCartola, $nomesRegistrados, true)) {
                $acertosNomeCartola++;
            }
        }

        $campo = $acertosNomeCartola > $acertosNome ? 'nome_cartola_raw' : 'nome_raw';

        $this->log(
            'liga_oficial',
            null,
            true,
            "Detecção automática do campo de nome do time: '{$campo}' (acertos nome={$acertosNome}, nome_cartola={$acertosNomeCartola}, inscritos comparados=" . count($nomesRegistrados) . ')'
        );

        return $campo;
    }

    /**
     * A resposta de auth/liga/{slug} tem pelo menos dois formatos conhecidos:
     *
     * 1) "pontos corridos": dicionário `participantes` (chave = time_id).
     * 2) Formato genérico alternativo (liga clássica): lista em `times` ou na raiz.
     *
     * IMPORTANTE: qual dos campos (`nome` ou `nome_cartola`) é o nome do
     * time e qual é o nome do dono/cartoleiro já variou entre respostas
     * observadas — por isso esse método NÃO decide isso. Ele só extrai os
     * dois valores crus (`nome_raw` e `nome_cartola_raw`); quem decide qual é
     * qual é `decidirCampoNomeDoTime()`, comparando com os dados reais dos
     * inscritos.
     */
    private function extrairTimesDaResposta(array $resposta): array
    {
        // Formato 1: dicionário `participantes` (liga pontos corridos)
        if (isset($resposta['participantes']) && is_array($resposta['participantes'])) {
            $times = [];
            foreach ($resposta['participantes'] as $item) {
                if (!is_array($item) || !isset($item['time_id'])) {
                    continue;
                }

                $times[] = [
                    'time_id' => (int) $item['time_id'],
                    'nome_raw' => trim((string) ($item['nome'] ?? '')),
                    'nome_cartola_raw' => trim((string) ($item['nome_cartola'] ?? '')),
                    'slug' => $item['slug'] ?? '',
                    'escudo' => $item['url_escudo_svg'] ?? $item['url_escudo_png'] ?? ($item['escudo'] ?? null),
                    'assinante' => !empty($item['assinante']),
                ];
            }

            return $times;
        }

        // Formato 2: fallback genérico (formato ainda não confirmado)
        $bruto = $resposta['times'] ?? $resposta['liga']['times'] ?? $resposta;

        if (!is_array($bruto)) {
            return [];
        }

        $times = [];
        foreach ($bruto as $item) {
            if (!is_array($item) || !isset($item['time_id'])) {
                continue;
            }

            $times[] = [
                'time_id' => (int) $item['time_id'],
                'nome_raw' => trim((string) ($item['nome'] ?? '')),
                'nome_cartola_raw' => trim((string) ($item['nome_cartola'] ?? ($item['nome'] ?? ''))),
                'slug' => $item['slug'] ?? '',
                'escudo' => $item['url_escudo_svg'] ?? $item['url_escudo_png'] ?? ($item['escudo'] ?? null),
            ];
        }

        return $times;
    }

    /**
     * Bônus do formato "pontos corridos": a resposta já traz o histórico
     * completo de pontuação por rodada em `confrontos` (chave = número da
     * rodada, valor = lista de confrontos internos da liga, cada um com
     * mandante/visitante contendo `id` do time e `pontuacao_rodada`).
     * Isso evita ter que chamar o endpoint público rodada por rodada, time
     * por time — vem tudo de uma vez só no import.
     */
    private function processarConfrontosDaResposta(int $ligaId, array $resposta): int
    {
        if (!isset($resposta['confrontos']) || !is_array($resposta['confrontos'])) {
            return 0;
        }

        $mapaIds = TimeLigaOficial::mapaCartolaIdParaId($ligaId);
        $totalRodadas = 0;

        foreach ($resposta['confrontos'] as $numeroRodada => $confrontosDaRodada) {
            $numeroRodada = (int) $numeroRodada;
            if ($numeroRodada <= 0 || !is_array($confrontosDaRodada)) {
                continue;
            }

            $rodadaId = null;

            foreach ($confrontosDaRodada as $confronto) {
                foreach (['mandante', 'visitante'] as $lado) {
                    $dados = $confronto[$lado] ?? null;

                    // rodadas futuras só têm o 'id', sem pontuacao_rodada ainda
                    if (!is_array($dados) || !isset($dados['id']) || !array_key_exists('pontuacao_rodada', $dados) || $dados['pontuacao_rodada'] === null) {
                        continue;
                    }

                    $cartolaTimeId = (int) $dados['id'];
                    if (!isset($mapaIds[$cartolaTimeId])) {
                        continue;
                    }

                    if ($rodadaId === null) {
                        $rodadaId = Rodada::upsert($numeroRodada, null, null, 'fechada');
                    }

                    Pontuacao::upsert($mapaIds[$cartolaTimeId], $rodadaId, (float) $dados['pontuacao_rodada'], null, 'api');
                }
            }

            if ($rodadaId !== null) {
                $totalRodadas++;
            }
        }

        $this->log('liga_oficial', null, true, "{$totalRodadas} rodadas de pontuação importadas do JSON colado (confrontos)");

        return $totalRodadas;
    }

    private function log(string $tipo, ?int $rodadaNumero, bool $sucesso, string $mensagem): void
    {
        try {
            $pdo = Connection::get();
            $stmt = $pdo->prepare('
                INSERT INTO sync_log (tipo, rodada_numero, sucesso, mensagem)
                VALUES (:tipo, :rodada_numero, :sucesso, :mensagem)
            ');
            $stmt->execute([
                ':tipo' => $tipo,
                ':rodada_numero' => $rodadaNumero,
                ':sucesso' => $sucesso ? 1 : 0,
                ':mensagem' => $mensagem,
            ]);
        } catch (\Throwable $e) {
            // log nunca deve derrubar a sincronização
        }
    }
}
