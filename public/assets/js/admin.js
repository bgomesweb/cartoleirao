$(function () {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    // Sidebar (mobile)
    $('#sidebarToggle, #backdrop').on('click', function () {
        $('#sidebar').toggleClass('open');
        $('#backdrop').toggleClass('show');
    });

    // Registrations: client-side search
    $('#filtro-busca').on('input', function () {
        const term = $(this).val().toLowerCase().trim();
        let visible = 0;
        $('#tabela-inscricoes tbody tr').each(function () {
            const match = term === '' || $(this).text().toLowerCase().indexOf(term) !== -1
                || ($(this).find('input').val() || '').toLowerCase().indexOf(term) !== -1;
            $(this).toggle(match);
            if (match) { visible++; }
        });
        $('#vazio-texto').text('Nenhum resultado para a busca.');
        $('#vazio-inscricoes').prop('hidden', visible > 0);
    });

    // Payment toggle
    $(document).on('click', '.btn-toggle-pago', function () {
        const $btn = $(this);
        const id = $btn.data('id');

        $btn.prop('disabled', true);

        $.post('ajax_toggle_pago.php', { id: id, _csrf: csrfToken })
            .done(function (resp) {
                if (resp.ok) {
                    if (resp.paid) {
                        $btn.removeClass('is-pending').addClass('is-paid').text('Pago ✓');
                    } else {
                        $btn.removeClass('is-paid').addClass('is-pending').text('Pendente');
                    }
                    $btn.closest('tr').find('.paid-at').text(resp.paid_at || '—');
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

    // Observações: saves on blur/Enter when the value changed
    $(document).on('keydown', '.input-observacao', function (e) {
        if (e.key === 'Enter') { this.blur(); }
    });
    $(document).on('change', '.input-observacao', function () {
        const $input = $(this);
        $input.prop('disabled', true);

        $.post('ajax_salvar_observacao.php', { id: $input.data('id'), notes: $input.val(), _csrf: csrfToken })
            .done(function (resp) {
                if (resp.ok) {
                    $input.data('original', $input.val()).addClass('is-valid');
                    setTimeout(function () { $input.removeClass('is-valid'); }, 1200);
                } else {
                    alert('Não foi possível salvar a observação.');
                }
            })
            .fail(function () { alert('Erro de comunicação com o servidor.'); })
            .always(function () { $input.prop('disabled', false); });
    });

    // Open the linking modal and search candidates in the Cartola API
    let currentRegistrantId = null;

    $(document).on('click', '.btn-vincular', function () {
        currentRegistrantId = $(this).data('id');
        const name = $(this).data('nome');

        const modalEl = document.getElementById('modalVincular');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        $('#vincular-resultados').html(
            '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>'
        );
        modal.show();

        $.get('ajax_buscar_time.php', { nome: name })
            .done(function (resp) {
                if (!resp.teams || resp.teams.length === 0) {
                    $('#vincular-resultados').html(
                        '<div class="alert alert-warning mb-0">Nenhum time encontrado na API do Cartola para esse nome.</div>'
                    );
                    return;
                }

                let html = '<div class="list-group">';
                resp.teams.forEach(function (t) {
                    html += `
                        <button type="button" class="list-group-item list-group-item-action btn-selecionar-time"
                                data-time-id="${t.team_id}"
                                data-time-nome="${t.cartola_name || t.name}"
                                data-time-slug="${t.slug}">
                            <strong>${t.cartola_name || t.name}</strong>
                            <div class="small text-muted">${t.name}</div>
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

    // Pick one of the candidate teams and confirm the link
    $(document).on('click', '.btn-selecionar-time', function () {
        const $btn = $(this);

        $.post('ajax_vincular_time.php', {
            id: currentRegistrantId,
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
