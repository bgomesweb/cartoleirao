<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;

AdminAuth::logout();
redirect('login.php');
