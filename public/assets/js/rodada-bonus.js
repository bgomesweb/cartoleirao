$(function () {
    var modalEl = document.getElementById('modalRodadaBonus');
    if (!modalEl) {
        return;
    }

    var round = modalEl.getAttribute('data-rodada-bonus');
    var key = 'cartoleirao_bonus_modal_rodada_' + round;

    // Shows only once per browser session so it doesn't get annoying while
    // navigating between the site's pages while the bonus round is active.
    try {
        if (sessionStorage.getItem(key)) {
            return;
        }
        sessionStorage.setItem(key, '1');
    } catch (e) {
        // If the browser blocks sessionStorage (private mode etc.), show it anyway.
    }

    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
});
