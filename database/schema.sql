-- Cartoleirão — schema reconstruído (nomenclatura em português, estado ANTES
-- da refatoração para inglês).
--
-- Este arquivo não existia versionado no repositório (o docker-compose.yml
-- já referenciava `database/schema.sql`, mas o arquivo tinha se perdido).
-- Foi reconstruído cruzando o changelog incremental do README.md com todas
-- as queries SQL reais encontradas em src/Models e src/Services — ou seja,
-- reflete o que o CÓDIGO espera encontrar no banco, não uma cópia exportada
-- do banco de produção real.
--
-- ⚠️ ANTES de aplicar qualquer migração baseada neste arquivo em produção,
-- rode `SHOW CREATE TABLE <tabela>;` para cada uma das 13 tabelas abaixo no
-- banco real (phpMyAdmin/SSH) e reconcilie qualquer diferença — em especial
-- os dois pontos marcados como "NÃO CONFIRMADO" abaixo.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- admins
-- ============================================================
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(100) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_admins_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ligas
-- ============================================================
CREATE TABLE IF NOT EXISTS ligas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NULL,
    descricao VARCHAR(255) NULL,
    tipo ENUM('classica', 'pontos_corridos') NOT NULL DEFAULT 'classica',
    cartola_liga_slug VARCHAR(150) NULL,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    eh_padrao TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_ligas_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- liga_config (1:1 com ligas — premiação e status de inscrição)
-- ============================================================
CREATE TABLE IF NOT EXISTS liga_config (
    liga_id INT NOT NULL PRIMARY KEY,
    inscricoes_abertas TINYINT(1) NOT NULL DEFAULT 0,
    valor_inscricao DECIMAL(10,2) NULL,
    premio_1 DECIMAL(10,2) NULL,
    premio_2 DECIMAL(10,2) NULL,
    premio_3 DECIMAL(10,2) NULL,
    premio_4 DECIMAL(10,2) NULL,
    premio_5 DECIMAL(10,2) NULL,
    premio_6 DECIMAL(10,2) NULL,
    premio_7 DECIMAL(10,2) NULL,
    premio_8 DECIMAL(10,2) NULL,
    premiacao_texto VARCHAR(500) NULL,
    FOREIGN KEY (liga_id) REFERENCES ligas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- participantes (inscrição/pagamento — não é o ranking, ver README)
-- ============================================================
CREATE TABLE IF NOT EXISTS participantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    liga_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    whatsapp VARCHAR(20) NOT NULL,
    nome_time_cartola VARCHAR(150) NULL,
    cartola_time_id BIGINT NULL,
    cartola_time_slug VARCHAR(150) NULL,
    vinculado_api TINYINT(1) NOT NULL DEFAULT 0,
    pago TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (liga_id) REFERENCES ligas(id) ON DELETE CASCADE,
    UNIQUE KEY uq_participantes_liga_email (liga_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- clubes (cache local dos clubes do Brasileirão, id = id da API)
-- ============================================================
CREATE TABLE IF NOT EXISTS clubes (
    id INT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    nome_fantasia VARCHAR(150) NULL,
    abreviacao VARCHAR(10) NULL,
    escudo_url VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- rodadas
-- ============================================================
CREATE TABLE IF NOT EXISTS rodadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero INT NOT NULL,
    inicio DATETIME NULL,
    fim DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'agendada',
    UNIQUE KEY uq_rodadas_numero (numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- jogos
-- ============================================================
CREATE TABLE IF NOT EXISTS jogos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cartola_partida_id BIGINT NULL,
    rodada_id INT NOT NULL,
    clube_casa_id INT NOT NULL,
    clube_visitante_id INT NOT NULL,
    placar_casa INT NULL,
    placar_visitante INT NULL,
    local VARCHAR(150) NULL,
    partida_data DATETIME NULL,
    FOREIGN KEY (rodada_id) REFERENCES rodadas(id) ON DELETE CASCADE,
    FOREIGN KEY (clube_casa_id) REFERENCES clubes(id),
    FOREIGN KEY (clube_visitante_id) REFERENCES clubes(id),
    UNIQUE KEY uq_jogos_rodada_confronto (rodada_id, clube_casa_id, clube_visitante_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- times_liga_oficial (roster real da liga oficial do Cartola)
-- ⚠️ nome_cartola guarda, na prática, o NOME DO TIME (não do dono — ver
-- README, seção "Detalhe não-óbvio"); nome_dono guarda o nome do cartoleiro.
-- ============================================================
CREATE TABLE IF NOT EXISTS times_liga_oficial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    liga_id INT NOT NULL,
    cartola_time_id BIGINT NOT NULL,
    nome_cartola VARCHAR(150) NULL,
    nome_dono VARCHAR(150) NULL,
    assinante TINYINT(1) NOT NULL DEFAULT 0,
    slug VARCHAR(150) NULL,
    escudo_url VARCHAR(255) NULL,
    participante_id INT NULL,
    sincronizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pc_posicao INT NULL,
    pc_variacao INT NULL,
    pc_pontos INT NULL,
    pc_vitorias INT NULL,
    pc_empates INT NULL,
    pc_derrotas INT NULL,
    pc_pontos_cartola DECIMAL(10,4) NULL,
    FOREIGN KEY (liga_id) REFERENCES ligas(id) ON DELETE CASCADE,
    FOREIGN KEY (participante_id) REFERENCES participantes(id) ON DELETE SET NULL,
    UNIQUE KEY uq_liga_time (liga_id, cartola_time_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- pontuacoes (pontuação por TIME DA LIGA OFICIAL, não por participante)
-- ============================================================
CREATE TABLE IF NOT EXISTS pontuacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    time_liga_oficial_id INT NOT NULL,
    rodada_id INT NOT NULL,
    pontos DECIMAL(7,2) NOT NULL DEFAULT 0,
    patrimonio DECIMAL(12,2) NULL,
    origem ENUM('api', 'manual') NOT NULL DEFAULT 'api',
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (time_liga_oficial_id) REFERENCES times_liga_oficial(id) ON DELETE CASCADE,
    FOREIGN KEY (rodada_id) REFERENCES rodadas(id) ON DELETE CASCADE,
    UNIQUE KEY uq_time_rodada (time_liga_oficial_id, rodada_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- cartola_auth (linha única — cache do token OIDC colado manualmente)
-- ⚠️ NÃO CONFIRMADO: a coluna expira_em é usada em todo CartolaAuthService
-- mas não tem nenhum CREATE/ALTER documentado no changelog do README — foi
-- reconstruída por inferência do código. Confirmar contra o banco real.
-- ============================================================
CREATE TABLE IF NOT EXISTS cartola_auth (
    id TINYINT PRIMARY KEY DEFAULT 1,
    token TEXT NULL,
    autenticado_em DATETIME NULL,
    expira_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO cartola_auth (id, token, autenticado_em, expira_em)
    VALUES (1, NULL, NULL, NULL)
    ON DUPLICATE KEY UPDATE id = id;

-- ============================================================
-- mercado_status_cache (linha única — cache local de /mercado/status)
-- ============================================================
CREATE TABLE IF NOT EXISTS mercado_status_cache (
    id TINYINT PRIMARY KEY DEFAULT 1,
    status_mercado INT NULL,
    rodada_atual INT NULL,
    atualizado_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mercado_status_cache (id, status_mercado, rodada_atual, atualizado_em)
    VALUES (1, NULL, NULL, NULL)
    ON DUPLICATE KEY UPDATE id = id;

-- ============================================================
-- rodadas_bonus (premiação extra por rodada, configurável por liga)
-- ============================================================
CREATE TABLE IF NOT EXISTS rodadas_bonus (
    id INT AUTO_INCREMENT PRIMARY KEY,
    liga_id INT NOT NULL,
    rodada_numero INT NOT NULL,
    valor_premio DECIMAL(10,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (liga_id) REFERENCES ligas(id) ON DELETE CASCADE,
    UNIQUE KEY uq_liga_rodada_bonus (liga_id, rodada_numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- sync_log (histórico de execuções dos crons de sincronização)
-- ⚠️ NÃO CONFIRMADO: não há nenhum CREATE TABLE documentado no README para
-- esta tabela — só o INSERT em SincronizacaoService::log(). Reconstruída por
-- inferência (colunas tipo/rodada_numero/sucesso/mensagem são as únicas
-- usadas pelo código; id e criado_em são estrutura mínima assumida).
-- Confirmar contra o banco real.
-- ============================================================
CREATE TABLE IF NOT EXISTS sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    rodada_numero INT NULL,
    sucesso TINYINT(1) NOT NULL,
    mensagem VARCHAR(500) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
