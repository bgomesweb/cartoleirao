<?php
/**
 * Simple scheduler (runs in a loop inside the "cron" container of
 * docker-compose). In production (shared hosting), replace this with real
 * crontab entries:
 *
 *   (every 10 min) php /path/to/cartoleirao/cron/sync_games.php
 *   (every 15 min) php /path/to/cartoleirao/cron/sync_scores.php
 *
 * Here, in the local environment, it just sleeps and calls the scripts via
 * include, to make the "live" behavior available as soon as docker compose
 * up runs.
 */

$intervals = [
    'sync_games.php' => 600,             // 10 min
    'sync_scores.php' => 900,            // 15 min
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
