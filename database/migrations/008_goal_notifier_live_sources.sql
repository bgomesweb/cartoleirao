-- Notificações de gol passam a usar o placar do ge.globo.com (e o autor, do placar da UOL):
-- guarda se o jogo já terminou (aviso de "Fim de jogo", kind = 'end' em goal_events).
-- (Jogos que terminaram há mais de ~3h15 nunca geram aviso de fim, então a migração não causa rajada.)
ALTER TABLE goal_game_state
    ADD COLUMN finished TINYINT(1) NOT NULL DEFAULT 0;

-- Tabela da detecção antiga (scouts da API do Cartola); não é mais usada.
DROP TABLE IF EXISTS goal_athlete_state;
