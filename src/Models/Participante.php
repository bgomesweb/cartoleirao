<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;
use PDOException;

class Participante
{
    /**
     * @throws \InvalidArgumentException se e-mail já inscrito na liga
     */
    public static function criar(array $dados): int
    {
        $pdo = Connection::get();

        $stmt = $pdo->prepare('
            INSERT INTO participantes
                (liga_id, nome, email, whatsapp, nome_time_cartola, cartola_time_id, cartola_time_slug, vinculado_api, pago)
            VALUES
                (:liga_id, :nome, :email, :whatsapp, :nome_time_cartola, :cartola_time_id, :cartola_time_slug, :vinculado_api, 0)
        ');

        try {
            $stmt->execute([
                ':liga_id' => $dados['liga_id'],
                ':nome' => $dados['nome'],
                ':email' => $dados['email'],
                ':whatsapp' => $dados['whatsapp'],
                ':nome_time_cartola' => ($dados['nome_time_cartola'] ?? null) ?: null,
                ':cartola_time_id' => $dados['cartola_time_id'] ?? null,
                ':cartola_time_slug' => $dados['cartola_time_slug'] ?? null,
                ':vinculado_api' => !empty($dados['cartola_time_id']) ? 1 : 0,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \InvalidArgumentException('Este e-mail já está inscrito nesta liga.');
            }
            throw $e;
        }

        return (int) $pdo->lastInsertId();
    }

    public static function porLiga(int $ligaId, ?string $filtroPago = null): array
    {
        $pdo = Connection::get();
        $sql = 'SELECT * FROM participantes WHERE liga_id = ? AND ativo = 1';
        $params = [$ligaId];

        if ($filtroPago === 'pago') {
            $sql .= ' AND pago = 1';
        } elseif ($filtroPago === 'pendente') {
            $sql .= ' AND pago = 0';
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function porId(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM participantes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function alternarPago(int $id): bool
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE participantes SET pago = 1 - pago WHERE id = ?');
        $stmt->execute([$id]);

        $stmt = $pdo->prepare('SELECT pago FROM participantes WHERE id = ?');
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    public static function vincularTimeCartola(int $id, int $timeId, string $timeNome, string $timeSlug): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE participantes
            SET cartola_time_id = :time_id,
                cartola_time_slug = :time_slug,
                nome_time_cartola = :time_nome,
                vinculado_api = 1
            WHERE id = :id
        ');
        $stmt->execute([
            ':time_id' => $timeId,
            ':time_slug' => $timeSlug,
            ':time_nome' => $timeNome,
            ':id' => $id,
        ]);
    }

    /** Participante da liga já vinculado a esse cartola_time_id (se houver). */
    public static function porLigaETimeId(int $ligaId, int $cartolaTimeId): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT * FROM participantes
            WHERE liga_id = :liga_id AND cartola_time_id = :time_id AND ativo = 1
            LIMIT 1
        ');
        $stmt->execute([':liga_id' => $ligaId, ':time_id' => $cartolaTimeId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Participante da liga ainda SEM vínculo cujo nome do time (informado na
     * inscrição) bate, ignorando maiúsculas/acentos/espaços, com o nome
     * vindo da liga oficial. Usado pra casar automaticamente sem duplicar
     * um vínculo que já foi feito por outro caminho.
     */
    public static function porLigaENomeTimeSemVinculo(int $ligaId, string $nomeTime): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT * FROM participantes
            WHERE liga_id = :liga_id
              AND ativo = 1
              AND vinculado_api = 0
              AND nome_time_cartola IS NOT NULL
              AND LOWER(TRIM(nome_time_cartola)) = LOWER(TRIM(:nome_time))
            LIMIT 1
        ');
        $stmt->execute([':liga_id' => $ligaId, ':nome_time' => $nomeTime]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Nomes de time (em minúsculas e sem espaços nas pontas) que os
     * participantes ativos da liga informaram na inscrição.
     *
     * Usado pra detectar automaticamente, a partir dos dados reais dos
     * inscritos, qual campo do JSON da liga oficial ("nome" ou
     * "nome_cartola") é o que realmente contém o nome do time — em vez de
     * assumir uma convenção fixa que pode não bater com o que a API do
     * Cartola realmente devolve.
     *
     * @return string[]
     */
    public static function nomesTimeCartolaPorLiga(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT nome_time_cartola FROM participantes
            WHERE liga_id = :liga_id
              AND ativo = 1
              AND nome_time_cartola IS NOT NULL
              AND nome_time_cartola != \'\'
        ');
        $stmt->execute([':liga_id' => $ligaId]);

        $nomes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $nome) {
            $nomes[] = mb_strtolower(trim((string) $nome));
        }

        return $nomes;
    }

    /** Participantes da liga ainda sem vínculo com a API (usado no dropdown de vinculação manual). */
    public static function naoVinculadosPorLiga(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT * FROM participantes
            WHERE liga_id = :liga_id AND ativo = 1 AND vinculado_api = 0
            ORDER BY nome
        ');
        $stmt->execute([':liga_id' => $ligaId]);

        return $stmt->fetchAll();
    }

    public static function contadores(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT
                COUNT(*) AS total,
                SUM(pago = 1) AS pagos,
                SUM(pago = 0) AS pendentes,
                SUM(vinculado_api = 1) AS vinculados
            FROM participantes
            WHERE liga_id = ? AND ativo = 1
        ');
        $stmt->execute([$ligaId]);

        return $stmt->fetch() ?: ['total' => 0, 'pagos' => 0, 'pendentes' => 0, 'vinculados' => 0];
    }
}
