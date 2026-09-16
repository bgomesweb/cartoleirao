<?php

declare(strict_types=1);

/**
 * In production (shared hosting like cPanel), there's no docker-compose.yml
 * to inject environment variables — so we load them from a local file
 * (config.local.php) that lives OUTSIDE the public folder and NEVER goes
 * into Git (already in .gitignore).
 *
 * In local Docker, this file doesn't exist — the file_exists() check below
 * silently skips it and the variables keep coming from docker-compose.yml
 * as usual.
 *
 * See config.local.php.example at the project root for the template to fill in.
 */
$localConfigFile = __DIR__ . '/../../config.local.php';

if (file_exists($localConfigFile)) {
    require $localConfigFile;
}
