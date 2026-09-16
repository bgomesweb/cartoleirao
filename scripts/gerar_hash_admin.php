<?php
/**
 * Uso:
 *   docker compose exec app php scripts/gerar_hash_admin.php "minhaSenhaForte"
 *
 * Copie o hash gerado e rode no banco:
 *   INSERT INTO admins (usuario, senha_hash) VALUES ('admin', '<hash>');
 */
$senha = $argv[1] ?? null;

if (!$senha) {
    fwrite(STDERR, "Informe a senha como argumento. Ex: php gerar_hash_admin.php \"minhaSenha\"\n");
    exit(1);
}

echo password_hash($senha, PASSWORD_BCRYPT) . PHP_EOL;
