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
            ? "Para garantir sua participação, é necessário efetuar o pagamento no valor de {$amount} via PIX:"
            : "Para garantir sua participação, é necessário efetuar o pagamento da inscrição via PIX:";

        $body = "Olá {$registrant['name']},\n\n"
            . "Sua inscrição na liga \"{$league['name']}\" foi efetuada com sucesso!\n\n"
            . "{$amountLine}\n\n"
            . "Chave PIX: {$pixKey}\n"
            . "Favorecido: {$pixRecipient}\n\n"
            . "Depois de pagar, envie o comprovante para o admin da liga ({$leagueAdmin}).\n\n"
            . "Obrigado,\n"
            . "Equipe Cartoleirão";

        return $this->send($registrant['email'], $registrant['name'], $subject, $body);
    }

    /** E-mail sent when the admin marks the registration as paid (participation confirmation). */
    public function sendPaymentConfirmed(array $registrant, array $league): bool
    {
        $subject = "Participação confirmada — {$league['name']}";

        $body = "Olá {$registrant['name']},\n\n"
            . "Recebemos a confirmação do seu pagamento! Sua participação na liga \"{$league['name']}\" está confirmada.\n\n"
            . "Boa sorte na temporada!\n\n"
            . "Equipe Cartoleirão";

        return $this->send($registrant['email'], $registrant['name'], $subject, $body);
    }

    private function formatAmount($amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return 'R$ ' . number_format((float) $amount, 2, ',', '.');
    }

    private function send(string $toEmail, string $toName, string $subject, string $textBody): bool
    {
        $this->lastError = null;

        $fromEmail = getenv('EMAIL_FROM_ADDRESS') ?: (getenv('EMAIL_REMETENTE') ?: ('nao-responda@' . ($_SERVER['SERVER_NAME'] ?? 'localhost')));
        $fromName = getenv('EMAIL_FROM_NAME') ?: (getenv('EMAIL_REMETENTE_NOME') ?: 'Cartoleirão');

        // PHP's native mail(): works out of the box on cPanel hosting (via
        // local sendmail/exim), no external library or SMTP config needed.
        // UTF-8 headers with MIME encoding for the name (subject/name may
        // have accents).
        $headers = [
            'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Cartoleirao',
        ];

        $encodedSubject = $this->encodeHeader($subject);

        $sent = @mail($toEmail, $encodedSubject, $textBody, implode("\r\n", $headers));

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
