-- Temporadas: cada ano tem suas próprias rodadas, inscritos, times do ranking
-- e rodadas bônus. As pontuações (scores) herdam a temporada pela rodada e
-- pelo time, então o histórico por rodada e por ano fica guardado para sempre.
-- Os dados que já existem (inscrições de 2027) são atribuídos à temporada 2027,
-- que fica ativa.

CREATE TABLE IF NOT EXISTS seasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year SMALLINT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    registration_deadline DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_seasons_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO seasons (year, is_active, registration_deadline)
VALUES (2027, 1, '2027-01-26 23:59:59');

SET @season := (SELECT id FROM seasons WHERE year = 2027);

-- rounds: o número da rodada passa a ser único por temporada
ALTER TABLE rounds ADD COLUMN season_id INT NULL AFTER id;
UPDATE rounds SET season_id = @season;
ALTER TABLE rounds
    MODIFY season_id INT NOT NULL,
    ADD CONSTRAINT fk_rounds_season FOREIGN KEY (season_id) REFERENCES seasons(id),
    ADD UNIQUE KEY uq_rounds_season_number (season_id, number),
    DROP INDEX uq_rounds_number;

-- registrants: o mesmo e-mail pode se inscrever de novo a cada temporada.
-- O índice novo começa por league_id, então já atende a FK da liga.
ALTER TABLE registrants ADD COLUMN season_id INT NULL AFTER league_id;
UPDATE registrants SET season_id = @season;
ALTER TABLE registrants
    MODIFY season_id INT NOT NULL,
    ADD CONSTRAINT fk_registrants_season FOREIGN KEY (season_id) REFERENCES seasons(id),
    ADD UNIQUE KEY uq_registrants_league_season_email (league_id, season_id, email);
ALTER TABLE registrants DROP INDEX uq_registrants_league_email;

-- official_league_teams: o mesmo time do Cartola entra de novo a cada temporada
ALTER TABLE official_league_teams ADD COLUMN season_id INT NULL AFTER league_id;
UPDATE official_league_teams SET season_id = @season;
ALTER TABLE official_league_teams
    MODIFY season_id INT NOT NULL,
    ADD CONSTRAINT fk_olt_season FOREIGN KEY (season_id) REFERENCES seasons(id),
    ADD UNIQUE KEY uq_league_season_team (league_id, season_id, cartola_team_id);
ALTER TABLE official_league_teams DROP INDEX uq_league_team;

-- bonus_rounds: as rodadas bônus são definidas por temporada
ALTER TABLE bonus_rounds ADD COLUMN season_id INT NULL AFTER league_id;
UPDATE bonus_rounds SET season_id = @season;
ALTER TABLE bonus_rounds
    MODIFY season_id INT NOT NULL,
    ADD CONSTRAINT fk_bonus_rounds_season FOREIGN KEY (season_id) REFERENCES seasons(id),
    ADD UNIQUE KEY uq_league_season_bonus_round (league_id, season_id, round_number);
ALTER TABLE bonus_rounds DROP INDEX uq_league_bonus_round;
