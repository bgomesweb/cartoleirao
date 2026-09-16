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

$registrantBefore = Registrant::findById($id);
$paid = Registrant::togglePaid($id);

// Only fires the confirmation e-mail when the toggle MARKS it as paid
// (0 -> 1), never when unmarking (1 -> 0) — reverting a payment by mistake
// shouldn't send any e-mail to the registrant.
if ($paid && $registrantBefore && !$registrantBefore['paid']) {
    $league = League::findById((int) $registrantBefore['league_id']);
    if ($league) {
        try {
            (new EmailService())->sendPaymentConfirmed($registrantBefore, $league);
        } catch (\Throwable $e) {
            error_log('[ajax_toggle_pago] falha ao enviar e-mail de confirmação de pagamento: ' . $e->getMessage());
        }
    }
}

echo json_encode(['ok' => true, 'paid' => $paid]);
