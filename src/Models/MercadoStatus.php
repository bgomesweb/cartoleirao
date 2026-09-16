<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class MercadoStatus
{
    /**
     * Mapa dos códigos de status_mercado da API do Cartola. Não há
     * documentação oficial — valores conferidos com implementações de
     * terceiros. Se aparecer um código novo, cai no fallback "Status N".
     */
    private const LABELS = [
        1 => ['label' => 'Mercado Aberto', 'classe' => 'aberto'],
        2 => ['label' => 'Mercado Fechado', 'classe' => 'fechado'],
        3 => ['label' => 'Atualizando', 'classe' => 'manutencao'],
        4 => ['label' => 'Em Manutenção', 'classe' => 'manutencao'],
        5 => ['label' => 'Em Manutenção', 'classe' => 'manutencao'],
        6 => ['label' => 'Encerrado', 'classe' => 'fechado'],
    ];

    public static function atualizar(?int $statusMercado, ?int $rodadaAtual): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE mercado_status_cache
            SET status_mercado = :status_mercado, rodada_atual = :rodada_atual, atualizado_em = NOW()
            WHERE id = 1
        ');
        $stmt->execute([
            ':status_mercado' => $statusMercado,
            ':rodada_atual' => $rodadaAtual,
        ]);
    }

    public static function atual(): ?array
    {
        $pdo = Connection::get();
        $row = $pdo->query('SELECT * FROM mercado_status_cache WHERE id = 1')->fetch();

        if (!$row || $row['status_mercado'] === null) {
            return null;
        }

        $info = self::LABELS[(int) $row['status_mercado']] ?? ['label' => 'Status ' . $row['status_mercado'], 'classe' => 'manutencao'];

        return [
            'status_mercado' => (int) $row['status_mercado'],
            'rodada_atual' => $row['rodada_atual'] !== null ? (int) $row['rodada_atual'] : null,
            'label' => $info['label'],
            'classe' => $info['classe'],
            'atualizado_em' => $row['atualizado_em'],
        ];
    }
}
