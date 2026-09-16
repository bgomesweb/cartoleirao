<?php

/**
 * Cria (ou atualiza a senha de) um admin diretamente no banco, sem precisar
 * copiar/colar hash manualmente — evita erros de quebra de linha/espaço que
 * corrompem o hash bcrypt.
 *
 * Uso:
 *   docker compose exec app php scripts/create_admin.php admin "SuaSenhaForte123"
 */

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Database\Connection;

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;

if (!$username || !$password) {
    fwrite(STDERR, "Uso: php scripts/create_admin.php <usuario> <senha>\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$pdo = Connection::get();

$stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
$stmt->execute([$username]);

if ($stmt->fetch()) {
    $update = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE username = ?');
    $update->execute([$hash, $username]);
    echo "Admin '{$username}' já existia — senha atualizada com sucesso.\n";
} else {
    $insert = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
    $insert->execute([$username, $hash]);
    echo "Admin '{$username}' criado com sucesso.\n";
}

// Confere se o hash gravado realmente bate com a senha informada
$check = $pdo->prepare('SELECT password_hash FROM admins WHERE username = ?');
$check->execute([$username]);
$savedHash = $check->fetchColumn();

if (password_verify($password, $savedHash)) {
    echo "Verificação OK: o login vai funcionar com essa senha.\n";
} else {
    echo "ATENÇÃO: algo deu errado, o hash salvo não bate com a senha informada.\n";
}
