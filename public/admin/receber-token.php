<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Services\CartolaAuthService;

/**
 * Endpoint called by the bookmarklet (see cartola-bookmarklet.php) FROM
 * INSIDE the cartola.globo.com tab — i.e. it's a cross-origin call, without
 * the admin's session cookie. That's why authentication here isn't
 * AdminAuth::requireLogin(), but a shared secret (CARTOLA_BOOKMARKLET_SECRET)
 * that only exists embedded in the bookmarklet itself, generated while the
 * admin was logged in.
 */

$allowedOrigins = ['https://cartola.globo.com', 'https://globo.com', 'https://www.globo.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
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

$expectedSecret = getenv('CARTOLA_BOOKMARKLET_SECRET') ?: null;
$receivedSecret = $_SERVER['HTTP_X_BOOKMARKLET_SECRET'] ?? '';

if (!$expectedSecret || !hash_equals($expectedSecret, (string) $receivedSecret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Segredo inválido ou CARTOLA_BOOKMARKLET_SECRET não configurado no servidor']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$token = trim((string) ($body['token'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Token vazio']);
    exit;
}

try {
    (new CartolaAuthService())->saveManualToken($token);
    echo json_encode(['ok' => true]);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
