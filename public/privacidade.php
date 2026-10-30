<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

$pageTitle = 'Política de Privacidade';
$adminWhatsapp = getenv('LEAGUE_ADMIN_WHATSAPP') ?: '(19) 99897-0090';
$whatsappLink = 'https://wa.me/55' . preg_replace('/\D/', '', $adminWhatsapp);

require __DIR__ . '/includes/header.php';

?>
<div class="container py-4" style="max-width: 820px">
    <h1 class="h3 fw-bold mb-1">Política de Privacidade e Cookies</h1>
    <p class="text-muted small mb-4">Atualizada em 30/10/2026</p>

    <h2 class="h5 fw-bold mt-4">Quais dados coletamos</h2>
    <ul>
        <li><strong>Inscrição na liga:</strong> nome, e-mail, WhatsApp e o nome do seu time no Cartola, informados por você no formulário.</li>
        <li><strong>Notificações:</strong> se você ativar o sino (ou usar o app Android), guardamos o identificador técnico do seu dispositivo/navegador fornecido pelo serviço de push (Google, Apple, Mozilla ou Microsoft) e o tipo de navegador, só para enviar os avisos de gol e de jogos.</li>
        <li><strong>Dados públicos do Cartola FC:</strong> nome do time, escudo e pontuação, obtidos da API pública do Cartola para montar a classificação.</li>
    </ul>

    <h2 class="h5 fw-bold mt-4">Cookies e armazenamento no navegador</h2>
    <p>Usamos apenas armazenamento <strong>essencial</strong> ao funcionamento do site (por exemplo, lembrar que você já viu o aviso de cookies ou o aviso de rodada bônus). <strong>Não usamos cookies de publicidade, rastreamento ou análise de comportamento.</strong></p>

    <h2 class="h5 fw-bold mt-4">Como usamos os dados</h2>
    <p>Os dados da inscrição servem para organizar a liga: confirmar sua participação, controlar o pagamento da taxa e entrar em contato com você. Os dados de notificação servem apenas para enviar os avisos. Não vendemos nem compartilhamos seus dados com terceiros para fins comerciais.</p>

    <h2 class="h5 fw-bold mt-4">Como e onde os dados são armazenados</h2>
    <p>Os dados ficam em banco de dados no servidor de hospedagem do Cartoleirão, com acesso restrito ao administrador da liga. O envio de notificações passa pelos serviços de push do seu navegador/sistema (Google Firebase, Apple, Mozilla ou Microsoft), que recebem somente o identificador necessário para entregar a mensagem. Mantemos os dados enquanto a liga existir ou até você pedir a remoção.</p>

    <h2 class="h5 fw-bold mt-4" id="remover-dados">Remoção dos seus dados</h2>
    <p>Você pode pedir a remoção dos seus dados <strong>a qualquer momento</strong>:</p>
    <ul>
        <li><strong>Neste dispositivo (imediato):</strong> use o botão abaixo para cancelar as notificações e apagar o que o site guardou no seu navegador.</li>
        <li><strong>Dados da inscrição:</strong> chame o administrador pelo WhatsApp
            <a href="<?= e($whatsappLink) ?>" target="_blank" rel="noopener"><?= e($adminWhatsapp) ?></a>
            informando seu nome e e-mail de inscrição. Excluímos seus dados do nosso banco assim que o pedido for confirmado.</li>
    </ul>

    <div class="my-3">
        <button type="button" id="btn-remover-dados" class="btn btn-outline-danger">
            <i class="fa-solid fa-trash-can"></i> Remover meus dados deste dispositivo
        </button>
        <div id="remover-dados-msg" class="small mt-2 text-success d-none" role="status">
            Pronto! Notificações canceladas e dados locais apagados.
        </div>
    </div>

    <h2 class="h5 fw-bold mt-4">Seus direitos (LGPD)</h2>
    <p>Conforme a Lei Geral de Proteção de Dados (Lei 13.709/2018), você pode solicitar confirmação de tratamento, acesso, correção e exclusão dos seus dados, pelo mesmo contato acima.</p>
</div>
<?php $extraScripts[] = 'assets/js/privacidade.js'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
