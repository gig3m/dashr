<?php
declare(strict_types=1);

require __DIR__ . '/../src/env.php';
require __DIR__ . '/../src/codes.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/routes.php';

load_env(__DIR__ . '/../.env');

configure_session();
session_start();

dispatch();
