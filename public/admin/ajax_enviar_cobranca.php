<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\League;
use Cartoleirao\Models\Registrant;
use Cartoleirao\Services\EmailService;

header('Content-Type: application/json');
AdminAuth::requireLoginAjax();

$id = (int) ($_POST['id'] ?? 0);

if (!Csrf::isValid($_POST['_csrf'] ?? null) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Requisição inválida']);
    exit;
}

$registrant = Registrant::findById($id);
$league = $registrant ? League::findById((int) $registrant['league_id']) : null;

if (!$registrant || !$league) {
    http_response_code(404);
    echo json_encode(['error' => 'Inscrição não encontrada']);
    exit;
}

if ($registrant['paid']) {
    http_response_code(409);
    echo json_encode(['error' => 'Este participante já está com o pagamento confirmado.']);
    exit;
}

$mailer = new EmailService();
$sent = false;
try {
    $sent = $mailer->sendPaymentReminder($registrant, $league);
} catch (\Throwable $e) {
    error_log('[ajax_enviar_cobranca] ' . $e->getMessage());
}

if (!$sent) {
    http_response_code(500);
    echo json_encode(['error' => $mailer->getLastError() ?? 'Falha ao enviar o e-mail.']);
    exit;
}

echo json_encode(['ok' => true]);
