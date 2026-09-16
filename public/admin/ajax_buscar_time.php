<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Services\CartolaApiClient;

header('Content-Type: application/json');
AdminAuth::requireLoginAjax();

$name = trim((string) ($_GET['nome'] ?? ''));

if ($name === '') {
    echo json_encode(['teams' => []]);
    exit;
}

try {
    $teams = (new CartolaApiClient())->searchTeamsByName($name);
} catch (\Throwable $e) {
    $teams = [];
}

echo json_encode(['teams' => $teams]);
