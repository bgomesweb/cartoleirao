/**
 * Filtro de times na classificação: digita parte do nome do time ou do
 * participante. Durante a busca o pódio some e só a lista é filtrada.
 */
$(function () {
    var $input = $('#busca-time');
    if (!$input.length) {
        return;
    }

    var $list = $('#rk-list');
    var $rows = $('#rk-list .rk-row, #tabela-busca tbody tr');
    var $podium = $('.podium');
    var $empty = $('#busca-vazia');

    function normalize(text) {
        return (text || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    $input.on('input', function () {
        var query = normalize($input.val().trim());
        var searching = query !== '';
        var shown = 0;

        $list.toggleClass('is-searching', searching);
        $podium.toggle(!searching);

        $rows.each(function () {
            var match = !searching || normalize($(this).attr('data-busca')).indexOf(query) !== -1;
            $(this).toggleClass('is-hidden', !match);
            if (match) {
                shown++;
            }
        });

        $empty.toggleClass('d-none', shown > 0);
    });
});
