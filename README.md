# Cartoleirão

Sistema de gestão de ligas do Cartola FC (Campeonato Brasileiro Série A):
inscrições, jogos da rodada e classificação, integrado com a API pública do
Cartola FC (`api.cartola.globo.com`).

Stack: PHP 8.4, MySQL 8, jQuery + Bootstrap 5, Docker.

---

> **Colocando em produção (cPanel)?** Este README é sobre o ambiente local
> (Docker). Pro passo a passo de deploy numa hospedagem compartilhada
> (criar banco, cron jobs, segurança etc), veja **[DEPLOY-CPANEL.md](DEPLOY-CPANEL.md)**.

## 1. Pré-requisitos (Ubuntu)

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg lsb-release

# Docker Engine
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Rodar docker sem sudo (relogue depois disso)
sudo usermod -aG docker $USER
newgrp docker
```

### Opção A — Warden (o mesmo que você já usa nos projetos Magento)

```bash
sudo apt install -y libnss3-tools
curl -JLO "https://dl.filippo.io/mkcert/latest?for=linux/amd64"
chmod +x mkcert-v*-linux-amd64 && sudo mv mkcert-v*-linux-amd64 /usr/local/bin/mkcert

curl -sS -L https://github.com/wardenenv/warden/releases/latest/download/warden.phar -o warden
chmod +x warden && sudo mv warden /usr/local/bin/warden

warden svc up
cd ~/projects/cartoleirao
warden env-init cartoleirao php --php-version=8.4
warden env up
warden signal-trust
```

> Se o template PHP do Warden não tiver PHP 8.4 disponível, use a **Opção B** abaixo — o `docker-compose.yml` deste repo já
> resolve tudo sozinho, sem depender do Warden.

### Opção B — docker compose direto (recomendado por enquanto)

```bash
cd cartoleirao
cp .env.example .env
docker compose up -d --build
```

Isso sobe:

| Serviço | URL |
|---|---|
| App (PHP 8.4 + Apache) | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |
| MySQL | localhost:3306 |

O `database/schema_en.sql` é executado automaticamente na primeira vez que o
container do banco sobe (via `docker-entrypoint-initdb.d`).

## 2. Instalar dependências PHP (autoload)

```bash
docker compose exec app composer dump-autoload
```

(Ainda não temos libs externas — é só pra gerar o autoload PSR-4 do `src/`.)

## 3. Criar o admin

```bash
docker compose exec app php scripts/generate_admin_hash.php "SuaSenhaForte123"
```

Copie o hash impresso e rode no phpMyAdmin (ou `docker compose exec db mysql -u root -p`):

```sql
INSERT INTO admins (username, password_hash) VALUES ('admin', '<hash gerado>');
```

## 4. Verificar se subiu certo

Acesse `http://localhost:8080` — deve aparecer a tela confirmando a conexão
com o banco (`public/index.php`, temporária, será substituída pela home real
no próximo módulo).

## 5. Comandos úteis

```bash
docker compose logs -f app        # logs do PHP/Apache
docker compose exec app bash      # entrar no container
docker compose exec db mysql -u root -p   # acessar o MySQL direto
docker compose down               # derrubar tudo
docker compose down -v            # derrubar tudo + apagar volume do banco (reset total)
```

## Estrutura do projeto

```
cartoleirao/
├── public/              # document root (index.php, admin/, assets/)
├── src/
│   ├── Database/        # conexão PDO
│   ├── Models/          # entidades (a implementar)
│   ├── Services/         # CartolaApiClient, RankingService etc (a implementar)
│   └── Controllers/
├── cron/                 # scripts de sincronização com a API do Cartola
├── database/schema_en.sql   # schema completo do MySQL (inglês, usado no Docker)
├── scripts/               # utilitários (ex: gerar hash de admin)
└── docker-compose.yml
```

## Módulo de inscrição

- **Público:** `http://localhost:8080/inscricao.php` — formulário (nome, e-mail,
  WhatsApp, nome do time no Cartola opcional), com honeypot anti-spam e CSRF.
  Se o nome do time bater com exatamente 1 resultado na API do Cartola, já
  vincula automaticamente; senão fica pendente pro admin resolver na mão.
- **Admin:** `http://localhost:8080/admin/login.php`
  - `admin/index.php` — dashboard com contadores por liga
  - `admin/ligas.php` — abrir/fechar inscrições, valor e texto de premiação
  - `admin/inscricoes.php` — lista de inscritos, toggle de pagamento (AJAX),
    modal de vinculação manual de time do Cartola (busca na API em tempo real)

### Criar o primeiro admin

```bash
docker compose exec app php scripts/generate_admin_hash.php "SuaSenhaForte123"
```

Copie o hash e rode no phpMyAdmin (`http://localhost:8081`) ou via mysql client:

```sql
INSERT INTO admins (username, password_hash) VALUES ('admin', '<hash gerado>');
```

> O histórico de migrações incrementais do banco (como cada tabela/coluna
> foi adicionada ao longo do projeto) foi movido pro [CHANGELOG.md](CHANGELOG.md).
> Pra reconstruir o schema atual do zero, use `database/schema_en.sql`; pra
> migrar um banco antigo (nomes em português) pro schema novo (inglês), use
> `database/migrations/001_rename_schema_to_english.sql`.

## Módulo de integração com a API do Cartola FC + páginas públicas

A API usada é a pública/não-oficial `api.cartola.globo.com` (sem necessidade
de chave para os endpoints de leitura usados aqui).

> **Nota:** até meados de 2026 o domínio era `api.cartolafc.globo.com`. A
> Globo migrou pra `api.cartola.globo.com` (sem "fc") — o código já aponta
> pro domínio novo e segue redirects automaticamente (`CURLOPT_FOLLOWLOCATION`)
> como proteção caso mude de novo no futuro.

- `src/Services/CartolaApiClient.php` — cliente HTTP (clubes, mercado/status,
  partidas por rodada, pontuação de um time numa rodada, busca de times)
- `src/Services/SyncService.php` — orquestra a sincronização
  (clubes → rodada atual → jogos → pontuação), grava log em `sync_log`
- `cron/sync_games.php` — roda a cada 10 min (via `cron/scheduler.php`):
  sincroniza clubes (se necessário) e os jogos da rodada atual
- `cron/sync_scores.php` — roda a cada 15 min: para cada time da liga oficial
  cadastrado (`official_league_teams`), busca a pontuação da rodada atual e
  grava em `scores`
- `cron/sync_official_league.php` — roda a cada 20 min (via `cron/scheduler.php`
  local, ou cron real no cPanel): sincroniza a(s) liga(s) com
  `cartola_league_slug` configurado, incluindo ligas "Pontos Corridos" (ex:
  Glória Eterna). Só faz algo se `CARTOLA_OFFICIAL_LEAGUE_ENABLED=true` **e**
  houver um token válido em cache (colado em `admin/cartola-login.php`); sem
  isso, só loga e sai sem erro. O login automático por e-mail/senha continua
  batendo em captcha — o token precisa ser colado manualmente de vez em
  quando (dura só algumas horas), mas a partir daí essa cron mantém tudo
  sincronizado sozinha até ele expirar

> Participantes sem o time vinculado (nome não informado, ou nome ambíguo que
> não bateu automático) não são sincronizados — vincule manualmente em
> `admin/inscricoes.php` pra que a pontuação comece a entrar.

### Páginas públicas

- `index.php` — Home: banner, quadro de premiação (lido de `league_settings`),
  destaques da rodada (top 8 da liga clássica na última rodada com pontuação)
- `jogos.php` — jogos de qualquer rodada já sincronizada (seletor de rodada) +
  botão "Compartilhar rodada" que monta o texto e abre o WhatsApp
- `classificacao.php` — ranking por liga, com abas "Geral" (acumulado) e
  "Por rodada", incluindo:
  - **Variação de posição** (▲ subiu / ▼ desceu / − manteve / "novo" se não
    havia dado anterior pra comparar), sempre contra a última rodada anterior
    que já tinha pontuação registrada
  - **Botões de compartilhar no WhatsApp** (rodada e geral, independente de
    qual aba está selecionada na tela) — o texto inclui nome do time no
    Cartola e pontuação de cada participante

### Identidade visual

Cores extraídas do logo (`public/assets/img/logo.png`): azul marinho
`#00165a` e laranja `#eb8202`, com o verde original mantido como cor de ação
(botões). Variáveis CSS em `public/assets/css/style.css` (`:root`).

### Forçar uma sincronização manual (útil pra testar sem esperar o cron)

```bash
docker compose exec app php cron/sync_games.php
docker compose exec app php cron/sync_scores.php
docker compose exec app php cron/sync_official_league.php
```

### Diagnóstico de conectividade com a API do Cartola

Se a sincronização falhar, esse script isola onde está o problema (DNS,
egress de internet do container, SSL, ou resposta da própria API):

```bash
docker compose exec app php scripts/test_cartola_connection.php
```

### Arquitetura: inscrição (pagamento) ≠ ranking (times da liga oficial)

Duas coisas completamente desacopladas, de propósito:

1. **Inscrição** (`registrants`): só controla quem se cadastrou e se pagou
   (`admin/inscricoes.php`, toggle pago/pendente). Não precisa de nome de
   time nem de nada relacionado ao Cartola pra existir.
2. **Ranking/pontuação** (`official_league_teams` + `scores`): vem de TODOS
   os times que estão de fato na liga oficial do Cartola, independente de
   alguém ter feito a inscrição. `classificacao.php`, `index.php` (destaques)
   e o compartilhamento no WhatsApp mostram todo mundo que está jogando.

O cruzamento entre as duas (pra saber quem pagou) é opcional e feito em
`admin/liga-oficial.php`: `official_league_teams.registrant_id` aponta pro
participante correspondente, quando existir — usado só pra exibir o nome/
badge de pago junto do time na classificação.

### Como popular `official_league_teams` (roster completo da liga)

O único endpoint que lista todos os times de uma liga (`auth/liga/{slug}`)
exige login — e **testamos em produção: a Globo responde `captchaBlank`**,
ou seja, exige resolver captcha, o que não dá pra automatizar de forma
legítima. A solução: você mesmo, autenticado normalmente no navegador (onde
o captcha não é um problema, porque é um humano de verdade), pega o JSON
uma vez e cola no admin:

1. Login em [cartola.globo.com](https://cartola.globo.com) no seu navegador
2. Abra sua liga, DevTools (F12) → aba **Network**
3. Recarregue a página, ache a chamada pra `auth/liga/{slug}`
4. Copie o JSON da resposta e cole em `admin/liga-oficial.php` → "Importar times"

Isso popula `official_league_teams` com o `time_id` real de cada time.

**Formato real confirmado em produção** (liga de pontos corridos):

```json
{
    "participantes": {
        "13908998": {
            "time_id": 13908998,
            "nome": "Hasdrubal FC",
            "nome_cartola": "Bruno Gomes",
            "url_escudo_svg": "https://..."
        }
    },
    "confrontos": {
        "1": [
            {
                "mandante": {"id": 13908998, "pontuacao_rodada": 61.0},
                "visitante": {"id": 117113, "pontuacao_rodada": 72.86}
            }
        ]
    },
    "ranking": [ {"time_id": 13908998, "pontos_cartola": 1536.87, "...": "..."} ]
}
```

⚠️ **Detalhe não-óbvio:** `nome` é o **nome do time** (ex: "Hasdrubal FC") e
`nome_cartola` é o nome do **dono/cartoleiro** (ex: "Bruno Gomes") — invertido
do que o nome do campo sugere. O código já trata isso certo (mostra o nome do
time em toda a interface).

**Bônus 2:** o array `ranking` já traz a tabela oficial pronta (posição,
variação, vitórias/empates/derrotas, pontos de tabela e pontuação acumulada
do Cartola) — é exatamente a "Tabela de Resultados" que aparece no site do
Cartola. O import grava esse snapshot em `official_league_teams` (colunas
`standing_*`), e `classificacao.php` exibe automaticamente esse layout (com
badge "PRO" pra assinantes) quando a liga é do tipo `round_robin`. Ligas
`classic` continuam com o layout genérico (Geral / Por rodada).

Pra rodadas novas que ainda não estavam no JSON quando você importou, **a
partir daí a pontuação entra sozinha via endpoint público**
(`/time/id/{id}/{rodada}`, sem precisar de login nenhum) — só o passo de
descobrir "quem são os times" (e o histórico/tabela de pontos corridos, se
houver) exige esse import manual ocasional. Repita o import quando: entrar
gente nova na liga oficial, quiser atualizar o histórico completo, ou
atualizar a Tabela de Resultados pra refletir a rodada mais recente.

> A liga "clássica" pode ter um formato de resposta diferente (sem
> `confrontos`/`ranking` no mesmo formato, já que não tem confronto interno
> par-a-par) — ainda não testamos essa em produção. Se o import falhar ou
> vier vazio pra ela, me manda o JSON que eu ajusto o código.

O login automático (`CartolaAuthService`, `CARTOLA_OFFICIAL_LEAGUE_ENABLED`)
continua no código, desabilitado por padrão, documentado caso a Globo mude
esse fluxo no futuro — mas não conte com ele.

### Vinculação automática/manual por nome (legado, ainda funciona)

Se alguém informar o nome do time na inscrição, o sistema ainda tenta casar
automático via busca pública (`/times?q=`) — é um bônus, não é mais
necessário pro ranking funcionar (isso já vem de `official_league_teams`).

## Novidades: badge de mercado, premiação estruturada, admin e inscrição redesenhados

- **Badge de status do mercado** ao lado do logo (público e admin): "Mercado
  Aberto/Fechado/Em Manutenção", lido de um cache local (`market_status_cache`)
  atualizado pelo `cron/sync_games.php` — nunca bate na API ao vivo numa
  requisição de página. Os códigos de `status_code` não são documentados
  oficialmente; se aparecer um valor não mapeado, mostra "Status N" (me avise
  qual rodada/situação pra eu mapear certo).
- **Premiação estruturada**: `admin/ligas.php` agora tem campos numéricos pro
  1º/2º/3º colocado (em vez de só texto livre) — a home mostra um card escuro
  com medalhas e o total calculado automaticamente, no estilo bolão.
  `premiacao_texto` continua existindo como observação extra opcional.
- **Inscrição redesenhada**: a liga agora é escolhida por pills dentro do
  próprio card (`inscricao.php?liga_id=N`), não mais por um `<select>` solto
  acima do formulário.
- **Admin polido**: botões e badges com a identidade visual consistente,
  tabelas com classe própria (`table-admin`, sem o efeito de "1ª linha
  dourada" que só faz sentido em ranking), menu com pills em vez de
  sublinhado, e corrigido um link quebrado em `ligas.php` que apontava pro
  arquivo antigo `liga_oficial.php` (removido faz algumas mudanças).

## ✨ Novidade: Rodada Bônus

- **Admin → Rodada Bônus** (`admin/rodadas-bonus.php`): marque rodadas com
  premiação extra pro time que fechar em 1º lugar naquela rodada específica
  (ex: rodadas 10, 19, 26, 37), com um valor de prêmio por rodada. Dá pra
  cadastrar várias rodadas de uma vez separando por vírgula.
- **Modal dourado no site**: quando a rodada atual do mercado (a que está
  aberta pra escalação) é uma rodada bônus, um modal estilo "gold" avisa a
  galera assim que o site abre, mostrando a rodada e o valor do prêmio. Só
  aparece uma vez por sessão do navegador (não fica repetindo a cada página).
- **Destaque do campeão**: na Classificação → "Por rodada", se a rodada
  selecionada for bônus, aparece uma faixa dourada avisando o prêmio, e o
  1º colocado daquela rodada é destacado em dourado com o selo "Campeão da
  Rodada Bônus".

## 🐛 Bug corrigido: pontuação de rodada futura repetindo a última rodada fechada

**O que acontecia:** `rodada_atual` do `/mercado/status` é a rodada com o
**mercado aberto pra escalação** — ou seja, ainda vai acontecer. A API
pública de pontuação por time (`/time/id/{id}/{rodada}`), quando consultada
pra uma rodada que ainda não rolou, não retorna erro nem vazio: ela devolve
a pontuação "congelada" da última rodada fechada. O `cron/sync_scores.php`
sincronizava a rodada atual junto com a anterior, e acabava gravando esse
valor errado como se fosse pontuação real da rodada nova.

**Correção:** `cron/sync_scores.php` agora **nunca sincroniza a rodada
atual** — só as duas anteriores (janela de segurança pra não perder nada).
`classificacao.php` também foi corrigido: por padrão, mostra a última rodada
que **tem pontuação de verdade** pra aquela liga, não a última rodada
conhecida (que pode ser a atual, sem dados ainda).

**Se você já tem dado errado gravado**, limpe a rodada atual (ajuste o
número — no seu caso era a 22):

```bash
docker compose exec -T db mysql -u root -proot cartoleirao -e "
DELETE FROM scores WHERE round_id = (SELECT id FROM rounds WHERE number = 22);
"
```

Depois rode `cron/sync_scores.php` de novo — ele vai preencher certinho
só as rodadas que já aconteceram.

## Novidades: premiação até 8 posições, admin redesenhado

- **Premiação**: até 8 posições configuráveis em `admin/ligas.php` (não mais
  só 3), exibidas na home com as cores do próprio site (navy/laranja/verde),
  não mais um esquema genérico desconectado da identidade visual.
- **Admin redesenhado**: fundo com gradiente sutil (navy/laranja bem discreto),
  cards com elevação ao passar o mouse, blocos de estatística do dashboard
  coloridos por tipo (inscritos/pagos/pendentes/vinculados), botões com
  efeito de elevação + sombra no hover, campos de formulário com foco na cor
  da marca em vez do azul padrão do Bootstrap, tabelas com zebra striping sutil.

## Puxar o histórico completo de pontuação (todas as rodadas já jogadas)

O cron normal (`sync_scores.php`) só sincroniza as 2 últimas rodadas a
cada execução, de propósito (pra não sobrecarregar a API a cada 15 min).
Pra preencher o histórico inteiro de uma vez (da rodada 1 até a última já
jogada), rode:

```bash
docker compose exec app php scripts/backfill_scores.php
```

Ele descobre sozinho a rodada atual e para uma antes dela (nunca sincroniza
a rodada que ainda não aconteceu). Se quiser um intervalo específico:

```bash
docker compose exec app php scripts/backfill_scores.php 1 15
```

Demora um pouco (bate na API pública uma vez por time por rodada, com uma
pausa de 0.3s entre cada uma pra não sobrecarregar) — pra 20 times e 20
rodadas, são ~400 chamadas, uns poucos minutos.

## Premiação: visual novo + reconhecimento automático do texto antigo

Card claro (não mais fundo escuro) com ícones em círculo com gradiente,
efeito de elevação ao passar o mouse no card inteiro e em cada linha. Se você
já tinha digitado a premiação como texto livre (formato "1º Campeão:
R$950,00"), o sistema reconhece esse padrão automaticamente e mostra com o
visual novo — não precisa redigitar nos campos estruturados, embora migrar
pra lá (`admin/ligas.php`) seja mais robusto a longo prazo.

## Próximos módulos

1. ✅ Ambiente Docker + schema do banco
2. ✅ Módulo de inscrição (formulário público + gestão no admin, múltiplas ligas)
3. ✅ Integração com a API do Cartola FC (jogos da rodada + pontuação)
4. ✅ Home, menu Jogos e menu Classificação (front-end jQuery + Bootstrap 5)
5. ✅ Compartilhamento no WhatsApp, variação de posição, identidade visual do logo
6. ✅ Arquitetura final: inscrição = só pagamento; ranking = times da liga
   oficial (import via JSON colado), pontuação automática via endpoint público
7. ✅ Tabela de Resultados (visual oficial do Cartola: posição, variação,
   escudo, badge PRO, dono, P/V/E/D/PC) pra ligas de pontos corridos
8. ✅ Badge de status do mercado, premiação estruturada (estilo bolão),
   inscrição com pills de liga, e polimento visual do admin
9. ✅ Bugfix crítico de pontuação de rodada futura, premiação até 8
   posições com cores do site, redesign visual completo do admin
10. ✅ Premiação com card claro/ícones/hover + parser do texto antigo,
    script de backfill de histórico completo de pontuação
11. ✅ Refatoração completa: código e schema do banco traduzidos pra inglês
    (PSR-1/4/12), remoção da cópia duplicada dentro de `public/`, Git
    inicializado, crons renomeados mantendo mercado/jogos/pontuação 100%
    automáticos (ver [CHANGELOG.md](CHANGELOG.md) pro histórico completo)
