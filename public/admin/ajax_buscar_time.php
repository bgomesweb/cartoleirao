<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Services\CartolaApiClient;

header('Content-Type: application/json');
AdminAuth::exigirLoginAjax();

$nome = trim((string) ($_GET['nome'] ?? ''));

if ($nome === '') {
    echo json_encode(['times' => []]);
    exit;
}

try {
    $times = (new CartolaApiClient())->buscarTimesPorNome($nome);
} catch (\Throwable $e) {
    $times = [];
}

echo json_encode(['times' => $times]);
