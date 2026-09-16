<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class Liga
{
    public static function todas(): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('
            SELECT l.*, lc.inscricoes_abertas, lc.valor_inscricao, lc.premio_1, lc.premio_2, lc.premio_3, lc.premio_4, lc.premio_5, lc.premio_6, lc.premio_7, lc.premio_8, lc.premiacao_texto
            FROM ligas l
            LEFT JOIN liga_config lc ON lc.liga_id = l.id
            ORDER BY l.eh_padrao DESC, l.nome ASC
        ');

        return $stmt->fetchAll();
    }

    public static function porId(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT l.*, lc.inscricoes_abertas, lc.valor_inscricao, lc.premio_1, lc.premio_2, lc.premio_3, lc.premio_4, lc.premio_5, lc.premio_6, lc.premio_7, lc.premio_8, lc.premiacao_texto
            FROM ligas l
            LEFT JOIN liga_config lc ON lc.liga_id = l.id
            WHERE l.id = ?
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function padrao(): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('
            SELECT l.*, lc.inscricoes_abertas, lc.valor_inscricao, lc.premio_1, lc.premio_2, lc.premio_3, lc.premio_4, lc.premio_5, lc.premio_6, lc.premio_7, lc.premio_8, lc.premiacao_texto
            FROM ligas l
            LEFT JOIN liga_config lc ON lc.liga_id = l.id
            WHERE l.eh_padrao = 1
            LIMIT 1
        ');
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function atualizarConfig(int $ligaId, array $dados): void
    {
        $pdo = Connection::get();

        $setPremios = implode(', ', array_map(fn ($i) => "premio_{$i} = :premio_{$i}", range(1, 8)));

        $stmt = $pdo->prepare("
            UPDATE liga_config
            SET inscricoes_abertas = :inscricoes_abertas,
                valor_inscricao = :valor_inscricao,
                {$setPremios},
                premiacao_texto = :premiacao_texto
            WHERE liga_id = :liga_id
        ");

        $params = [
            ':inscricoes_abertas' => (int) $dados['inscricoes_abertas'],
            ':valor_inscricao' => ($dados['valor_inscricao'] ?? '') !== '' ? $dados['valor_inscricao'] : null,
            ':premiacao_texto' => ($dados['premiacao_texto'] ?? '') !== '' ? $dados['premiacao_texto'] : null,
            ':liga_id' => $ligaId,
        ];
        foreach (range(1, 8) as $i) {
            $params[":premio_{$i}"] = ($dados["premio_{$i}"] ?? '') !== '' ? $dados["premio_{$i}"] : null;
        }

        $stmt->execute($params);
    }

    public static function alternarInscricoes(int $ligaId): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE liga_config
            SET inscricoes_abertas = 1 - inscricoes_abertas
            WHERE liga_id = ?
        ');
        $stmt->execute([$ligaId]);
    }

    public static function atualizarSlugOficial(int $ligaId, ?string $slug): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE ligas SET cartola_liga_slug = ? WHERE id = ?');
        $stmt->execute([$slug ?: null, $ligaId]);
    }

    /** Todas as ligas que têm o slug da liga oficial configurado (usado pelo cron de sincronização). */
    public static function comSlugOficial(): array
    {
        $pdo = Connection::get();

        return $pdo->query("
            SELECT * FROM ligas WHERE cartola_liga_slug IS NOT NULL AND cartola_liga_slug != '' AND ativa = 1
        ")->fetchAll();
    }
}
