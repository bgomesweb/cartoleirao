<?php
/**
 * Simple scheduler (runs in a loop inside the "cron" container of
 * docker-compose). In production (shared hosting), replace this with real
 * crontab entries:
 *
 *   (every 10 min) php /path/to/cartoleirao/cron/sync_games.php
 *   (every 15 min) php /path/to/cartoleirao/cron/sync_scores.php
 *
 * sync_official_league.php IS in the automatic loop (every 20 min). The
 * script protects itself: if CARTOLA_OFFICIAL_LEAGUE_ENABLED != true it
 * just logs and exits (exit 0), and if there's no valid token cached
 * (pasted in admin/cartola-login.php) it also exits without error, only
 * logging to sync_log. In other words, it's safe to always leave it in the
 * loop — it stays harmless until you paste a token; from then on it starts
 * running on its own, reusing that token (see the notice in
 * admin/liga-oficial.php).
 *
 * Here, in the local environment, it just sleeps and calls the scripts via
 * include, to make the "live" behavior available as soon as docker compose
 * up runs.
 */

$intervals = [
    'sync_games.php' => 600,             // 10 min
    'sync_scores.php' => 900,            // 15 min
    'sync_official_league.php' => 1200,  // 20 min (only actually does something if there's a valid cached token)
];

$lastRun = [];

echo "[scheduler] iniciado " . date('Y-m-d H:i:s') . PHP_EOL;

while (true) {
    foreach ($intervals as $script => $seconds) {
        $now = time();
        if (!isset($lastRun[$script]) || ($now - $lastRun[$script]) >= $seconds) {
            $path = __DIR__ . '/' . $script;
            if (file_exists($path)) {
                echo "[scheduler] rodando {$script} em " . date('H:i:s') . PHP_EOL;
                // isolating in its own process prevents one script's error from taking down the whole loop
                exec('php ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
                echo implode(PHP_EOL, $output) . PHP_EOL;
                $output = [];
            }
            $lastRun[$script] = $now;
        }
    }
    sleep(30);
}
