/**
 * Botão de notificações de gol (Web Push). No iPhone só funciona com o site
 * instalado na tela de início (iOS 16.4+); nesse caso mostra o passo a passo.
 */
$(function () {
    var $btn = $('#btn-notificacoes');
    if (!$btn.length) { return; }

    var vapidKey = $btn.data('vapid');
    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var standalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;

    function urlBase64ToUint8Array(base64) {
        var padding = '='.repeat((4 - base64.length % 4) % 4);
        var raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, function (c) { return c.charCodeAt(0); });
    }

    function setState(on) {
        $btn.toggleClass('is-on', on)
            .attr('title', on ? 'Notificações de gol ativadas (toque para desativar)' : 'Ativar notificações de gol')
            .find('i').attr('class', on ? 'fa-solid fa-bell' : 'fa-regular fa-bell');
    }

    function help(html) {
        $('#modalNotificacoesTexto').html(html);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNotificacoes')).show();
    }

    function send(payload) {
        return fetch('push_subscribe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
    }

    if (supported) {
        navigator.serviceWorker.register('/sw.js').then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) { setState(!!sub && Notification.permission === 'granted'); }).catch(function () {});
    }

    $btn.on('click', async function () {
        if (isIos && !standalone) {
            help('No iPhone, toque em <strong>Compartilhar</strong> <i class="fa-solid fa-arrow-up-from-bracket"></i> e em <strong>Adicionar à Tela de Início</strong>. Abra o Cartoleirão pelo ícone novo e toque no sino de novo.');
            return;
        }
        if (!supported) {
            help('Este navegador não suporta notificações. Tente o Chrome (Android) ou instale o site na tela de início (iPhone).');
            return;
        }

        try {
            var reg = await navigator.serviceWorker.ready;
            var sub = await reg.pushManager.getSubscription();

            if (sub) {
                await send({ action: 'unsubscribe', endpoint: sub.endpoint });
                await sub.unsubscribe();
                setState(false);
                return;
            }

            var permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                help('As notificações estão bloqueadas. Libere nas configurações do navegador para este site e tente de novo.');
                return;
            }

            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidKey),
            });
            var resp = await send(sub.toJSON());
            if (!resp.ok) { throw new Error('http ' + resp.status); }
            setState(true);
            help('Pronto! Você vai receber um aviso a cada gol da rodada. ⚽');
        } catch (err) {
            help('Não foi possível ativar as notificações agora. Tente novamente em instantes.');
        }
    });
});
