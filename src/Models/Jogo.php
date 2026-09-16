<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class Jogo
{
    /**
     * Upsert em lote dos jogos de uma rodada, a partir do array de partidas
     * retornado por CartolaApiClient::partidasDaRodada().
     */
    public static function upsertLote(int $rodadaId, array $partidasApi): int
    {
        if (empty($partidasApi)) {
            return 0;
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO jogos
                (cartola_partida_id, rodada_id, clube_casa_id, clube_visitante_id, placar_casa, placar_visitante, local, partida_data)
            VALUES
                (:cartola_partida_id, :rodada_id, :clube_casa_id, :clube_visitante_id, :placar_casa, :placar_visitante, :local, :partida_data)
            ON DUPLICATE KEY UPDATE
                cartola_partida_id = VALUES(cartola_partida_id),
                placar_casa = VALUES(placar_casa),
                placar_visitante = VALUES(placar_visitante),
                local = VALUES(local),
                partida_data = VALUES(partida_data)
        ');

        $total = 0;
        foreach ($partidasApi as $partida) {
            if (empty($partida['clube_casa_id']) || empty($partida['clube_visitante_id'])) {
                continue;
            }

            $placarCasa = $partida['placar_oficial_mandante'] ?? $partida['placar_mandante'] ?? null;
            $placarVisitante = $partida['placar_oficial_visitante'] ?? $partida['placar_visitante'] ?? null;

            $dataPartida = null;
            if (!empty($partida['partida_data'])) {
                $dataPartida = str_replace('T', ' ', substr((string) $partida['partida_data'], 0, 19));
            }

            $stmt->execute([
                ':cartola_partida_id' => $partida['partida_id'] ?? null,
                ':rodada_id' => $rodadaId,
                ':clube_casa_id' => (int) $partida['clube_casa_id'],
                ':clube_visitante_id' => (int) $partida['clube_visitante_id'],
                ':placar_casa' => $placarCasa,
                ':placar_visitante' => $placarVisitante,
                ':local' => $partida['local'] ?? null,
                ':partida_data' => $dataPartida,
            ]);
            $total++;
        }

        return $total;
    }

    /**
     * Jogos de uma rodada, já com nome/escudo dos clubes resolvidos.
     */
    public static function porRodada(int $rodadaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT
                j.*,
                cc.nome AS casa_nome, cc.abreviacao AS casa_abreviacao, cc.escudo_url AS casa_escudo,
                cv.nome AS visitante_nome, cv.abreviacao AS visitante_abreviacao, cv.escudo_url AS visitante_escudo
            FROM jogos j
            JOIN clubes cc ON cc.id = j.clube_casa_id
            JOIN clubes cv ON cv.id = j.clube_visitante_id
            WHERE j.rodada_id = ?
            ORDER BY j.partida_data ASC
        ');
        $stmt->execute([$rodadaId]);

        return $stmt->fetchAll();
    }
}
