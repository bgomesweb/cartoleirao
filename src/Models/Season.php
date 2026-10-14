<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

/**
 * A season (year) of the Cartoleirão. Exactly one is active at a time and it
 * drives the public site, the admin lists and the sync crons. Rounds,
 * registrants, ranking teams and bonus rounds belong to a season, so what
 * was scored in past years stays stored as history.
 */
class Season
{
    private static ?int $activeId = null;

    public static function all(): array
    {
        return Connection::get()->query('SELECT * FROM seasons ORDER BY year DESC')->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM seasons WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public static function active(): ?array
    {
        $row = Connection::get()->query('SELECT * FROM seasons WHERE is_active = 1 LIMIT 1')->fetch();

        return $row ?: null;
    }

    /** Id of the active season (cached for the request). */
    public static function activeId(): int
    {
        if (self::$activeId === null) {
            $active = self::active();
            if (!$active) {
                throw new \RuntimeException('Nenhuma temporada ativa. Ative uma temporada no painel admin.');
            }
            self::$activeId = (int) $active['id'];
        }

        return self::$activeId;
    }

    /**
     * @throws \InvalidArgumentException if the year is invalid or already exists
     */
    public static function create(int $year, ?string $registrationDeadline): int
    {
        if ($year < 2020 || $year > 2100) {
            throw new \InvalidArgumentException('Informe um ano válido.');
        }

        $pdo = Connection::get();
        $exists = $pdo->prepare('SELECT 1 FROM seasons WHERE year = ?');
        $exists->execute([$year]);
        if ($exists->fetchColumn()) {
            throw new \InvalidArgumentException("A temporada {$year} já existe.");
        }

        $stmt = $pdo->prepare('INSERT INTO seasons (year, registration_deadline) VALUES (?, ?)');
        $stmt->execute([$year, self::normalizeDeadline($registrationDeadline)]);

        return (int) $pdo->lastInsertId();
    }

    public static function setDeadline(int $id, ?string $registrationDeadline): void
    {
        $stmt = Connection::get()->prepare('UPDATE seasons SET registration_deadline = ? WHERE id = ?');
        $stmt->execute([self::normalizeDeadline($registrationDeadline), $id]);
    }

    /**
     * Makes this season the active one and opens the registrations of every
     * league, which starts the sign-up flow of the new year. Nothing from
     * the other seasons is touched.
     */
    public static function activate(int $id): void
    {
        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE seasons SET is_active = 0');
            $stmt = $pdo->prepare('UPDATE seasons SET is_active = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->exec('UPDATE league_settings SET registrations_open = 1');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::$activeId = $id;
    }

    public static function deadlinePassed(?array $season = null): bool
    {
        $season = $season ?? self::active();
        if (!$season || empty($season['registration_deadline'])) {
            return false;
        }

        $deadline = new \DateTimeImmutable($season['registration_deadline'], new \DateTimeZone('America/Sao_Paulo'));

        return new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')) > $deadline;
    }

    /**
     * True while the sign-up flow is running: at least one league has the
     * "registrations open" flag on and the active season's deadline hasn't
     * passed. In this mode the public site only shows the sign-up page.
     */
    public static function registrationsOpen(): bool
    {
        if (self::deadlinePassed()) {
            return false;
        }

        return (bool) Connection::get()
            ->query('SELECT 1 FROM league_settings WHERE registrations_open = 1 LIMIT 1')
            ->fetchColumn();
    }

    /** Sign-up page while it's open, home page otherwise: use at the top of the public pages. */
    public static function guardPublicPage(bool $isRegistrationPage): void
    {
        $open = self::registrationsOpen();
        if ($open && !$isRegistrationPage) {
            redirect('inscricao.php');
        }
        if (!$open && $isRegistrationPage) {
            redirect('index.php');
        }
    }

    /** Totals shown on the admin season list. */
    public static function summary(int $id): array
    {
        $pdo = Connection::get();
        $one = static function (string $sql) use ($pdo, $id): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);

            return (int) $stmt->fetchColumn();
        };

        return [
            'registrants' => $one('SELECT COUNT(*) FROM registrants WHERE season_id = ? AND active = 1'),
            'teams' => $one('SELECT COUNT(*) FROM official_league_teams WHERE season_id = ?'),
            'rounds_scored' => $one('
                SELECT COUNT(DISTINCT sc.round_id) FROM scores sc
                JOIN rounds r ON r.id = sc.round_id WHERE r.season_id = ?
            '),
        ];
    }

    private static function normalizeDeadline(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);
        if ($time === false) {
            throw new \InvalidArgumentException('Prazo de inscrição inválido.');
        }

        return date('Y-m-d H:i:s', $time);
    }
}
