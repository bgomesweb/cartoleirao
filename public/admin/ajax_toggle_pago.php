<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Liga;
use Cartoleirao\Models\Participante;
use Cartoleirao\Services\EmailService;

header('Content-Type: application/json');
AdminAuth::exigirLoginAjax();

$id = (int) ($_POST['id'] ?? 0);

if (!Csrf::valido($_POST['_csrf'] ?? null) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Requisição inválida']);
    exit;
}

$participanteAntes = Participante::porId($id);
$pago = Participante::alternarPago($id);

// Só dispara o e-mail de confirmação quando a baixa MARCA como pago (0 -> 1),
// nunca ao desmarcar (1 -> 0) — reverter um pagamento por engano não deve
// mandar e-mail nenhum pro participante.
if ($pago && $participanteAntes && !$participanteAntes['pago']) {
    $liga = Liga::porId((int) $participanteAntes['liga_id']);
    if ($liga) {
        try {
            (new EmailService())->enviarPagamentoConfirmado($participanteAntes, $liga);
        } catch (\Throwable $e) {
            error_log('[ajax_toggle_pago] falha ao enviar e-mail de confirmação de pagamento: ' . $e->getMessage());
        }
    }
}

echo json_encode(['ok' => true, 'pago' => $pago]);
