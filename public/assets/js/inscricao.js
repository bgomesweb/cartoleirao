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
        const name = $('#nome').val().trim();
        const email = $('#email').val().trim();
        const whatsapp = $('#whatsapp').val().replace(/\D/g, '');

        let error = '';
        if (name.length < 3) {
            error = 'Informe seu nome completo.';
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            error = 'Informe um e-mail válido.';
        } else if (whatsapp.length < 10) {
            error = 'Informe um WhatsApp válido com DDD.';
        }

        if (error) {
            e.preventDefault();
            $('#erro-cliente').text(error).removeClass('d-none');
            $('html, body').animate({ scrollTop: $('#erro-cliente').offset().top - 100 }, 300);
        }
    });
});
