<?php
declare(strict_types=1);

require __DIR__ . '/../src/env.php';
require __DIR__ . '/../src/codes.php';
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/routes.php';

$envPath = getenv('SHORTENER_ENV_PATH') ?: (__DIR__ . '/../.env');
load_env($envPath);

$dbPath = getenv('SHORTENER_DB_PATH') ?: (__DIR__ . '/../data/links.db');
get_db($dbPath); // initialize early so handlers don't have to pass the path

configure_session();
session_start();

dispatch();
