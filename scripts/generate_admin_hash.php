<?php
/**
 * Uso:
 *   docker compose exec app php scripts/generate_admin_hash.php "minhaSenhaForte"
 *
 * Copie o hash gerado e rode no banco:
 *   INSERT INTO admins (username, password_hash) VALUES ('admin', '<hash>');
 */
$password = $argv[1] ?? null;

if (!$password) {
    fwrite(STDERR, "Informe a senha como argumento. Ex: php generate_admin_hash.php \"minhaSenha\"\n");
    exit(1);
}

echo password_hash($password, PASSWORD_BCRYPT) . PHP_EOL;
