<?php
/**
 * Scheduler simples (roda em loop dentro do container "cron" do docker-compose).
 * Em produção (hospedagem compartilhada), troque isso por entradas reais no crontab:
 *
 *   (a cada 10 min) php /caminho/cartoleirao/cron/sync_jogos.php
 *   (a cada 15 min) php /caminho/cartoleirao/cron/sync_pontuacao.php
 *
 * sync_liga_oficial.php ESTÁ no loop automático (a cada 20 min). O próprio
 * script se protege sozinho: se CARTOLA_LIGA_OFICIAL_HABILITADA != true ele
 * só loga e sai (exit 0), e se não houver token válido em cache (colado em
 * admin/cartola-login.php) ele também sai sem erro, só registrando em
 * sync_log. Ou seja, é seguro deixar sempre no loop — ele fica inofensivo
 * até você colar um token; a partir daí passa a rodar sozinho reaproveitando
 * esse token (ver aviso em admin/liga-oficial.php).
 *
 * Aqui no ambiente local ele só dorme e chama os scripts via include, pra já
 * deixar o comportamento "vivo" assim que o docker compose up for feito.
 */

$intervalos = [
    'sync_jogos.php' => 600,           // 10 min
    'sync_pontuacao.php' => 900,       // 15 min
    'sync_liga_oficial.php' => 1200,   // 20 min (só faz algo de fato se houver token válido em cache)
];

$ultimaExecucao = [];

echo "[scheduler] iniciado " . date('Y-m-d H:i:s') . PHP_EOL;

while (true) {
    foreach ($intervalos as $script => $segundos) {
        $agora = time();
        if (!isset($ultimaExecucao[$script]) || ($agora - $ultimaExecucao[$script]) >= $segundos) {
            $caminho = __DIR__ . '/' . $script;
            if (file_exists($caminho)) {
                echo "[scheduler] rodando {$script} em " . date('H:i:s') . PHP_EOL;
                // isolar em processo próprio evita que um erro derrube o loop inteiro
                exec('php ' . escapeshellarg($caminho) . ' 2>&1', $saida, $codigo);
                echo implode(PHP_EOL, $saida) . PHP_EOL;
                $saida = [];
            }
            $ultimaExecucao[$script] = $agora;
        }
    }
    sleep(30);
}
