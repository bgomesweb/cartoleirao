<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Registrant;

header('Content-Type: application/json');
AdminAuth::requireLoginAjax();

if (!Csrf::isValid($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Requisição inválida']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$teamId = (int) ($_POST['time_id'] ?? 0);
$teamName = trim((string) ($_POST['time_nome'] ?? ''));
$teamSlug = trim((string) ($_POST['time_slug'] ?? ''));

if ($id <= 0 || $teamId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados incompletos']);
    exit;
}

Registrant::linkCartolaTeam($id, $teamId, $teamName, $teamSlug);

echo json_encode(['ok' => true]);
