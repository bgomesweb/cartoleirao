<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/../../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');

// Helper simples pra escapar saída em views
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}
