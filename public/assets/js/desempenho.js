(function () {
    var rounds = window.PERF_ROUNDS || [];
    if (!window.Chart || !rounds.length) { return; }

    var labels = rounds.map(function (r) { return 'R' + r.round; });
    var fmt = function (v) { return Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var base = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } } }
    };

    new Chart(document.getElementById('chart-pontos'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Pontos',
                data: rounds.map(function (r) { return r.points; }),
                borderColor: '#00165a',
                backgroundColor: 'rgba(0, 22, 90, .12)',
                fill: true,
                tension: .3,
                pointRadius: 4,
                pointHoverRadius: 7
            }]
        },
        options: Object.assign({}, base, {
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { title: function (i) { return 'Rodada ' + rounds[i[0].dataIndex].round; }, label: function (c) { return fmt(c.parsed.y) + ' pts'; } } }
            }
        })
    });

    new Chart(document.getElementById('chart-valorizacao'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Valorização',
                data: rounds.map(function (r) { return r.variation; }),
                backgroundColor: rounds.map(function (r) { return (r.variation || 0) >= 0 ? '#1a9d55' : '#d33a3a'; }),
                borderRadius: 4
            }]
        },
        options: Object.assign({}, base, {
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function (i) { return 'Rodada ' + rounds[i[0].dataIndex].round; },
                        label: function (c) { return 'Valorização: C$ ' + fmt(c.parsed.y); },
                        afterLabel: function (c) { var p = rounds[c.dataIndex].patrimony; return p === null ? '' : 'Patrimônio: C$ ' + fmt(p); }
                    }
                }
            }
        })
    });
})();
