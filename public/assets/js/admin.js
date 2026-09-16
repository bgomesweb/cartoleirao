$(function () {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    // Toggle de pagamento
    $(document).on('click', '.btn-toggle-pago', function () {
        const $btn = $(this);
        const id = $btn.data('id');

        $btn.prop('disabled', true);

        $.post('ajax_toggle_pago.php', { id: id, _csrf: csrfToken })
            .done(function (resp) {
                if (resp.ok) {
                    if (resp.pago) {
                        $btn.removeClass('btn-outline-danger').addClass('btn-success').text('Pago ✓');
                    } else {
                        $btn.removeClass('btn-success').addClass('btn-outline-danger').text('Pendente');
                    }
                } else {
                    alert('Não foi possível atualizar o pagamento.');
                }
            })
            .fail(function () {
                alert('Erro de comunicação com o servidor.');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    // Abrir modal de vinculação e buscar candidatos na API do Cartola
    let participanteIdAtual = null;

    $(document).on('click', '.btn-vincular', function () {
        participanteIdAtual = $(this).data('id');
        const nome = $(this).data('nome');

        const modalEl = document.getElementById('modalVincular');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        $('#vincular-resultados').html(
            '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>'
        );
        modal.show();

        $.get('ajax_buscar_time.php', { nome: nome })
            .done(function (resp) {
                if (!resp.times || resp.times.length === 0) {
                    $('#vincular-resultados').html(
                        '<div class="alert alert-warning mb-0">Nenhum time encontrado na API do Cartola para esse nome.</div>'
                    );
                    return;
                }

                let html = '<div class="list-group">';
                resp.times.forEach(function (t) {
                    html += `
                        <button type="button" class="list-group-item list-group-item-action btn-selecionar-time"
                                data-time-id="${t.time_id}"
                                data-time-nome="${t.nome_cartola || t.nome}"
                                data-time-slug="${t.slug}">
                            <strong>${t.nome_cartola || t.nome}</strong>
                            <div class="small text-muted">${t.nome}</div>
                        </button>`;
                });
                html += '</div>';

                $('#vincular-resultados').html(html);
            })
            .fail(function () {
                $('#vincular-resultados').html(
                    '<div class="alert alert-danger mb-0">Erro ao consultar a API do Cartola.</div>'
                );
            });
    });

    // Selecionar um dos times candidatos e efetivar a vinculação
    $(document).on('click', '.btn-selecionar-time', function () {
        const $btn = $(this);

        $.post('ajax_vincular_time.php', {
            id: participanteIdAtual,
            time_id: $btn.data('time-id'),
            time_nome: $btn.data('time-nome'),
            time_slug: $btn.data('time-slug'),
            _csrf: csrfToken,
        })
            .done(function (resp) {
                if (resp.ok) {
                    location.reload();
                } else {
                    alert('Não foi possível vincular o time.');
                }
            })
            .fail(function () {
                alert('Erro de comunicação com o servidor.');
            });
    });
});
