<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Registrant;

header('Content-Type: application/json');
AdminAuth::requireLoginAjax();

$id = (int) ($_POST['id'] ?? 0);

if (!Csrf::isValid($_POST['_csrf'] ?? null) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Requisição inválida']);
    exit;
}

Registrant::updateNotes($id, (string) ($_POST['notes'] ?? ''));

echo json_encode(['ok' => true]);
