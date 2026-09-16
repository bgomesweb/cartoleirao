<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Participante;

header('Content-Type: application/json');
AdminAuth::exigirLoginAjax();

if (!Csrf::valido($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Requisição inválida']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$timeId = (int) ($_POST['time_id'] ?? 0);
$timeNome = trim((string) ($_POST['time_nome'] ?? ''));
$timeSlug = trim((string) ($_POST['time_slug'] ?? ''));

if ($id <= 0 || $timeId <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Dados incompletos']);
    exit;
}

Participante::vincularTimeCartola($id, $timeId, $timeNome, $timeSlug);

echo json_encode(['ok' => true]);
