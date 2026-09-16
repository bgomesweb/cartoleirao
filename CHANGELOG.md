# Changelog

Histórico de migrações incrementais do banco de dados, extraído do
`README.md` na refatoração que traduziu todo o código/schema pra inglês.
Os comandos abaixo são exatamente como foram aplicados na época — usam os
nomes de tabela/coluna em **português** (schema antigo, pré-refatoração) e
não devem ser rodados contra o schema atual (`database/schema_en.sql`).
Preservados aqui só como registro histórico de como o banco evoluiu.

Para reconstruir o schema atual do zero, use `database/schema_en.sql`. Para
migrar um banco antigo (schema em português) pro schema novo (inglês), use
`database/migrations/001_rename_schema_to_english.sql`.

---

## ⚠️ Migração: colunas novas em times_liga_oficial (tabela de pontos corridos)

Adição pura (sem apagar nada), roda direto:

```bash
docker compose exec -T db mysql -u root -proot cartoleirao <<'EOF'
ALTER TABLE times_liga_oficial
    ADD COLUMN nome_dono VARCHAR(150) NULL AFTER nome_cartola,
    ADD COLUMN assinante TINYINT(1) NOT NULL DEFAULT 0 AFTER nome_dono,
    ADD COLUMN pc_posicao INT NULL,
    ADD COLUMN pc_variacao INT NULL,
    ADD COLUMN pc_pontos INT NULL,
    ADD COLUMN pc_vitorias INT NULL,
    ADD COLUMN pc_empates INT NULL,
    ADD COLUMN pc_derrotas INT NULL,
    ADD COLUMN pc_pontos_cartola DECIMAL(10,4) NULL;
EOF
```

> Se der erro `Duplicate column name`, é porque você já rodou isso antes — ignora.

## ⚠️ Migração: pontuacoes agora aponta pra times_liga_oficial, não participantes

Mudança de arquitetura (ranking passou a ser dos times da liga oficial, não
de quem se inscreveu). Como você provavelmente não tem pontuação real ainda
salva (o login pra popular isso nunca funcionou por causa do captcha), o mais
simples é recriar só essa tabela:

```bash
docker compose exec -T db mysql -u root -proot cartoleirao <<'EOF'
DROP TABLE IF EXISTS pontuacoes;

CREATE TABLE pontuacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    time_liga_oficial_id INT NOT NULL,
    rodada_id INT NOT NULL,
    pontos DECIMAL(7,2) NOT NULL DEFAULT 0,
    patrimonio DECIMAL(12,2) NULL,
    origem ENUM('api','manual') NOT NULL DEFAULT 'api',
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (time_liga_oficial_id) REFERENCES times_liga_oficial(id) ON DELETE CASCADE,
    FOREIGN KEY (rodada_id) REFERENCES rodadas(id) ON DELETE CASCADE,
    UNIQUE KEY uq_time_rodada (time_liga_oficial_id, rodada_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF
```

## ⚠️ Migração: se você já tinha o banco rodando antes do módulo de liga oficial

Dessa vez não precisava resetar o volume (dava pra aplicar direto, só com
adições — tabelas novas + uma coluna nova em `ligas`):

```bash
docker compose exec db mysql -u root -proot cartoleirao <<'EOF'
ALTER TABLE ligas ADD COLUMN IF NOT EXISTS cartola_liga_slug VARCHAR(150) NULL;

UPDATE ligas SET cartola_liga_slug = 'cartoleirao-2026' WHERE slug = 'cartoleirao';
UPDATE ligas SET cartola_liga_slug = 'd5j59k2k58ms73fgkfi0' WHERE slug = 'cartoleirao-gloria-eterna';

CREATE TABLE IF NOT EXISTS times_liga_oficial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    liga_id INT NOT NULL,
    cartola_time_id BIGINT NOT NULL,
    nome_cartola VARCHAR(150) NULL,
    slug VARCHAR(150) NULL,
    escudo_url VARCHAR(255) NULL,
    participante_id INT NULL,
    sincronizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (liga_id) REFERENCES ligas(id) ON DELETE CASCADE,
    FOREIGN KEY (participante_id) REFERENCES participantes(id) ON DELETE SET NULL,
    UNIQUE KEY uq_liga_time (liga_id, cartola_time_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cartola_auth (
    id TINYINT PRIMARY KEY DEFAULT 1,
    token TEXT NULL,
    autenticado_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO cartola_auth (id, token, autenticado_em) VALUES (1, NULL, NULL)
    ON DUPLICATE KEY UPDATE id = id;
EOF
```

## ⚠️ Migração: premiação estruturada + cache de status do mercado

Adição pura (sem apagar nada):

```bash
docker compose exec -T db mysql -u root -proot cartoleirao <<'EOF'
ALTER TABLE liga_config
    ADD COLUMN premio_1 DECIMAL(10,2) NULL AFTER valor_inscricao,
    ADD COLUMN premio_2 DECIMAL(10,2) NULL AFTER premio_1,
    ADD COLUMN premio_3 DECIMAL(10,2) NULL AFTER premio_2;

CREATE TABLE IF NOT EXISTS mercado_status_cache (
    id TINYINT PRIMARY KEY DEFAULT 1,
    status_mercado INT NULL,
    rodada_atual INT NULL,
    atualizado_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mercado_status_cache (id, status_mercado, rodada_atual, atualizado_em) VALUES (1, NULL, NULL, NULL)
    ON DUPLICATE KEY UPDATE id = id;
EOF
```

## ⚠️ Migração: premiação estendida pra 8 posições

Adição pura (sem apagar nada):

```bash
docker compose exec -T db mysql -u root -proot cartoleirao <<'EOF'
ALTER TABLE liga_config
    ADD COLUMN premio_4 DECIMAL(10,2) NULL AFTER premio_3,
    ADD COLUMN premio_5 DECIMAL(10,2) NULL AFTER premio_4,
    ADD COLUMN premio_6 DECIMAL(10,2) NULL AFTER premio_5,
    ADD COLUMN premio_7 DECIMAL(10,2) NULL AFTER premio_6,
    ADD COLUMN premio_8 DECIMAL(10,2) NULL AFTER premio_7;
EOF
```

## ⚠️ Migração: Rodadas Bônus

Adição pura (sem apagar nada):

```bash
docker compose exec -T db mysql -u root -proot cartoleirao <<'EOF'
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
EOF
```

## 🐛 Bug corrigido: pontuação de rodada futura repetindo a última rodada fechada

**O que acontecia:** `rodada_atual` do `/mercado/status` é a rodada com o
**mercado aberto pra escalação** — ou seja, ainda vai acontecer. A API
pública de pontuação por time (`/time/id/{id}/{rodada}`), quando consultada
pra uma rodada que ainda não rolou, não retorna erro nem vazio: ela devolve
a pontuação "congelada" da última rodada fechada. O cron de pontuação
sincronizava a rodada atual junto com a anterior, e acabava gravando esse
valor errado como se fosse pontuação real da rodada nova.

**Correção:** o cron de pontuação passou a **nunca sincronizar a rodada
atual** — só as duas anteriores (janela de segurança pra não perder nada).
A classificação também foi corrigida: por padrão, mostra a última rodada
que **tem pontuação de verdade** pra aquela liga, não a última rodada
conhecida (que pode ser a atual, sem dados ainda).

## ✨ Refatoração: código e schema traduzidos pra inglês, limpeza de duplicatas

- Todo o código PHP (classes, métodos, variáveis, comentários) traduzido
  pra inglês, seguindo PSR-1/4/12. Strings visíveis ao usuário final e a
  prosa dos `.md` continuam em português.
- Schema do banco migrado pra inglês (`database/schema_en.sql` +
  `database/migrations/001_rename_schema_to_english.sql`).
- Scripts de cron renomeados (`sync_games.php`, `sync_scores.php`,
  `sync_official_league.php`), mantendo o comportamento idêntico —
  mercado/clubes/jogos/pontuações continuam 100% automáticos, sem exigir
  nenhuma ação manual.
- Removida a cópia duplicada e obsoleta do projeto que existia dentro de
  `public/` (artefato de um deploy antigo mal extraído).
- Repositório Git inicializado (não existia controle de versão antes).
