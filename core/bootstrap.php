<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/secrets.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/password_resets.php";
require_once __DIR__ . "/email_changes.php";

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/env.php';

// load .env from project root
load_env(__DIR__ . '/../.env');

