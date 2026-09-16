-- Migração 001: renomeia o schema de produção (português) para o novo
-- schema em inglês (database/schema_en.sql), SEM apagar dados.
--
-- ⚠️ LEIA ANTES DE RODAR EM PRODUÇÃO:
--   1. Faça um backup completo: mysqldump -u ... -p ... <banco> > backup_antes_da_migracao.sql
--   2. Pause os cron jobs no cPanel (Cron Jobs) enquanto roda esta migração.
--   3. Rode este arquivo inteiro de uma vez (ex: mysql -u ... -p ... <banco> < 001_rename_schema_to_english.sql)
--      — não execute pedaço por pedaço manualmente, a ordem importa.
--   4. Faça o deploy do código novo (que já espera os nomes em inglês) NA
--      MESMA janela — não existe estado "meio migrado" seguro.
--   5. Reative os cron jobs só depois de confirmar que o site voltou a
--      funcionar com o código novo.
--
-- Usa CHANGE COLUMN (não RENAME COLUMN) de propósito: RENAME COLUMN só
-- existe a partir do MySQL 8.0.3 / MariaDB 10.5.2, e hospedagem
-- compartilhada às vezes roda versões mais antigas.
--
-- Se algo der errado no meio do caminho, use
-- 001_rename_schema_to_english_rollback.sql (com o backup do passo 1 como
-- rede de segurança final).

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1) Renomear as 13 tabelas (uma única instrução, atômica)
-- ============================================================
RENAME TABLE
    admins TO admins_tmp_noop, -- admins não muda de nome; renomeação "no-op" só pra manter o padrão do bloco abaixo
    ligas TO leagues,
    liga_config TO league_settings,
    participantes TO registrants,
    clubes TO clubs,
    rodadas TO rounds,
    jogos TO games,
    times_liga_oficial TO official_league_teams,
    pontuacoes TO scores,
    cartola_auth TO cartola_auth_token,
    mercado_status_cache TO market_status_cache,
    rodadas_bonus TO bonus_rounds,
    sync_log TO sync_log_tmp_noop; -- sync_log não muda de nome, mesma lógica

RENAME TABLE admins_tmp_noop TO admins;
RENAME TABLE sync_log_tmp_noop TO sync_log;

-- ============================================================
-- 2) Renomear colunas (CHANGE COLUMN repete o tipo original)
-- ============================================================

ALTER TABLE admins
    CHANGE COLUMN usuario username VARCHAR(100) NOT NULL,
    CHANGE COLUMN senha_hash password_hash VARCHAR(255) NOT NULL,
    DROP INDEX uq_admins_usuario,
    ADD UNIQUE KEY uq_admins_username (username);

ALTER TABLE leagues
    CHANGE COLUMN nome name VARCHAR(150) NOT NULL,
    CHANGE COLUMN descricao description VARCHAR(255) NULL,
    CHANGE COLUMN tipo type ENUM('classica', 'pontos_corridos') NOT NULL DEFAULT 'classica',
    CHANGE COLUMN cartola_liga_slug cartola_league_slug VARCHAR(150) NULL,
    CHANGE COLUMN ativa is_active TINYINT(1) NOT NULL DEFAULT 1,
    CHANGE COLUMN eh_padrao is_default TINYINT(1) NOT NULL DEFAULT 0,
    DROP INDEX uq_ligas_slug,
    ADD UNIQUE KEY uq_leagues_slug (slug);

-- valores do enum `type` traduzidos depois de todas as tabelas renomeadas (passo 3)
ALTER TABLE leagues
    MODIFY COLUMN type ENUM('classica', 'pontos_corridos', 'classic', 'round_robin') NOT NULL DEFAULT 'classica';

ALTER TABLE league_settings
    CHANGE COLUMN liga_id league_id INT NOT NULL,
    CHANGE COLUMN inscricoes_abertas registrations_open TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN valor_inscricao registration_fee DECIMAL(10,2) NULL,
    CHANGE COLUMN premio_1 prize_1 DECIMAL(10,2) NULL,
    CHANGE COLUMN premio_2 prize_2 DECIMAL(10,2) NULL,
    CHANGE COLUMN premio_3 prize_3 DECIMAL(10,2) NULL,
    CHANGE COLUMN premio_4 prize_4 DECIMAL(10,2) NULL,
    CHANGE COLUMN premio_5 prize_5 DECIMAL(10,2) NULL,
    CHANGE COLUMN premio_6 prize_6 DECIMAL(10,2) NULL,
    CHANGE COLUMN premio_7 prize_7 DECIMAL(10,2) NULL,
    CHANGE COLUMN premio_8 prize_8 DECIMAL(10,2) NULL,
    CHANGE COLUMN premiacao_texto prize_description VARCHAR(500) NULL;

ALTER TABLE registrants
    CHANGE COLUMN liga_id league_id INT NOT NULL,
    CHANGE COLUMN nome name VARCHAR(150) NOT NULL,
    CHANGE COLUMN nome_time_cartola cartola_team_name VARCHAR(150) NULL,
    CHANGE COLUMN cartola_time_id cartola_team_id BIGINT NULL,
    CHANGE COLUMN cartola_time_slug cartola_team_slug VARCHAR(150) NULL,
    CHANGE COLUMN vinculado_api linked_to_api TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN pago paid TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN ativo active TINYINT(1) NOT NULL DEFAULT 1,
    DROP INDEX uq_participantes_liga_email,
    ADD UNIQUE KEY uq_registrants_league_email (league_id, email);

ALTER TABLE clubs
    CHANGE COLUMN nome name VARCHAR(150) NOT NULL,
    CHANGE COLUMN nome_fantasia trade_name VARCHAR(150) NULL,
    CHANGE COLUMN abreviacao abbreviation VARCHAR(10) NULL,
    CHANGE COLUMN escudo_url crest_url VARCHAR(255) NULL;

ALTER TABLE rounds
    CHANGE COLUMN numero number INT NOT NULL,
    CHANGE COLUMN inicio starts_at DATETIME NULL,
    CHANGE COLUMN fim ends_at DATETIME NULL,
    CHANGE COLUMN status status VARCHAR(20) NOT NULL DEFAULT 'agendada',
    DROP INDEX uq_rodadas_numero,
    ADD UNIQUE KEY uq_rounds_number (number);

ALTER TABLE games
    CHANGE COLUMN cartola_partida_id cartola_game_id BIGINT NULL,
    CHANGE COLUMN rodada_id round_id INT NOT NULL,
    CHANGE COLUMN clube_casa_id home_club_id INT NOT NULL,
    CHANGE COLUMN clube_visitante_id away_club_id INT NOT NULL,
    CHANGE COLUMN placar_casa home_score INT NULL,
    CHANGE COLUMN placar_visitante away_score INT NULL,
    CHANGE COLUMN local venue VARCHAR(150) NULL,
    CHANGE COLUMN partida_data played_at DATETIME NULL,
    DROP INDEX uq_jogos_rodada_confronto,
    ADD UNIQUE KEY uq_games_round_matchup (round_id, home_club_id, away_club_id);

ALTER TABLE official_league_teams
    CHANGE COLUMN liga_id league_id INT NOT NULL,
    CHANGE COLUMN cartola_time_id cartola_team_id BIGINT NOT NULL,
    CHANGE COLUMN nome_cartola team_name VARCHAR(150) NULL,
    CHANGE COLUMN nome_dono owner_name VARCHAR(150) NULL,
    CHANGE COLUMN assinante is_subscriber TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN escudo_url crest_url VARCHAR(255) NULL,
    CHANGE COLUMN participante_id registrant_id INT NULL,
    CHANGE COLUMN sincronizado_em synced_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHANGE COLUMN pc_posicao standing_position INT NULL,
    CHANGE COLUMN pc_variacao standing_change INT NULL,
    CHANGE COLUMN pc_pontos standing_points INT NULL,
    CHANGE COLUMN pc_vitorias standing_wins INT NULL,
    CHANGE COLUMN pc_empates standing_draws INT NULL,
    CHANGE COLUMN pc_derrotas standing_losses INT NULL,
    CHANGE COLUMN pc_pontos_cartola standing_cartola_points DECIMAL(10,4) NULL,
    DROP INDEX uq_liga_time,
    ADD UNIQUE KEY uq_league_team (league_id, cartola_team_id);

ALTER TABLE scores
    CHANGE COLUMN time_liga_oficial_id official_league_team_id INT NOT NULL,
    CHANGE COLUMN rodada_id round_id INT NOT NULL,
    CHANGE COLUMN pontos points DECIMAL(7,2) NOT NULL DEFAULT 0,
    CHANGE COLUMN patrimonio budget DECIMAL(12,2) NULL,
    CHANGE COLUMN origem source ENUM('api', 'manual') NOT NULL DEFAULT 'api',
    CHANGE COLUMN atualizado_em updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    DROP INDEX uq_time_rodada,
    ADD UNIQUE KEY uq_team_round (official_league_team_id, round_id);

ALTER TABLE cartola_auth_token
    CHANGE COLUMN autenticado_em authenticated_at DATETIME NULL,
    CHANGE COLUMN expira_em expires_at DATETIME NULL;

ALTER TABLE market_status_cache
    CHANGE COLUMN status_mercado status_code INT NULL,
    CHANGE COLUMN rodada_atual current_round INT NULL,
    CHANGE COLUMN atualizado_em updated_at DATETIME NULL;

ALTER TABLE bonus_rounds
    CHANGE COLUMN liga_id league_id INT NOT NULL,
    CHANGE COLUMN rodada_numero round_number INT NOT NULL,
    CHANGE COLUMN valor_premio prize_amount DECIMAL(10,2) NULL,
    DROP INDEX uq_liga_rodada_bonus,
    ADD UNIQUE KEY uq_league_bonus_round (league_id, round_number);

ALTER TABLE sync_log
    CHANGE COLUMN tipo type VARCHAR(50) NOT NULL,
    CHANGE COLUMN rodada_numero round_number INT NULL,
    CHANGE COLUMN sucesso success TINYINT(1) NOT NULL,
    CHANGE COLUMN mensagem message VARCHAR(500) NULL,
    CHANGE COLUMN criado_em created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ============================================================
-- 3) Traduzir valores de dados tipo-enum (strings em português salvas)
-- ============================================================

UPDATE leagues SET type = 'classic' WHERE type = 'classica';
UPDATE leagues SET type = 'round_robin' WHERE type = 'pontos_corridos';
ALTER TABLE leagues
    MODIFY COLUMN type ENUM('classic', 'round_robin') NOT NULL DEFAULT 'classic';

UPDATE rounds SET status = 'scheduled' WHERE status = 'agendada';
UPDATE rounds SET status = 'open' WHERE status = 'aberta';
UPDATE rounds SET status = 'closed' WHERE status = 'fechada';
ALTER TABLE rounds
    CHANGE COLUMN status status VARCHAR(20) NOT NULL DEFAULT 'scheduled';

SET FOREIGN_KEY_CHECKS = 1;
