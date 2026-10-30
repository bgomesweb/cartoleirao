/**
 * Aviso de cookies: aparece até o visitante confirmar. A escolha fica no
 * localStorage; se o navegador bloquear, o aviso volta a cada visita.
 */
$(function () {
    var key = 'cartoleirao_cookies_ok';
    var $banner = $('#cookie-banner');

    try {
        if (localStorage.getItem(key)) {
            return;
        }
    } catch (e) {
        // Sem localStorage: mostra o aviso mesmo assim.
    }

    $banner.removeClass('d-none');

    $('#cookie-ok').on('click', function () {
        try {
            localStorage.setItem(key, '1');
        } catch (e) {
            // Ignora: o aviso só some nesta visita.
        }
        $banner.addClass('d-none');
    });
});
