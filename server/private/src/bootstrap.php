<?php
/** Loads configuration and all classes. APP_DIR must be defined by the caller. */
if (!defined('APP_DIR')) {
    define('APP_DIR', dirname(__DIR__));
}

require_once __DIR__ . '/Env.php';
Env::load(APP_DIR . '/.env');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Flash.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Notify.php';
require_once __DIR__ . '/Files.php';
require_once __DIR__ . '/Schedule.php';
require_once __DIR__ . '/Cuts.php';
require_once __DIR__ . '/EspLog.php';
require_once __DIR__ . '/Services.php';
require_once __DIR__ . '/Monitor.php';
require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/Pages.php';
require_once __DIR__ . '/App.php';

date_default_timezone_set((string)Env::get('APP_TIMEZONE', 'America/Detroit'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(APP_DIR . '/data') || @mkdir(APP_DIR . '/data', 0700, true)) {
    ini_set('error_log', APP_DIR . '/data/error.log');
}
error_reporting(E_ALL);
