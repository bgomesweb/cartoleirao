<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;
use PDOException;

class Registrant
{
    /**
     * @throws \InvalidArgumentException if the e-mail is already registered in the league
     */
    public static function create(array $data): int
    {
        $pdo = Connection::get();

        $stmt = $pdo->prepare('
            INSERT INTO registrants
                (league_id, name, email, whatsapp, cartola_team_name, cartola_team_id, cartola_team_slug, linked_to_api, paid)
            VALUES
                (:league_id, :name, :email, :whatsapp, :cartola_team_name, :cartola_team_id, :cartola_team_slug, :linked_to_api, 0)
        ');

        try {
            $stmt->execute([
                ':league_id' => $data['league_id'],
                ':name' => $data['name'],
                ':email' => $data['email'],
                ':whatsapp' => $data['whatsapp'],
                ':cartola_team_name' => ($data['cartola_team_name'] ?? null) ?: null,
                ':cartola_team_id' => $data['cartola_team_id'] ?? null,
                ':cartola_team_slug' => $data['cartola_team_slug'] ?? null,
                ':linked_to_api' => !empty($data['cartola_team_id']) ? 1 : 0,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \InvalidArgumentException('Este e-mail já está inscrito nesta liga.');
            }
            throw $e;
        }

        return (int) $pdo->lastInsertId();
    }

    public static function findByLeague(int $leagueId, ?string $paidFilter = null): array
    {
        $pdo = Connection::get();
        $sql = 'SELECT * FROM registrants WHERE league_id = ? AND active = 1';
        $params = [$leagueId];

        if ($paidFilter === 'paid') {
            $sql .= ' AND paid = 1';
        } elseif ($paidFilter === 'pending') {
            $sql .= ' AND paid = 0';
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM registrants WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Toggles the paid flag, storing the payment date/time (paid_at) when
     * marking as paid and clearing it when reverting. MySQL evaluates SET
     * assignments left to right, so `paid` in the IF() is already the new value.
     *
     * @return array{paid: bool, paid_at: ?string}
     */
    public static function togglePaid(int $id): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE registrants SET paid = 1 - paid, paid_at = IF(paid = 1, NOW(), NULL) WHERE id = ?');
        $stmt->execute([$id]);

        $stmt = $pdo->prepare('SELECT paid, paid_at FROM registrants WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return ['paid' => (bool) $row['paid'], 'paid_at' => $row['paid_at']];
    }

    public static function updateNotes(int $id, string $notes): void
    {
        $notes = mb_substr(trim($notes), 0, 255);
        $stmt = Connection::get()->prepare('UPDATE registrants SET notes = ? WHERE id = ?');
        $stmt->execute([$notes !== '' ? $notes : null, $id]);
    }

    public static function linkCartolaTeam(int $id, int $teamId, string $teamName, string $teamSlug): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE registrants
            SET cartola_team_id = :team_id,
                cartola_team_slug = :team_slug,
                cartola_team_name = :team_name,
                linked_to_api = 1
            WHERE id = :id
        ');
        $stmt->execute([
            ':team_id' => $teamId,
            ':team_slug' => $teamSlug,
            ':team_name' => $teamName,
            ':id' => $id,
        ]);
    }

    /** Registrant from the league already linked to that cartola_team_id (if any). */
    public static function findByLeagueAndTeamId(int $leagueId, int $cartolaTeamId): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT * FROM registrants
            WHERE league_id = :league_id AND cartola_team_id = :team_id AND active = 1
            LIMIT 1
        ');
        $stmt->execute([':league_id' => $leagueId, ':team_id' => $cartolaTeamId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Registrant from the league still WITHOUT a link whose team name
     * (given at sign-up) matches, ignoring case/accents/spaces, the name
     * coming from the official league. Used to automatically match without
     * duplicating a link already made through another path.
     */
    public static function findUnlinkedByLeagueAndTeamName(int $leagueId, string $teamName): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT * FROM registrants
            WHERE league_id = :league_id
              AND active = 1
              AND linked_to_api = 0
              AND cartola_team_name IS NOT NULL
              AND LOWER(TRIM(cartola_team_name)) = LOWER(TRIM(:team_name))
            LIMIT 1
        ');
        $stmt->execute([':league_id' => $leagueId, ':team_name' => $teamName]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Team names (lowercased, trimmed) that the league's active registrants
     * provided at sign-up.
     *
     * Used to automatically detect, from the registrants' real data, which
     * field of the official-league JSON ("nome" or "nome_cartola") actually
     * holds the team name — instead of assuming a fixed convention that
     * might not match what the Cartola API actually returns.
     *
     * @return string[]
     */
    public static function cartolaTeamNamesByLeague(int $leagueId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT cartola_team_name FROM registrants
            WHERE league_id = :league_id
              AND active = 1
              AND cartola_team_name IS NOT NULL
              AND cartola_team_name != \'\'
        ');
        $stmt->execute([':league_id' => $leagueId]);

        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $names[] = mb_strtolower(trim((string) $name));
        }

        return $names;
    }

    /** League registrants still without an API link (used in the manual-linking dropdown). */
    public static function unlinkedByLeague(int $leagueId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT * FROM registrants
            WHERE league_id = :league_id AND active = 1 AND linked_to_api = 0
            ORDER BY name
        ');
        $stmt->execute([':league_id' => $leagueId]);

        return $stmt->fetchAll();
    }

    public static function counters(int $leagueId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT
                COUNT(*) AS total,
                SUM(paid = 1) AS paid,
                SUM(paid = 0) AS pending,
                SUM(linked_to_api = 1) AS linked
            FROM registrants
            WHERE league_id = ? AND active = 1
        ');
        $stmt->execute([$leagueId]);

        return $stmt->fetch() ?: ['total' => 0, 'paid' => 0, 'pending' => 0, 'linked' => 0];
    }
}
