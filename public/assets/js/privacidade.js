/**
 * "Remover meus dados deste dispositivo": cancela a inscrição de push no
 * servidor e apaga o armazenamento local do site.
 */
$(function () {
    $('#btn-remover-dados').on('click', async function () {
        var $btn = $(this).prop('disabled', true);

        try {
            if ('serviceWorker' in navigator && 'PushManager' in window) {
                var reg = await navigator.serviceWorker.getRegistration();
                var sub = reg ? await reg.pushManager.getSubscription() : null;
                if (sub) {
                    await fetch('push_subscribe.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'unsubscribe', endpoint: sub.endpoint }),
                    });
                    await sub.unsubscribe();
                }
            }
        } catch (e) {
            // Segue apagando o que for possível localmente.
        }

        try {
            localStorage.clear();
            sessionStorage.clear();
        } catch (e) {
            // Armazenamento bloqueado: não há nada a apagar.
        }

        $('#remover-dados-msg').removeClass('d-none');
        $btn.prop('disabled', false);
    });
});
