<?php
/**
 * Run from cron, e.g. hourly:   php ~/video-manager-private/bin/cron.php
 * It does nothing outside 8 AM - 9 PM church time unless you pass --force.
 */
if (PHP_SAPI !== 'cli') {
    exit("Command line only\n");
}
define('APP_DIR', dirname(__DIR__));
require APP_DIR . '/src/bootstrap.php';
$force = in_array('--force', $argv, true);
foreach (Monitor::cron($force) as $line) {
    echo date('Y-m-d H:i:s') . ' ' . $line . "\n";
}
