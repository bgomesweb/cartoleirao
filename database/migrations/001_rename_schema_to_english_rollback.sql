-- Rollback da migração 001: desfaz a renomeação de tabelas/colunas para
-- inglês, voltando ao schema em português (database/schema.sql).
--
-- Use isso só se o corte de produção precisar ser revertido. Depois de
-- rodar, o código antigo (pré-refatoração) volta a funcionar — o código novo
-- (inglês) para de funcionar. Se algo além do schema já mudou, prefira
-- restaurar o backup feito antes da migração 001.

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1) Reverter valores de dados tipo-enum
-- ============================================================

ALTER TABLE rounds
    MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'scheduled';
UPDATE rounds SET status = 'agendada' WHERE status = 'scheduled';
UPDATE rounds SET status = 'aberta' WHERE status = 'open';
UPDATE rounds SET status = 'fechada' WHERE status = 'closed';

ALTER TABLE leagues
    MODIFY COLUMN type ENUM('classica', 'pontos_corridos', 'classic', 'round_robin') NOT NULL DEFAULT 'classic';
UPDATE leagues SET type = 'classica' WHERE type = 'classic';
UPDATE leagues SET type = 'pontos_corridos' WHERE type = 'round_robin';
ALTER TABLE leagues
    MODIFY COLUMN type ENUM('classica', 'pontos_corridos') NOT NULL DEFAULT 'classica';

-- ============================================================
-- 2) Reverter nomes de coluna
-- ============================================================

ALTER TABLE sync_log
    CHANGE COLUMN type tipo VARCHAR(50) NOT NULL,
    CHANGE COLUMN round_number rodada_numero INT NULL,
    CHANGE COLUMN success sucesso TINYINT(1) NOT NULL,
    CHANGE COLUMN message mensagem VARCHAR(500) NULL,
    CHANGE COLUMN created_at criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE bonus_rounds
    CHANGE COLUMN league_id liga_id INT NOT NULL,
    CHANGE COLUMN round_number rodada_numero INT NOT NULL,
    CHANGE COLUMN prize_amount valor_premio DECIMAL(10,2) NULL,
    DROP INDEX uq_league_bonus_round,
    ADD UNIQUE KEY uq_liga_rodada_bonus (liga_id, rodada_numero);

ALTER TABLE market_status_cache
    CHANGE COLUMN status_code status_mercado INT NULL,
    CHANGE COLUMN current_round rodada_atual INT NULL,
    CHANGE COLUMN updated_at atualizado_em DATETIME NULL;

ALTER TABLE cartola_auth_token
    CHANGE COLUMN authenticated_at autenticado_em DATETIME NULL,
    CHANGE COLUMN expires_at expira_em DATETIME NULL;

ALTER TABLE scores
    CHANGE COLUMN official_league_team_id time_liga_oficial_id INT NOT NULL,
    CHANGE COLUMN round_id rodada_id INT NOT NULL,
    CHANGE COLUMN points pontos DECIMAL(7,2) NOT NULL DEFAULT 0,
    CHANGE COLUMN budget patrimonio DECIMAL(12,2) NULL,
    CHANGE COLUMN source origem ENUM('api', 'manual') NOT NULL DEFAULT 'api',
    CHANGE COLUMN updated_at atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    DROP INDEX uq_team_round,
    ADD UNIQUE KEY uq_time_rodada (time_liga_oficial_id, rodada_id);

ALTER TABLE official_league_teams
    CHANGE COLUMN league_id liga_id INT NOT NULL,
    CHANGE COLUMN cartola_team_id cartola_time_id BIGINT NOT NULL,
    CHANGE COLUMN team_name nome_cartola VARCHAR(150) NULL,
    CHANGE COLUMN owner_name nome_dono VARCHAR(150) NULL,
    CHANGE COLUMN is_subscriber assinante TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN crest_url escudo_url VARCHAR(255) NULL,
    CHANGE COLUMN registrant_id participante_id INT NULL,
    CHANGE COLUMN synced_at sincronizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHANGE COLUMN standing_position pc_posicao INT NULL,
    CHANGE COLUMN standing_change pc_variacao INT NULL,
    CHANGE COLUMN standing_points pc_pontos INT NULL,
    CHANGE COLUMN standing_wins pc_vitorias INT NULL,
    CHANGE COLUMN standing_draws pc_empates INT NULL,
    CHANGE COLUMN standing_losses pc_derrotas INT NULL,
    CHANGE COLUMN standing_cartola_points pc_pontos_cartola DECIMAL(10,4) NULL,
    DROP INDEX uq_league_team,
    ADD UNIQUE KEY uq_liga_time (liga_id, cartola_time_id);

ALTER TABLE games
    CHANGE COLUMN cartola_game_id cartola_partida_id BIGINT NULL,
    CHANGE COLUMN round_id rodada_id INT NOT NULL,
    CHANGE COLUMN home_club_id clube_casa_id INT NOT NULL,
    CHANGE COLUMN away_club_id clube_visitante_id INT NOT NULL,
    CHANGE COLUMN home_score placar_casa INT NULL,
    CHANGE COLUMN away_score placar_visitante INT NULL,
    CHANGE COLUMN venue local VARCHAR(150) NULL,
    CHANGE COLUMN played_at partida_data DATETIME NULL,
    DROP INDEX uq_games_round_matchup,
    ADD UNIQUE KEY uq_jogos_rodada_confronto (rodada_id, clube_casa_id, clube_visitante_id);

ALTER TABLE rounds
    CHANGE COLUMN number numero INT NOT NULL,
    CHANGE COLUMN starts_at inicio DATETIME NULL,
    CHANGE COLUMN ends_at fim DATETIME NULL,
    CHANGE COLUMN status status VARCHAR(20) NOT NULL DEFAULT 'agendada',
    DROP INDEX uq_rounds_number,
    ADD UNIQUE KEY uq_rodadas_numero (numero);

ALTER TABLE clubs
    CHANGE COLUMN name nome VARCHAR(150) NOT NULL,
    CHANGE COLUMN trade_name nome_fantasia VARCHAR(150) NULL,
    CHANGE COLUMN abbreviation abreviacao VARCHAR(10) NULL,
    CHANGE COLUMN crest_url escudo_url VARCHAR(255) NULL;

ALTER TABLE registrants
    CHANGE COLUMN league_id liga_id INT NOT NULL,
    CHANGE COLUMN name nome VARCHAR(150) NOT NULL,
    CHANGE COLUMN cartola_team_name nome_time_cartola VARCHAR(150) NULL,
    CHANGE COLUMN cartola_team_id cartola_time_id BIGINT NULL,
    CHANGE COLUMN cartola_team_slug cartola_time_slug VARCHAR(150) NULL,
    CHANGE COLUMN linked_to_api vinculado_api TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN paid pago TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN active ativo TINYINT(1) NOT NULL DEFAULT 1,
    DROP INDEX uq_registrants_league_email,
    ADD UNIQUE KEY uq_participantes_liga_email (liga_id, email);

ALTER TABLE league_settings
    CHANGE COLUMN league_id liga_id INT NOT NULL,
    CHANGE COLUMN registrations_open inscricoes_abertas TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN registration_fee valor_inscricao DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_1 premio_1 DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_2 premio_2 DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_3 premio_3 DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_4 premio_4 DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_5 premio_5 DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_6 premio_6 DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_7 premio_7 DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_8 premio_8 DECIMAL(10,2) NULL,
    CHANGE COLUMN prize_description premiacao_texto VARCHAR(500) NULL;

ALTER TABLE leagues
    CHANGE COLUMN name nome VARCHAR(150) NOT NULL,
    CHANGE COLUMN description descricao VARCHAR(255) NULL,
    CHANGE COLUMN type tipo ENUM('classica', 'pontos_corridos') NOT NULL DEFAULT 'classica',
    CHANGE COLUMN cartola_league_slug cartola_liga_slug VARCHAR(150) NULL,
    CHANGE COLUMN is_active ativa TINYINT(1) NOT NULL DEFAULT 1,
    CHANGE COLUMN is_default eh_padrao TINYINT(1) NOT NULL DEFAULT 0,
    DROP INDEX uq_leagues_slug,
    ADD UNIQUE KEY uq_ligas_slug (slug);

ALTER TABLE admins
    CHANGE COLUMN username usuario VARCHAR(100) NOT NULL,
    CHANGE COLUMN password_hash senha_hash VARCHAR(255) NOT NULL,
    DROP INDEX uq_admins_username,
    ADD UNIQUE KEY uq_admins_usuario (usuario);

-- ============================================================
-- 3) Reverter nomes de tabela
-- ============================================================
RENAME TABLE
    leagues TO ligas,
    league_settings TO liga_config,
    registrants TO participantes,
    clubs TO clubes,
    rounds TO rodadas,
    games TO jogos,
    official_league_teams TO times_liga_oficial,
    scores TO pontuacoes,
    cartola_auth_token TO cartola_auth,
    market_status_cache TO mercado_status_cache,
    bonus_rounds TO rodadas_bonus;

-- admins e sync_log nunca mudaram de nome de tabela.

SET FOREIGN_KEY_CHECKS = 1;
