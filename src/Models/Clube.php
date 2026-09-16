<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class Clube
{
    /**
     * Faz upsert em lote a partir do array retornado por CartolaApiClient::clubes()
     * (dicionário id => dados do clube).
     */
    public static function upsertLote(array $clubesApi): int
    {
        if (empty($clubesApi)) {
            return 0;
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO clubes (id, nome, nome_fantasia, abreviacao, escudo_url)
            VALUES (:id, :nome, :nome_fantasia, :abreviacao, :escudo_url)
            ON DUPLICATE KEY UPDATE
                nome = VALUES(nome),
                nome_fantasia = VALUES(nome_fantasia),
                abreviacao = VALUES(abreviacao),
                escudo_url = VALUES(escudo_url)
        ');

        $total = 0;
        foreach ($clubesApi as $clube) {
            if (empty($clube['id'])) {
                continue;
            }

            $escudo = $clube['escudos']['60x60']
                ?? $clube['escudos']['45x45']
                ?? $clube['escudo']
                ?? null;

            $stmt->execute([
                ':id' => (int) $clube['id'],
                ':nome' => $clube['nome'] ?? ('Clube ' . $clube['id']),
                ':nome_fantasia' => $clube['nome_fantasia'] ?? null,
                ':abreviacao' => $clube['abreviacao'] ?? null,
                ':escudo_url' => $escudo,
            ]);
            $total++;
        }

        return $total;
    }

    public static function todos(): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT * FROM clubes ORDER BY nome');
        $porId = [];
        foreach ($stmt->fetchAll() as $clube) {
            $porId[(int) $clube['id']] = $clube;
        }

        return $porId;
    }

    public static function total(): int
    {
        $pdo = Connection::get();

        return (int) $pdo->query('SELECT COUNT(*) FROM clubes')->fetchColumn();
    }
}
