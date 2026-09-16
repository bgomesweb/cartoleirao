<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

/**
 * Pontuação por TIME DA LIGA OFICIAL (times_liga_oficial), não por
 * participante/inscrição. A classificação reflete todo mundo que está de
 * fato jogando na liga do Cartola — a inscrição/pagamento é uma informação
 * à parte, só cruzada aqui pra exibição (badge de pago, nome de contato).
 */
class Pontuacao
{
    public static function upsert(int $timeLigaOficialId, int $rodadaId, float $pontos, ?float $patrimonio, string $origem = 'api'): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO pontuacoes (time_liga_oficial_id, rodada_id, pontos, patrimonio, origem)
            VALUES (:time_liga_oficial_id, :rodada_id, :pontos, :patrimonio, :origem)
            ON DUPLICATE KEY UPDATE
                pontos = VALUES(pontos),
                patrimonio = VALUES(patrimonio),
                origem = VALUES(origem)
        ');
        $stmt->execute([
            ':time_liga_oficial_id' => $timeLigaOficialId,
            ':rodada_id' => $rodadaId,
            ':pontos' => $pontos,
            ':patrimonio' => $patrimonio,
            ':origem' => $origem,
        ]);
    }

    private const SELECT_BASE = "
        SELECT
            t.id AS time_liga_oficial_id,
            t.cartola_time_id,
            t.nome_cartola,
            t.slug,
            t.escudo_url,
            t.participante_id,
            p.nome AS participante_nome,
            p.pago AS pago
    ";

    /** Ranking da liga numa rodada específica. */
    public static function rankingRodada(int $ligaId, int $rodadaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(self::SELECT_BASE . ',
                pt.pontos, pt.patrimonio
            FROM pontuacoes pt
            JOIN times_liga_oficial t ON t.id = pt.time_liga_oficial_id
            LEFT JOIN participantes p ON p.id = t.participante_id
            WHERE t.liga_id = :liga_id AND pt.rodada_id = :rodada_id
            ORDER BY pt.pontos DESC
        ');
        $stmt->execute([':liga_id' => $ligaId, ':rodada_id' => $rodadaId]);

        return $stmt->fetchAll();
    }

    /** Ranking geral acumulado (soma de todas as rodadas já pontuadas). */
    public static function rankingGeral(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(self::SELECT_BASE . ',
                COALESCE(SUM(pt.pontos), 0) AS pontos_total,
                COUNT(pt.id) AS rodadas_pontuadas
            FROM times_liga_oficial t
            LEFT JOIN participantes p ON p.id = t.participante_id
            LEFT JOIN pontuacoes pt ON pt.time_liga_oficial_id = t.id
            WHERE t.liga_id = :liga_id
            GROUP BY t.id, t.cartola_time_id, t.nome_cartola, t.slug, t.escudo_url, t.participante_id, p.nome, p.pago
            ORDER BY pontos_total DESC
        ');
        $stmt->execute([':liga_id' => $ligaId]);

        return $stmt->fetchAll();
    }

    /** Ranking geral acumulado considerando apenas rodadas até (e incluindo) o número informado. */
    public static function rankingGeralAteRodada(int $ligaId, int $numeroRodadaLimite): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(self::SELECT_BASE . ',
                COALESCE(SUM(pt.pontos), 0) AS pontos_total,
                COUNT(pt.id) AS rodadas_pontuadas
            FROM times_liga_oficial t
            LEFT JOIN participantes p ON p.id = t.participante_id
            LEFT JOIN (
                SELECT pt2.id, pt2.time_liga_oficial_id, pt2.pontos
                FROM pontuacoes pt2
                JOIN rodadas r2 ON r2.id = pt2.rodada_id
                WHERE r2.numero <= :numero_limite
            ) pt ON pt.time_liga_oficial_id = t.id
            WHERE t.liga_id = :liga_id
            GROUP BY t.id, t.cartola_time_id, t.nome_cartola, t.slug, t.escudo_url, t.participante_id, p.nome, p.pago
            ORDER BY pontos_total DESC
        ');
        $stmt->execute([':liga_id' => $ligaId, ':numero_limite' => $numeroRodadaLimite]);

        return $stmt->fetchAll();
    }

    /** Números de rodada (desc) que já têm pelo menos uma pontuação pra essa liga. */
    public static function numerosRodadasComDados(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT DISTINCT r.numero
            FROM rodadas r
            JOIN pontuacoes pt ON pt.rodada_id = r.id
            JOIN times_liga_oficial t ON t.id = pt.time_liga_oficial_id
            WHERE t.liga_id = :liga_id
            ORDER BY r.numero DESC
        ');
        $stmt->execute([':liga_id' => $ligaId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Transforma um array de ranking (já ordenado) num mapa time_liga_oficial_id => posição (1-based).
     * Usado pra comparar posições entre dois momentos e calcular a variação (▲▼-).
     */
    public static function mapaDePosicoes(array $ranking): array
    {
        $mapa = [];
        foreach ($ranking as $i => $linha) {
            $mapa[(int) $linha['time_liga_oficial_id']] = $i + 1;
        }

        return $mapa;
    }

    /**
     * Calcula a variação de posição entre dois mapas de posição (atual vs anterior).
     * Retorna null se o time não existia no ranking anterior (estreante).
     * Positivo = subiu, negativo = desceu, 0 = manteve.
     */
    public static function variacaoPosicao(array $mapaAnterior, int $timeLigaOficialId, int $posicaoAtual): ?int
    {
        if (!isset($mapaAnterior[$timeLigaOficialId])) {
            return null;
        }

        return $mapaAnterior[$timeLigaOficialId] - $posicaoAtual;
    }

    /** Top N times de uma liga na rodada informada (usado nos destaques da home). */
    public static function destaquesRodada(int $ligaId, int $rodadaId, int $limite = 8): array
    {
        $ranking = self::rankingRodada($ligaId, $rodadaId);

        return array_slice($ranking, 0, $limite);
    }

    /**
     * Última rodada (número) que já tem pelo menos uma pontuação registrada
     * pra liga informada. Usado pra saber que rodada mostrar nos "destaques"
     * da home sem depender da rodada_atual do mercado (que pode ainda não
     * ter pontuação, pois o mercado abre antes dos jogos acontecerem).
     */
    public static function ultimaRodadaComDados(int $ligaId): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT r.*
            FROM rodadas r
            JOIN pontuacoes pt ON pt.rodada_id = r.id
            JOIN times_liga_oficial t ON t.id = pt.time_liga_oficial_id
            WHERE t.liga_id = :liga_id
            GROUP BY r.id
            ORDER BY r.numero DESC
            LIMIT 1
        ');
        $stmt->execute([':liga_id' => $ligaId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
