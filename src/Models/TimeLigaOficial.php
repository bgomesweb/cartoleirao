<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class TimeLigaOficial
{
    public static function upsert(
        int $ligaId,
        int $cartolaTimeId,
        ?string $nomeCartola,
        ?string $slug,
        ?string $escudoUrl,
        ?int $participanteId,
        ?string $nomeDono = null,
        bool $assinante = false
    ): void {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO times_liga_oficial (liga_id, cartola_time_id, nome_cartola, nome_dono, assinante, slug, escudo_url, participante_id)
            VALUES (:liga_id, :cartola_time_id, :nome_cartola, :nome_dono, :assinante, :slug, :escudo_url, :participante_id)
            ON DUPLICATE KEY UPDATE
                nome_cartola = VALUES(nome_cartola),
                nome_dono = VALUES(nome_dono),
                assinante = VALUES(assinante),
                slug = VALUES(slug),
                escudo_url = VALUES(escudo_url),
                participante_id = COALESCE(VALUES(participante_id), participante_id)
        ');
        $stmt->execute([
            ':liga_id' => $ligaId,
            ':cartola_time_id' => $cartolaTimeId,
            ':nome_cartola' => $nomeCartola,
            ':nome_dono' => $nomeDono,
            ':assinante' => $assinante ? 1 : 0,
            ':slug' => $slug,
            ':escudo_url' => $escudoUrl,
            ':participante_id' => $participanteId,
        ]);
    }

    /**
     * Grava o snapshot da tabela oficial de pontos corridos (posição,
     * variação, V/E/D, pontos de tabela, pontuação acumulada do cartola) —
     * vem do array `ranking` da resposta da liga, atualizado a cada import.
     */
    public static function atualizarSnapshotPontosCorridos(
        int $ligaId,
        int $cartolaTimeId,
        int $posicao,
        int $variacao,
        int $pontos,
        int $vitorias,
        int $empates,
        int $derrotas,
        float $pontosCartola
    ): void {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE times_liga_oficial
            SET pc_posicao = :posicao,
                pc_variacao = :variacao,
                pc_pontos = :pontos,
                pc_vitorias = :vitorias,
                pc_empates = :empates,
                pc_derrotas = :derrotas,
                pc_pontos_cartola = :pontos_cartola
            WHERE liga_id = :liga_id AND cartola_time_id = :cartola_time_id
        ');
        $stmt->execute([
            ':posicao' => $posicao,
            ':variacao' => $variacao,
            ':pontos' => $pontos,
            ':vitorias' => $vitorias,
            ':empates' => $empates,
            ':derrotas' => $derrotas,
            ':pontos_cartola' => $pontosCartola,
            ':liga_id' => $ligaId,
            ':cartola_time_id' => $cartolaTimeId,
        ]);
    }

    /** Tabela oficial de pontos corridos, ordenada pela posição oficial (pc_posicao). */
    public static function porLigaOrdenadoPontosCorridos(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT t.*, p.nome AS participante_nome, p.email AS participante_email, p.pago AS participante_pago
            FROM times_liga_oficial t
            LEFT JOIN participantes p ON p.id = t.participante_id
            WHERE t.liga_id = :liga_id
            ORDER BY (t.pc_posicao IS NULL), t.pc_posicao ASC
        ');
        $stmt->execute([':liga_id' => $ligaId]);

        return $stmt->fetchAll();
    }

    /** Todos os times da liga oficial, com o nome do participante casado (se houver). */
    public static function porLiga(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT t.*, p.nome AS participante_nome, p.email AS participante_email, p.pago AS participante_pago
            FROM times_liga_oficial t
            LEFT JOIN participantes p ON p.id = t.participante_id
            WHERE t.liga_id = :liga_id
            ORDER BY (t.participante_id IS NULL) DESC, t.nome_cartola ASC
        ');
        $stmt->execute([':liga_id' => $ligaId]);

        return $stmt->fetchAll();
    }

    public static function contarSemCorrespondencia(int $ligaId): int
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM times_liga_oficial WHERE liga_id = :liga_id AND participante_id IS NULL
        ');
        $stmt->execute([':liga_id' => $ligaId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Vincula manualmente um time da liga oficial a um participante inscrito
     * (cruzamento pra exibir nome/status de pagamento na classificação).
     */
    public static function vincularParticipante(int $timeLigaOficialId, int $participanteId): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE times_liga_oficial SET participante_id = ? WHERE id = ?');
        $stmt->execute([$participanteId, $timeLigaOficialId]);
    }

    public static function porId(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM times_liga_oficial WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Mapa cartola_time_id => id local, usado pra resolver a FK de pontuacoes em lote. */
    public static function mapaCartolaIdParaId(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT id, cartola_time_id FROM times_liga_oficial WHERE liga_id = ?');
        $stmt->execute([$ligaId]);

        $mapa = [];
        foreach ($stmt->fetchAll() as $row) {
            $mapa[(int) $row['cartola_time_id']] = (int) $row['id'];
        }

        return $mapa;
    }
}
