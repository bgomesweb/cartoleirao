<?php

declare(strict_types=1);

/**
 * Em produção (hospedagem compartilhada tipo cPanel), não existe
 * docker-compose.yml pra injetar variáveis de ambiente — então carregamos
 * de um arquivo local (config.local.php) que fica FORA da pasta pública e
 * NUNCA vai pro Git (já está no .gitignore).
 *
 * No Docker local, esse arquivo não existe — o file_exists() abaixo pula
 * silenciosamente e as variáveis continuam vindo do docker-compose.yml
 * normalmente, como já funcionava.
 *
 * Ver config.local.php.example na raiz do projeto pro modelo a preencher.
 */
$configLocal = __DIR__ . '/../../config.local.php';

if (file_exists($configLocal)) {
    require $configLocal;
}
