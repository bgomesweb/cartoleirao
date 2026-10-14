-- Cartoleirão — clean schema (English), used for fresh installs (Docker
-- local dev via docker-compose.yml's docker-entrypoint-initdb.d, or any new
-- deployment going forward).
--
-- For an EXISTING production database (Portuguese-named, see schema.sql),
-- do NOT run this file directly — use
-- database/migrations/001_rename_schema_to_english.sql instead, which
-- transforms the live database in place without losing data.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- admins
-- ============================================================
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- leagues
-- ============================================================
CREATE TABLE IF NOT EXISTS leagues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NULL,
    description VARCHAR(255) NULL,
    type ENUM('classic', 'round_robin') NOT NULL DEFAULT 'classic',
    cartola_league_slug VARCHAR(150) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_leagues_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- league_settings (1:1 with leagues — prizes and registration status)
-- ============================================================
CREATE TABLE IF NOT EXISTS league_settings (
    league_id INT NOT NULL PRIMARY KEY,
    registrations_open TINYINT(1) NOT NULL DEFAULT 0,
    registration_fee DECIMAL(10,2) NULL,
    prize_1 DECIMAL(10,2) NULL,
    prize_2 DECIMAL(10,2) NULL,
    prize_3 DECIMAL(10,2) NULL,
    prize_4 DECIMAL(10,2) NULL,
    prize_5 DECIMAL(10,2) NULL,
    prize_6 DECIMAL(10,2) NULL,
    prize_7 DECIMAL(10,2) NULL,
    prize_8 DECIMAL(10,2) NULL,
    prize_description VARCHAR(500) NULL,
    FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- registrants (sign-up/payment — NOT the ranking, see README)
-- ============================================================
CREATE TABLE IF NOT EXISTS registrants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    league_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    whatsapp VARCHAR(20) NOT NULL,
    cartola_team_name VARCHAR(150) NULL,
    cartola_team_id BIGINT NULL,
    cartola_team_slug VARCHAR(150) NULL,
    linked_to_api TINYINT(1) NOT NULL DEFAULT 0,
    paid TINYINT(1) NOT NULL DEFAULT 0,
    paid_at DATETIME NULL,
    notes VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
    UNIQUE KEY uq_registrants_league_email (league_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- clubs (local cache of Brasileirão clubs, id = Cartola API id)
-- ============================================================
CREATE TABLE IF NOT EXISTS clubs (
    id INT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    trade_name VARCHAR(150) NULL,
    abbreviation VARCHAR(10) NULL,
    crest_url VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- rounds
-- ============================================================
CREATE TABLE IF NOT EXISTS rounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number INT NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
    UNIQUE KEY uq_rounds_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- games
-- ============================================================
CREATE TABLE IF NOT EXISTS games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cartola_game_id BIGINT NULL,
    round_id INT NOT NULL,
    home_club_id INT NOT NULL,
    away_club_id INT NOT NULL,
    home_score INT NULL,
    away_score INT NULL,
    venue VARCHAR(150) NULL,
    played_at DATETIME NULL,
    FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
    FOREIGN KEY (home_club_id) REFERENCES clubs(id),
    FOREIGN KEY (away_club_id) REFERENCES clubs(id),
    UNIQUE KEY uq_games_round_matchup (round_id, home_club_id, away_club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- official_league_teams (real roster of the official Cartola league)
-- Note: team_name really does hold the TEAM's name (not the owner's — see
-- README "non-obvious detail"); owner_name holds the manager's name.
-- ============================================================
CREATE TABLE IF NOT EXISTS official_league_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    league_id INT NOT NULL,
    cartola_team_id BIGINT NOT NULL,
    team_name VARCHAR(150) NULL,
    owner_name VARCHAR(150) NULL,
    is_subscriber TINYINT(1) NOT NULL DEFAULT 0,
    slug VARCHAR(150) NULL,
    crest_url VARCHAR(255) NULL,
    registrant_id INT NULL,
    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    standing_position INT NULL,
    standing_change INT NULL,
    standing_points INT NULL,
    standing_wins INT NULL,
    standing_draws INT NULL,
    standing_losses INT NULL,
    standing_cartola_points DECIMAL(10,4) NULL,
    FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
    FOREIGN KEY (registrant_id) REFERENCES registrants(id) ON DELETE SET NULL,
    UNIQUE KEY uq_league_team (league_id, cartola_team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- scores (score per OFFICIAL LEAGUE TEAM, not per registrant)
-- ============================================================
CREATE TABLE IF NOT EXISTS scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    official_league_team_id INT NOT NULL,
    round_id INT NOT NULL,
    points DECIMAL(7,2) NOT NULL DEFAULT 0,
    budget DECIMAL(12,2) NULL,
    source ENUM('api', 'manual') NOT NULL DEFAULT 'api',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (official_league_team_id) REFERENCES official_league_teams(id) ON DELETE CASCADE,
    FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
    UNIQUE KEY uq_team_round (official_league_team_id, round_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- cartola_auth_token (single row — cached manually-pasted OIDC token)
-- ============================================================
CREATE TABLE IF NOT EXISTS cartola_auth_token (
    id TINYINT PRIMARY KEY DEFAULT 1,
    token TEXT NULL,
    authenticated_at DATETIME NULL,
    expires_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO cartola_auth_token (id, token, authenticated_at, expires_at)
    VALUES (1, NULL, NULL, NULL)
    ON DUPLICATE KEY UPDATE id = id;

-- ============================================================
-- market_status_cache (single row — local cache of /mercado/status)
-- ============================================================
CREATE TABLE IF NOT EXISTS market_status_cache (
    id TINYINT PRIMARY KEY DEFAULT 1,
    status_code INT NULL,
    current_round INT NULL,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO market_status_cache (id, status_code, current_round, updated_at)
    VALUES (1, NULL, NULL, NULL)
    ON DUPLICATE KEY UPDATE id = id;

-- ============================================================
-- bonus_rounds (extra prize per round, configurable per league)
-- ============================================================
CREATE TABLE IF NOT EXISTS bonus_rounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    league_id INT NOT NULL,
    round_number INT NOT NULL,
    prize_amount DECIMAL(10,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE,
    UNIQUE KEY uq_league_bonus_round (league_id, round_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- sync_log (execution history of the sync cron jobs)
-- ============================================================
CREATE TABLE IF NOT EXISTS sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    round_number INT NULL,
    success TINYINT(1) NOT NULL,
    message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
