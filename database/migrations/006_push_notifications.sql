-- Notificações push de gol: aparelhos inscritos + estado da detecção de gols.

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(64) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_push_endpoint (endpoint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Último placar visto de cada jogo da rodada (base para detectar gols novos).
CREATE TABLE IF NOT EXISTS goal_game_state (
    round_number INT NOT NULL,
    cartola_game_id BIGINT NOT NULL,
    home_score INT NOT NULL DEFAULT 0,
    away_score INT NOT NULL DEFAULT 0,
    PRIMARY KEY (round_number, cartola_game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gols de cada atleta já atribuídos a um aviso (para descobrir o autor).
CREATE TABLE IF NOT EXISTS goal_athlete_state (
    round_number INT NOT NULL,
    athlete_id BIGINT NOT NULL,
    goals INT NOT NULL DEFAULT 0,
    own_goals INT NOT NULL DEFAULT 0,
    PRIMARY KEY (round_number, athlete_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gols detectados; o aviso espera o autor aparecer (a API de atletas atrasa alguns minutos).
CREATE TABLE IF NOT EXISTS goal_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    round_number INT NOT NULL,
    cartola_game_id BIGINT NOT NULL,
    home_club_id INT NOT NULL,
    away_club_id INT NOT NULL,
    home_score INT NOT NULL,
    away_score INT NOT NULL,
    scoring_club_id INT NOT NULL,
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    KEY idx_goal_events_pending (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
