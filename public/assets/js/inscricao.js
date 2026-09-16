$(function () {
    $('#whatsapp').on('input', function () {
        let v = $(this).val().replace(/\D/g, '').slice(0, 11);
        if (v.length > 6) {
            v = v.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3');
        } else if (v.length > 2) {
            v = v.replace(/(\d{2})(\d{0,5})/, '($1) $2');
        } else if (v.length > 0) {
            v = v.replace(/(\d{0,2})/, '($1');
        }
        $(this).val(v.trim());
    });

    $('#form-inscricao').on('submit', function (e) {
        const nome = $('#nome').val().trim();
        const email = $('#email').val().trim();
        const whatsapp = $('#whatsapp').val().replace(/\D/g, '');

        let erro = '';
        if (nome.length < 3) {
            erro = 'Informe seu nome completo.';
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            erro = 'Informe um e-mail válido.';
        } else if (whatsapp.length < 10) {
            erro = 'Informe um WhatsApp válido com DDD.';
        }

        if (erro) {
            e.preventDefault();
            $('#erro-cliente').text(erro).removeClass('d-none');
            $('html, body').animate({ scrollTop: $('#erro-cliente').offset().top - 100 }, 300);
        }
    });
});
