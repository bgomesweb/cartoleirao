$(function () {
    var el = document.getElementById('modalResumoRodada');
    if (!el) {
        return;
    }

    var key = 'cartoleirao_resumo_rodada_' + el.getAttribute('data-rodada');
    // Shown once per browser session, like the bonus round modal.
    try {
        if (sessionStorage.getItem(key)) {
            return;
        }
        sessionStorage.setItem(key, '1');
    } catch (e) {
        // sessionStorage blocked: show it anyway
    }

    var modal = bootstrap.Modal.getOrCreateInstance(el);
    var bonus = document.getElementById('modalRodadaBonus');

    // Two Bootstrap modals can't be open together: if the bonus round one is up, wait for it to close.
    setTimeout(function () {
        if (bonus && bonus.classList.contains('show')) {
            bonus.addEventListener('hidden.bs.modal', function () { modal.show(); }, { once: true });
        } else {
            modal.show();
        }
    }, 400);
});
