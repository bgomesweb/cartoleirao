<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Models\PushSubscription;

header('Content-Type: application/json');

$input = json_decode((string) file_get_contents('php://input'), true);

// Native Android app: registers/removes its Firebase token.
if (is_array($input) && ($input['type'] ?? '') === 'fcm') {
    $token = (string) ($input['token'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_:\-]{50,4096}$/', $token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token inválido']);
        exit;
    }
    if (($input['action'] ?? '') === 'unsubscribe') {
        PushSubscription::deleteFcmToken($token);
    } else {
        PushSubscription::saveFcmToken($token, $_SERVER['HTTP_USER_AGENT'] ?? null);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$endpoint = is_array($input) ? (string) ($input['endpoint'] ?? '') : '';
$host = (string) parse_url($endpoint, PHP_URL_HOST);

// Only real push services are accepted (the server POSTs to this URL later).
$allowed = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'push.services.mozilla.com'];
$valid = str_starts_with($endpoint, 'https://') && strlen($endpoint) <= 2000 && (
    in_array($host, $allowed, true)
    || str_ends_with($host, '.push.apple.com')
    || str_ends_with($host, '.notify.windows.com')
);

if (!$valid) {
    http_response_code(400);
    echo json_encode(['error' => 'Inscrição inválida']);
    exit;
}

if (($input['action'] ?? '') === 'unsubscribe') {
    PushSubscription::deleteByEndpoint($endpoint);
    echo json_encode(['ok' => true]);
    exit;
}

$p256dh = (string) ($input['keys']['p256dh'] ?? '');
$auth = (string) ($input['keys']['auth'] ?? '');
if ($p256dh === '' || $auth === '' || strlen($p256dh) > 255 || strlen($auth) > 64) {
    http_response_code(400);
    echo json_encode(['error' => 'Inscrição inválida']);
    exit;
}

PushSubscription::save($endpoint, $p256dh, $auth, $_SERVER['HTTP_USER_AGENT'] ?? null);
echo json_encode(['ok' => true]);
