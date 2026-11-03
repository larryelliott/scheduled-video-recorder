<?php
/** One-time import of the old esp.log:   php bin/import-esp-log.php /path/to/esp.log */
if (PHP_SAPI !== 'cli') {
    exit("Command line only\n");
}
define('APP_DIR', dirname(__DIR__));
require APP_DIR . '/src/bootstrap.php';
if (!isset($argv[1]) || !is_file($argv[1])) {
    exit("Usage: php bin/import-esp-log.php /path/to/esp.log\n");
}
echo 'Imported ' . EspLog::import($argv[1]) . " readings.\n";
