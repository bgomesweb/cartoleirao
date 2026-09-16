<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

/**
 * Rodadas "bônus": rodadas marcadas pelo organizador com premiação extra
 * pro time que fechar em 1º lugar NAQUELA rodada específica (além da
 * premiação geral da liga). Ex: rodadas 10, 19, 26 e 37 do Brasileirão.
 *
 * A configuração é por liga (liga_id), já que cada liga tem sua própria
 * premiação — a mesma rodada pode ser bônus numa liga e não na outra.
 */
class RodadaBonus
{
    /** Todas as rodadas bônus cadastradas, com o nome da liga (pro admin). */
    public static function todasComLiga(): array
    {
        $pdo = Connection::get();

        return $pdo->query('
            SELECT rb.*, l.nome AS liga_nome
            FROM rodadas_bonus rb
            JOIN ligas l ON l.id = rb.liga_id
            ORDER BY l.nome ASC, rb.rodada_numero ASC
        ')->fetchAll();
    }

    public static function listarPorLiga(int $ligaId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM rodadas_bonus WHERE liga_id = ? ORDER BY rodada_numero ASC');
        $stmt->execute([$ligaId]);

        return $stmt->fetchAll();
    }

    public static function porId(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM rodadas_bonus WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Retorna a config de rodada bônus pra essa liga/rodada, ou null se a rodada não é bônus. */
    public static function ehBonus(int $ligaId, int $rodadaNumero): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM rodadas_bonus WHERE liga_id = ? AND rodada_numero = ?');
        $stmt->execute([$ligaId, $rodadaNumero]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Cadastra (ou atualiza o valor de) uma ou mais rodadas de uma vez —
     * usado no admin, que aceita uma lista tipo "10,19,26,37" com um valor
     * de premiação aplicado a todas de uma vez.
     *
     * @param int[] $numerosRodada
     */
    public static function salvarLote(int $ligaId, array $numerosRodada, ?float $valorPremio): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO rodadas_bonus (liga_id, rodada_numero, valor_premio)
            VALUES (:liga_id, :rodada_numero, :valor_premio)
            ON DUPLICATE KEY UPDATE valor_premio = VALUES(valor_premio)
        ');

        foreach ($numerosRodada as $numero) {
            $stmt->execute([
                ':liga_id' => $ligaId,
                ':rodada_numero' => $numero,
                ':valor_premio' => $valorPremio,
            ]);
        }
    }

    public static function atualizarValor(int $id, ?float $valorPremio): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE rodadas_bonus SET valor_premio = ? WHERE id = ?');
        $stmt->execute([$valorPremio, $id]);
    }

    public static function remover(int $id): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('DELETE FROM rodadas_bonus WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Rodadas bônus que batem com a rodada atual do mercado (usado pra
     * decidir se mostra o modal ao abrir o site). Pode haver mais de uma
     * liga com a mesma rodada marcada como bônus — nesse caso todas
     * aparecem no modal.
     */
    public static function paraRodadaAtual(?int $rodadaAtual): array
    {
        if (!$rodadaAtual) {
            return [];
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT rb.*, l.nome AS liga_nome
            FROM rodadas_bonus rb
            JOIN ligas l ON l.id = rb.liga_id
            WHERE rb.rodada_numero = ? AND l.ativa = 1
            ORDER BY l.eh_padrao DESC, l.nome ASC
        ');
        $stmt->execute([$rodadaAtual]);

        return $stmt->fetchAll();
    }
}
