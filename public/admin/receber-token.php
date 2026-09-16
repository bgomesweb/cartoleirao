<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Services\CartolaAuthService;

/**
 * Endpoint chamado pelo bookmarklet (ver cartola-bookmarklet.php) DE DENTRO
 * da aba do cartola.globo.com — ou seja, é uma chamada cross-origin, sem o
 * cookie de sessão do admin. Por isso a autenticação aqui não é
 * AdminAuth::exigirLogin(), e sim um segredo compartilhado
 * (CARTOLA_BOOKMARKLET_SECRET) que só existe embutido no próprio
 * bookmarklet, gerado enquanto o admin estava logado.
 */

$origensPermitidas = ['https://cartola.globo.com', 'https://globo.com', 'https://www.globo.com'];
$origem = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origem, $origensPermitidas, true)) {
    header('Access-Control-Allow-Origin: ' . $origem);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Bookmarklet-Secret');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido']);
    exit;
}

$segredoEsperado = getenv('CARTOLA_BOOKMARKLET_SECRET') ?: null;
$segredoRecebido = $_SERVER['HTTP_X_BOOKMARKLET_SECRET'] ?? '';

if (!$segredoEsperado || !hash_equals($segredoEsperado, (string) $segredoRecebido)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Segredo inválido ou CARTOLA_BOOKMARKLET_SECRET não configurado no servidor']);
    exit;
}

$corpo = json_decode((string) file_get_contents('php://input'), true);
$token = trim((string) ($corpo['token'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Token vazio']);
    exit;
}

try {
    (new CartolaAuthService())->salvarTokenManual($token);
    echo json_encode(['ok' => true]);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
