<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

class EmailService
{
    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * E-mail sent as soon as the registration is created, with PIX payment
     * instructions. Amount and league name always come from the league
     * (never hardcoded), so it works for any registered league.
     */
    public function sendRegistrationReceived(array $registrant, array $league): bool
    {
        $amount = $this->formatAmount($league['registration_fee'] ?? null);
        $pixKey = getenv('PIX_KEY') ?: (getenv('PIX_CHAVE') ?: '19998970090');
        $pixRecipient = getenv('PIX_RECIPIENT') ?: (getenv('PIX_FAVORECIDO') ?: 'Bruno Gomes da Silva');
        $leagueAdmin = getenv('LEAGUE_ADMIN_NAME') ?: (getenv('ADMIN_LIGA_NOME') ?: 'Bruno Gomes');

        $subject = "Inscrição recebida — {$league['name']}";

        $amountLine = $amount !== null
            ? "Para garantir sua participação, é necessário efetuar o pagamento no valor de <strong>{$this->e($amount)}</strong> via PIX:"
            : "Para garantir sua participação, é necessário efetuar o pagamento da inscrição via PIX:";

        $html = '<p>Olá ' . $this->e($registrant['name']) . ',</p>'
            . '<p>Sua inscrição na liga "' . $this->e($league['name']) . '" foi efetuada com sucesso!</p>'
            . '<p>' . $amountLine . '</p>'
            . '<p style="background:#f4f6f8;padding:12px 16px;border-radius:6px;">'
            . '<strong>Chave PIX:</strong> ' . $this->e($pixKey) . '<br>'
            . '<strong>Favorecido:</strong> ' . $this->e($pixRecipient) . '</p>'
            . '<p>Depois de pagar, envie o comprovante para o admin da liga (' . $this->e($leagueAdmin) . ').</p>'
            . '<p>Obrigado!</p>';

        $text = "Olá {$registrant['name']},\n\n"
            . "Sua inscrição na liga \"{$league['name']}\" foi efetuada com sucesso!\n\n"
            . strip_tags($amountLine) . "\n\n"
            . "Chave PIX: {$pixKey}\n"
            . "Favorecido: {$pixRecipient}\n\n"
            . "Depois de pagar, envie o comprovante para o admin da liga ({$leagueAdmin}).\n\n"
            . "Obrigado!\n\nEquipe Cartoleirão";

        return $this->send($registrant['email'], $registrant['name'], $subject, $html, $text);
    }

    /** E-mail sent when the admin marks the registration as paid (participation confirmation). */
    public function sendPaymentConfirmed(array $registrant, array $league): bool
    {
        $subject = "Participação confirmada — {$league['name']}";

        $html = '<p>Olá ' . $this->e($registrant['name']) . ',</p>'
            . '<p>Recebemos a confirmação do seu pagamento! Sua participação na liga "' . $this->e($league['name']) . '" está confirmada.</p>'
            . '<p>Boa sorte na temporada!</p>';

        $text = "Olá {$registrant['name']},\n\n"
            . "Recebemos a confirmação do seu pagamento! Sua participação na liga \"{$league['name']}\" está confirmada.\n\n"
            . "Boa sorte na temporada!\n\nEquipe Cartoleirão";

        return $this->send($registrant['email'], $registrant['name'], $subject, $html, $text);
    }

    /** Notifies the admin that a new registration came in (name, e-mail, WhatsApp and Cartola team). */
    public function sendAdminNewRegistration(array $registrant, array $league): bool
    {
        $adminEmail = getenv('ADMIN_NOTIFY_EMAIL') ?: 'bgomesweb@gmail.com';
        $team = ($registrant['cartola_team_name'] ?? '') !== '' ? $registrant['cartola_team_name'] : '—';

        $subject = "Nova inscrição — {$league['name']}: {$registrant['name']}";

        $rows = [
            'Liga' => $league['name'],
            'Nome' => $registrant['name'],
            'E-mail' => $registrant['email'],
            'WhatsApp' => $registrant['whatsapp'] ?? '—',
            'Time no Cartola' => $team,
        ];

        $html = '<p>Uma nova inscrição foi realizada:</p><table cellpadding="6" cellspacing="0" style="border-collapse:collapse;">';
        $text = "Uma nova inscrição foi realizada:\n\n";
        foreach ($rows as $label => $value) {
            $html .= '<tr><td style="color:#666;">' . $this->e($label) . '</td><td><strong>' . $this->e((string) $value) . '</strong></td></tr>';
            $text .= "{$label}: {$value}\n";
        }
        $html .= '</table>';
        $text .= "\nEquipe Cartoleirão";

        return $this->send($adminEmail, 'Administrador', $subject, $html, $text);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /** Wraps the content in the standard layout: header (logo + "Cartoleirão") and footer ("Equipe Cartoleirão"). */
    private function layout(string $content): string
    {
        $baseUrl = rtrim((string) getenv('APP_URL'), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        $logo = $this->e($baseUrl . '/assets/img/logo.png');

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head>'
            . '<body style="margin:0;padding:0;background:#eef1f4;font-family:Arial,Helvetica,sans-serif;color:#222;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">'
            . '<tr><td align="center" style="background:#0b3d2e;padding:20px;">'
            . '<img src="' . $logo . '" alt="Cartoleirão" height="64" style="vertical-align:middle;border:0;height:64px;">'
            . '<span style="display:inline-block;vertical-align:middle;margin-left:12px;color:#ffffff;font-size:26px;font-weight:bold;">Cartoleirão</span>'
            . '</td></tr>'
            . '<tr><td style="padding:24px 28px;font-size:15px;line-height:1.5;">' . $content . '</td></tr>'
            . '<tr><td align="center" style="background:#f4f6f8;padding:16px;color:#666;font-size:13px;">Equipe Cartoleirão</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private function formatAmount($amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return 'R$ ' . number_format((float) $amount, 2, ',', '.');
    }

    private function send(string $toEmail, string $toName, string $subject, string $htmlContent, string $textBody): bool
    {
        $this->lastError = null;

        $fromEmail = getenv('EMAIL_FROM_ADDRESS') ?: (getenv('EMAIL_REMETENTE') ?: ('nao-responda@' . ($_SERVER['SERVER_NAME'] ?? 'localhost')));
        $fromName = getenv('EMAIL_FROM_NAME') ?: (getenv('EMAIL_REMETENTE_NOME') ?: 'Cartoleirão');

        // PHP's native mail(): works out of the box on cPanel hosting (via
        // local sendmail/exim), no external library or SMTP config needed.
        // UTF-8 headers with MIME encoding for the name (subject/name may
        // have accents).
        $boundary = 'cartoleirao_' . bin2hex(random_bytes(8));

        $headers = [
            'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Cartoleirao',
        ];

        $encodedSubject = $this->encodeHeader($subject);

        $message = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody))
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->layout($htmlContent)))
            . "--{$boundary}--";

        $sent = @mail($toEmail, $encodedSubject, $message, implode("\r\n", $headers));

        if (!$sent) {
            $this->lastError = 'Falha ao enviar e-mail via mail() — confira se o servidor tem sendmail/exim configurado.';
        }

        return $sent;
    }

    /** Encodes an accented subject/name into the MIME format expected in e-mail headers (RFC 2047). */
    private function encodeHeader(string $text): string
    {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
}
