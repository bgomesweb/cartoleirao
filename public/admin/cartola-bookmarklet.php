<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
$endpoint = $appUrl !== '' ? $appUrl . '/admin/receber-token.php' : null;
$secret = getenv('CARTOLA_BOOKMARKLET_SECRET') ?: null;

$tituloPagina = 'Bookmarklet — captura automática de token';
require __DIR__ . '/includes/header.php';

$bookmarkletHref = null;
if ($secret && $endpoint) {
    // JS "achatado" de propósito (bookmarklet vira uma URL javascript: só).
    // O que ele faz: varre localStorage e sessionStorage da aba ATUAL
    // (que precisa ser cartola.globo.com) procurando algo no formato de
    // JWT — direto como valor, ou dentro de um JSON aninhado (é assim que
    // libs tipo keycloak-js costumam persistir o access_token). Achando,
    // manda pro nosso endpoint via fetch, junto com o segredo.
    $js = <<<JS
(function(){var ENDPOINT="{$endpoint}";var SECRET="{$secret}";function isJwt(v){return typeof v==="string"&&/^[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}$/.test(v);}function walk(v,found,p){if(isJwt(v)){found.push({path:p,token:v});return;}if(typeof v==="string"){try{walk(JSON.parse(v),found,p);}catch(e){}return;}if(v&&typeof v==="object"){for(var k in v){if(Object.prototype.hasOwnProperty.call(v,k)){walk(v[k],found,p+"."+k);}}}}var found=[];[localStorage,sessionStorage].forEach(function(store,idx){var sn=idx===0?"localStorage":"sessionStorage";for(var i=0;i<store.length;i++){var key=store.key(i);walk(store.getItem(key),found,sn+"["+key+"]");}});if(found.length===0){alert("Nao encontrei nenhum token JWT salvo nesta pagina. Confirme que voce esta logado em cartola.globo.com e tente de novo.");return;}var chosen=found[0];if(found.length>1){var list=found.map(function(f,i){return i+": "+f.path;}).join("\\n");var idx2=prompt("Achei "+found.length+" candidatos a token. Digite o numero do que quer usar:\\n"+list,"0");if(idx2===null)return;chosen=found[parseInt(idx2,10)]||found[0];}fetch(ENDPOINT,{method:"POST",headers:{"Content-Type":"application/json","X-Bookmarklet-Secret":SECRET},body:JSON.stringify({token:chosen.token})}).then(function(r){return r.json();}).then(function(data){alert(data.ok?"Token enviado com sucesso para o Cartoleirao!":"Erro: "+(data.erro||"desconhecido"));}).catch(function(err){alert("Falha ao enviar: "+err);});})();
JS;
    $bookmarkletHref = 'javascript:' . rawurlencode($js);
}
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-bookmark"></i> Captura automática de token (bookmarklet)</h2>

<div class="alert alert-info">
    <strong>Por que não um iframe como o app Parciais?</strong> O Parciais é um app nativo — ele abre o login
    da Globo dentro de uma <em>WebView</em> que o próprio app controla, e intercepta o tráfego de rede que
    passa por ali (é assim que ele "vê" o token sem você copiar nada). Num site, isso não dá: o navegador
    aplica a <em>same-origin policy</em>, que impede o JavaScript de <code><?= e($appUrl ?: 'cartoleirao.bgomesweb.com.br') ?></code>
    de ler cookies, localStorage ou requisições feitas dentro de um <code>&lt;iframe&gt;</code> de
    <code>cartola.globo.com</code>. É bem provável, inclusive, que a Globo bloqueie ser embutida em iframe de
    outro domínio (<code>X-Frame-Options</code> / <code>frame-ancestors</code>), então o iframe nem chegaria a
    carregar. Não é uma limitação do sistema — é proteção de navegador que nenhum site de terceiros contorna
    de forma legítima.
</div>

<p class="text-muted">
    O que dá pra fazer, e que fica bem perto de "um clique": um <strong>bookmarklet</strong>. Diferente de um
    link normal, ele roda o script <em>dentro da aba que você já tem aberta</em> em
    <code>cartola.globo.com</code> — ali sim o script está na mesma origem e enxerga o
    localStorage/sessionStorage daquela página, onde o site da Globo costuma persistir o token OIDC. Ele acha
    o token e manda direto pro Cartoleirão, sem precisar abrir o DevTools.
</p>

<?php if (!$bookmarkletHref): ?>
    <div class="alert alert-warning">
        Falta configurar <code>APP_URL</code> e <code>CARTOLA_BOOKMARKLET_SECRET</code> (no
        <code>config.local.php</code> em produção, ou nas variáveis do <code>docker-compose.yml</code> em
        dev) pra gerar o bookmarklet. Gere um segredo aleatório, por exemplo rodando
        <code>php -r "echo bin2hex(random_bytes(32));"</code>, e recarregue esta página.
    </div>
<?php else: ?>
    <div class="card admin-sidebar-card mb-4">
        <div class="card-body">
            <h5 class="card-title">1. Arraste este link pra barra de favoritos</h5>
            <p>
                <a href="<?= htmlspecialchars($bookmarkletHref, ENT_QUOTES) ?>"
                   class="btn btn-cartoleirao" onclick="return false;">
                    <i class="fa-solid fa-bookmark"></i> Capturar token Cartola
                </a>
            </p>
            <p class="text-muted small">
                (Clicar aqui não faz nada de propósito — precisa <strong>arrastar</strong> o botão pra barra de
                favoritos do navegador, como qualquer bookmarklet.)
            </p>

            <h5 class="card-title mt-4">2. Como usar</h5>
            <ol class="text-muted small mb-0">
                <li>Abra <a href="https://cartola.globo.com" target="_blank" rel="noopener">cartola.globo.com</a> e faça login normalmente (resolvendo o captcha se pedir)</li>
                <li>Com a página da Globo carregada, clique no favorito "Capturar token Cartola"</li>
                <li>Se aparecer mais de um candidato, ele pergunta qual usar — geralmente o que tem "access" no nome do caminho</li>
                <li>Pronto: o token já é salvo direto e aparece válido na <a href="cartola-login.php">página de status</a></li>
            </ol>
        </div>
    </div>

    <div class="alert alert-warning small mb-4">
        <i class="fa-solid fa-shield-halved"></i> O segredo embutido nesse link dá permissão pra gravar um
        token de acesso da sua conta Globo no sistema. Não compartilhe o botão/link com ninguém. Se vazar,
        troque <code>CARTOLA_BOOKMARKLET_SECRET</code> e gere o bookmarklet de novo (o antigo para de
        funcionar sozinho).
    </div>
<?php endif; ?>

<p class="text-muted small">
    Se o bookmarklet não achar nenhum token (a Globo pode manter parte do fluxo só em memória, sem persistir em
    storage), o <a href="cartola-login.php">método manual via DevTools</a> continua funcionando como plano B.
</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
