<?php

/**
 * Cria (ou atualiza a senha de) um admin diretamente no banco, sem precisar
 * copiar/colar hash manualmente — evita erros de quebra de linha/espaço que
 * corrompem o hash bcrypt.
 *
 * Uso:
 *   docker compose exec app php scripts/criar_admin.php admin "SuaSenhaForte123"
 */

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Database\Connection;

$usuario = $argv[1] ?? null;
$senha = $argv[2] ?? null;

if (!$usuario || !$senha) {
    fwrite(STDERR, "Uso: php scripts/criar_admin.php <usuario> <senha>\n");
    exit(1);
}

$hash = password_hash($senha, PASSWORD_BCRYPT);
$pdo = Connection::get();

$stmt = $pdo->prepare('SELECT id FROM admins WHERE usuario = ?');
$stmt->execute([$usuario]);

if ($stmt->fetch()) {
    $update = $pdo->prepare('UPDATE admins SET senha_hash = ? WHERE usuario = ?');
    $update->execute([$hash, $usuario]);
    echo "Admin '{$usuario}' já existia — senha atualizada com sucesso.\n";
} else {
    $insert = $pdo->prepare('INSERT INTO admins (usuario, senha_hash) VALUES (?, ?)');
    $insert->execute([$usuario, $hash]);
    echo "Admin '{$usuario}' criado com sucesso.\n";
}

// Confere se o hash gravado realmente bate com a senha informada
$check = $pdo->prepare('SELECT senha_hash FROM admins WHERE usuario = ?');
$check->execute([$usuario]);
$hashSalvo = $check->fetchColumn();

if (password_verify($senha, $hashSalvo)) {
    echo "Verificação OK: o login vai funcionar com essa senha.\n";
} else {
    echo "ATENÇÃO: algo deu errado, o hash salvo não bate com a senha informada.\n";
}
