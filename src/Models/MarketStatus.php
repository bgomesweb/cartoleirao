<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class MarketStatus
{
    /**
     * Map of the Cartola API's status_mercado codes. There's no official
     * documentation — values cross-checked against third-party
     * implementations. An unmapped code falls back to "Status N".
     */
    private const LABELS = [
        1 => ['label' => 'Mercado Aberto', 'class' => 'aberto'],
        2 => ['label' => 'Mercado Fechado', 'class' => 'fechado'],
        3 => ['label' => 'Atualizando', 'class' => 'manutencao'],
        4 => ['label' => 'Em Manutenção', 'class' => 'manutencao'],
        5 => ['label' => 'Em Manutenção', 'class' => 'manutencao'],
        6 => ['label' => 'Encerrado', 'class' => 'fechado'],
    ];

    public static function update(?int $statusCode, ?int $currentRound): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE market_status_cache
            SET status_code = :status_code, current_round = :current_round, updated_at = NOW()
            WHERE id = 1
        ');
        $stmt->execute([
            ':status_code' => $statusCode,
            ':current_round' => $currentRound,
        ]);
    }

    public static function current(): ?array
    {
        $pdo = Connection::get();
        $row = $pdo->query('SELECT * FROM market_status_cache WHERE id = 1')->fetch();

        if (!$row || $row['status_code'] === null) {
            return null;
        }

        $info = self::LABELS[(int) $row['status_code']] ?? ['label' => 'Status ' . $row['status_code'], 'class' => 'manutencao'];

        return [
            'status_code' => (int) $row['status_code'],
            'current_round' => $row['current_round'] !== null ? (int) $row['current_round'] : null,
            'label' => $info['label'],
            'class' => $info['class'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
