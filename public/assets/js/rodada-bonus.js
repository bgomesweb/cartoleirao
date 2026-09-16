$(function () {
    var modalEl = document.getElementById('modalRodadaBonus');
    if (!modalEl) {
        return;
    }

    var rodada = modalEl.getAttribute('data-rodada-bonus');
    var chave = 'cartoleirao_bonus_modal_rodada_' + rodada;

    // Mostra só uma vez por sessão do navegador pra não ficar chato
    // navegando entre as páginas do site enquanto a rodada bônus estiver ativa.
    try {
        if (sessionStorage.getItem(chave)) {
            return;
        }
        sessionStorage.setItem(chave, '1');
    } catch (e) {
        // Se o navegador bloquear sessionStorage (modo privado etc.), mostra mesmo assim.
    }

    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
});
