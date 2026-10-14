# Deploy do Cartoleirão em hospedagem cPanel

Guia completo pra colocar o sistema no ar numa hospedagem compartilhada
comum (cPanel), do zero.

---

## 0. Antes de começar — o que você precisa saber

- **PHP 8.4**: o projeto exige PHP 8.4 ou superior (`composer.json`). Selecione
  essa versão no seletor de PHP da hospedagem (passo 2).
- **Estrutura do projeto**: a pasta `public/` é a ÚNICA que deve ficar
  acessível pelo navegador. As pastas `src/`, `database/`, `scripts/`,
  `cron/` e o arquivo `config.local.php` (com a senha do banco) **nunca**
  podem ficar dentro da pasta pública — se ficarem, qualquer um na internet
  consegue baixar o código-fonte e a senha do banco direto pela URL.
- Extensões PHP necessárias: `pdo_mysql`, `curl`, `mbstring`, `intl`, `json`
  (praticamente todo cPanel já vem com todas ativadas por padrão).

---

## 1. Criar o banco de dados

No cPanel, vá em **"MySQL® Databases"**.

1. Em "Create New Database", digite `cartoleirao` e clique em criar.
   O cPanel vai prefixar automaticamente com o nome da sua conta, tipo
   `usuario_cartoleirao` — anote esse nome completo, você vai precisar dele.
2. Em "MySQL Users" → "Add New User", crie um usuário (ex: `cartoleirao`) e
   uma senha forte. Anote a senha. O nome final também vem prefixado, tipo
   `usuario_cartoleirao`.
3. Em "Add User to Database", selecione o usuário e o banco que você criou,
   marque **"ALL PRIVILEGES"** e confirme.

Anote os 3 dados finais (com prefixo):
```
Banco:   usuario_cartoleirao
Usuário: usuario_cartoleirao
Senha:   (a que você definiu)
Host:    localhost   (quase sempre é "localhost" em cPanel)
```

## 2. Escolher a versão do PHP

No cPanel, vá em **"MultiPHP Manager"** (ou "Select PHP Version").

1. Selecione o domínio/subdomínio onde vai instalar.
2. Escolha PHP **8.4** (ou superior, se disponível).
3. Clique em "Options" / no ícone de engrenagem ao lado da versão pra abrir o
   **"MultiPHP INI Editor"** e confirme que estão habilitadas as extensões:
   `pdo_mysql`, `curl`, `mbstring`, `intl`, `json`, `mysqli` (geralmente já
   vêm todas ligadas).

## 3. Criar o (sub)domínio apontando pra pasta certa

Essa é a parte mais importante pra segurança. Em **"Domains"** (ou
"Subdomains", dependendo da versão do cPanel):

- Se for usar um **subdomínio** (ex: `cartoleirao.seudominio.com.br`):
  crie o subdomínio e, no campo **"Document Root"**, aponte direto pra uma
  pasta que vai conter só o `public/` do projeto (ex:
  `/home/usuario/cartoleirao/public`) — não pra pasta raiz do projeto.

- Se for usar o **domínio principal** direto: em "Domains", edite o
  domínio principal e mude o "Document Root" pra
  `/home/usuario/cartoleirao/public` também (em vez do `public_html` padrão).

Isso garante que só o conteúdo de `public/` fica acessível pelo navegador —
o resto do projeto (`src/`, `database/`, `config.local.php` etc.) fica um
nível acima, fora do alcance de qualquer URL.

## 4. Enviar os arquivos

Via **"File Manager"** do cPanel (ou FTP/SFTP, se preferir):

1. Vá pra pasta um nível ACIMA do document root que você configurou no passo
   3 (ex: `/home/usuario/`).
2. Envie o `cartoleirao.zip` (o mesmo zip que você já tem) pra lá.
3. Clique com o botão direito nele → **"Extract"**.
4. Confirme que a estrutura ficou assim:
   ```
   /home/usuario/cartoleirao/
   ├── public/          <- é ISSO que o Document Root deve apontar
   ├── src/
   ├── database/
   ├── scripts/
   ├── cron/
   ├── composer.json
   └── config.local.php.example
   ```

## 5. Gerar o autoload (sem precisar de Composer)

O ideal é rodar `composer install` — se seu cPanel tiver **"Terminal"**
habilitado (Setup → Terminal, ou dentro de "Advanced"), faça:

```bash
cd ~/cartoleirao
composer dump-autoload
```

**Se seu plano não tiver Terminal/SSH** (comum em planos mais básicos), não
tem problema — o projeto não usa nenhuma biblioteca externa, só as próprias
classes. Crie o arquivo `vendor/autoload.php` manualmente pelo File Manager
com este conteúdo:

```php
<?php
spl_autoload_register(function ($class) {
    $prefix = 'Cartoleirao\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});
```

Salve como `~/cartoleirao/vendor/autoload.php` (crie a pasta `vendor` se não
existir). Funciona igualzinho ao autoload do Composer pra esse projeto.

## 6. Configurar a conexão com o banco (`config.local.php`)

1. Copie `config.local.php.example` pra `config.local.php` (mesma pasta,
   fora do `public/`).
2. Edite `config.local.php` e preencha com os dados reais do passo 1:

```php
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_DATABASE=usuario_cartoleirao');
putenv('DB_USERNAME=usuario_cartoleirao');
putenv('DB_PASSWORD=a-senha-que-voce-definiu');

putenv('CARTOLA_API_BASE=https://api.cartola.globo.com');

putenv('APP_URL=https://seudominio.com.br');
```

> Esse arquivo tem a senha do banco em texto puro — é exatamente por isso
> que ele **precisa** ficar fora do `public/`. Como o Document Root aponta
> só pro `public/` (passo 3), esse arquivo nunca é acessível por URL.

## 7. Importar o schema do banco

Via **phpMyAdmin** (ícone no cPanel):

1. Abra o phpMyAdmin, selecione o banco `usuario_cartoleirao` na lateral.
2. Vá na aba **"Import"** (Importar).
3. Clique em "Escolher arquivo" e selecione `database/schema_en.sql` — é o
   schema atual, já com os nomes de tabela/coluna em inglês (baixe esse
   arquivo do File Manager pro seu computador primeiro, ou copie o
   conteúdo e cole na aba "SQL" do phpMyAdmin em vez de importar arquivo).
4. Clique em "Go" / "Executar".

Confirme que criou as ~13 tabelas (aba "Structure" do banco, deve listar
`admins`, `leagues`, `registrants`, `official_league_teams`, `scores` etc).

> **Já tem um banco antigo (nomes em português) rodando em produção?** Não
> rode `schema_en.sql` por cima — use
> `database/migrations/001_rename_schema_to_english.sql` (com backup antes,
> ver comentário no topo do arquivo), que renomeia as tabelas/colunas
> existentes sem apagar os dados.

## 8. Criar o usuário admin

Se você tem Terminal/SSH:
```bash
cd ~/cartoleirao
php scripts/create_admin.php admin "SuaSenhaForte123"
```

**Sem Terminal:** crie um arquivo temporário `public/criar-admin-temp.php`
com este conteúdo, acesse ele uma vez pelo navegador, e **delete
imediatamente depois**:

```php
<?php
require __DIR__ . '/../src/Config/bootstrap.php';
use Cartoleirao\Database\Connection;

$hash = password_hash('SuaSenhaForte123', PASSWORD_BCRYPT);
$pdo = Connection::get();
$stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
$stmt->execute(['admin', $hash]);
echo 'Admin criado! APAGUE ESTE ARQUIVO AGORA.';
```

Acesse `https://seudominio.com.br/criar-admin-temp.php`, confirme a
mensagem, e **apague o arquivo na hora** pelo File Manager — ele cria um
admin toda vez que é acessado, é um risco de segurança sério deixá-lo lá.

## 9. Configurar os Cron Jobs

No cPanel, vá em **"Cron Jobs"**.

Primeiro descubra o caminho completo do PHP da versão que você escolheu no
passo 2 — normalmente algo como `/usr/local/bin/php` ou
`/opt/cpanel/ea-php85/root/usr/bin/php` (o próprio cPanel geralmente mostra
esse caminho no MultiPHP Manager, ou pergunte ao suporte da hospedagem).

Adicione 3 cron jobs:

**Jogos + clubes + status do mercado (a cada 10 minutos):**
```
Minuto: */10
Hora: *
Dia: *
Mês: *
Dia da semana: *
Comando: /usr/local/bin/php /home/usuario/cartoleirao/cron/sync_games.php >> /home/usuario/cartoleirao/cron.log 2>&1
```

**Pontuação (a cada 15 minutos):**
```
Minuto: */15
Hora: *
Dia: *
Mês: *
Dia da semana: *
Comando: /usr/local/bin/php /home/usuario/cartoleirao/cron/sync_scores.php >> /home/usuario/cartoleirao/cron.log 2>&1
```

> **Cron antigo `sync_official_league.php` / `sync_liga_oficial.php`?** Foram
> removidos. Apague esse cron job no cPanel (senão ele gera e-mail de erro).

Ajuste `/usr/local/bin/php` e `/home/usuario/cartoleirao` pros caminhos
reais da sua conta. O `>> cron.log 2>&1` salva a saída num arquivo de log
(fora do `public/`) em vez de mandar e-mail a cada execução — sem isso, o
cPanel te manda um e-mail toda vez que o cron roda, o que enche a caixa de
entrada rapidinho.

## 10. Ativar HTTPS (SSL grátis)

No cPanel, vá em **"SSL/TLS Status"**, marque o domínio e clique em **"Run
AutoSSL"**. Isso instala um certificado Let's Encrypt gratuito
automaticamente (leva alguns minutos).

Depois, force redirecionamento HTTP → HTTPS: em **"Domains"**, ative a
opção **"Force HTTPS Redirect"** pro domínio.

## 11. Popular os dados iniciais

Com tudo no ar:

```bash
# se tiver terminal:
php ~/cartoleirao/cron/sync_games.php
php ~/cartoleirao/scripts/backfill_scores.php
```

Sem terminal, espere os cron jobs do passo 9 rodarem sozinhos nos primeiros
10-15 minutos (eles vão sincronizar jogos e mercado automaticamente).

Depois, em `admin/inscricoes.php`, vincule cada inscrito ao time do Cartola
(automático quando a busca por nome acha um único time, ou pelo botão
"Vincular"). Os times vinculados entram sozinhos no ranking.

## 12. Checklist final

- [ ] `https://seudominio.com.br` abre a home
- [ ] `https://seudominio.com.br/admin/login.php` abre e você loga
- [ ] Cadeado verde (SSL) no navegador
- [ ] `config.local.php` e a pasta `src/` **não** abrem por URL direta
      (teste: `https://seudominio.com.br/config.local.php` deve dar 404,
      não deve baixar nem mostrar nada)
- [ ] Os 3 cron jobs aparecem em "Cron Jobs" no cPanel
- [ ] `admin/inscricoes.php` e `admin/ligas.php` carregam normalmente

Se o teste do `config.local.php` acima (item de segurança) FALHAR — ou seja,
se a URL mostrar o conteúdo do arquivo — pare tudo e corrija o Document Root
(passo 3) antes de divulgar o site pra qualquer pessoa. Isso significa que a
senha do banco está exposta publicamente.
