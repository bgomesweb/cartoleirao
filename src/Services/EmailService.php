<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

class EmailService
{
    private ?string $ultimoErro = null;

    public function getUltimoErro(): ?string
    {
        return $this->ultimoErro;
    }

    /**
     * E-mail enviado assim que a inscrição é criada, com as instruções de
     * pagamento via PIX. Valor e nome da liga vêm sempre da liga (nunca
     * hardcoded), então funciona pra qualquer liga cadastrada.
     */
    public function enviarInscricaoRealizada(array $participante, array $liga): bool
    {
        $valor = $this->formatarValor($liga['valor_inscricao'] ?? null);
        $pixChave = getenv('PIX_CHAVE') ?: '19998970090';
        $pixFavorecido = getenv('PIX_FAVORECIDO') ?: 'Bruno Gomes da Silva';
        $adminLiga = getenv('ADMIN_LIGA_NOME') ?: 'Bruno Gomes';

        $assunto = "Inscrição recebida — {$liga['nome']}";

        $linhasValor = $valor !== null
            ? "Para garantir sua participação, é necessário efetuar o pagamento no valor de {$valor} via PIX:"
            : "Para garantir sua participação, é necessário efetuar o pagamento da inscrição via PIX:";

        $corpo = "Olá {$participante['nome']},\n\n"
            . "Sua inscrição na liga \"{$liga['nome']}\" foi efetuada com sucesso!\n\n"
            . "{$linhasValor}\n\n"
            . "Chave PIX: {$pixChave}\n"
            . "Favorecido: {$pixFavorecido}\n\n"
            . "Depois de pagar, envie o comprovante para o admin da liga ({$adminLiga}).\n\n"
            . "Obrigado,\n"
            . "Equipe Cartoleirão";

        return $this->enviar($participante['email'], $participante['nome'], $assunto, $corpo);
    }

    /** E-mail enviado quando o admin marca a inscrição como paga (confirmação da participação). */
    public function enviarPagamentoConfirmado(array $participante, array $liga): bool
    {
        $assunto = "Participação confirmada — {$liga['nome']}";

        $corpo = "Olá {$participante['nome']},\n\n"
            . "Recebemos a confirmação do seu pagamento! Sua participação na liga \"{$liga['nome']}\" está confirmada.\n\n"
            . "Boa sorte na temporada!\n\n"
            . "Equipe Cartoleirão";

        return $this->enviar($participante['email'], $participante['nome'], $assunto, $corpo);
    }

    private function formatarValor($valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    private function enviar(string $paraEmail, string $paraNome, string $assunto, string $corpoTexto): bool
    {
        $this->ultimoErro = null;

        $remetenteEmail = getenv('EMAIL_REMETENTE') ?: ('nao-responda@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $remetenteNome = getenv('EMAIL_REMETENTE_NOME') ?: 'Cartoleirão';

        // mail() nativo do PHP: funciona direto em hospedagem cPanel (via
        // sendmail/exim local), sem precisar de biblioteca externa nem SMTP
        // configurado. Cabeçalhos em UTF-8 com codificação MIME pro nome
        // (assunto/nome podem ter acento).
        $headers = [
            'From: ' . $this->codificarCabecalho($remetenteNome) . ' <' . $remetenteEmail . '>',
            'Reply-To: ' . $remetenteEmail,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Cartoleirao',
        ];

        $assuntoCodificado = $this->codificarCabecalho($assunto);

        $enviado = @mail($paraEmail, $assuntoCodificado, $corpoTexto, implode("\r\n", $headers));

        if (!$enviado) {
            $this->ultimoErro = 'Falha ao enviar e-mail via mail() — confira se o servidor tem sendmail/exim configurado.';
        }

        return $enviado;
    }

    /** Codifica assunto/nome com acentos no formato MIME esperado nos headers de e-mail (RFC 2047). */
    private function codificarCabecalho(string $texto): string
    {
        return '=?UTF-8?B?' . base64_encode($texto) . '?=';
    }
}
