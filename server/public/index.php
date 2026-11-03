<?php
// Front controller. Every request that is not a real file lands here (see .htaccess).
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && substr($f, -9) !== 'index.php') {
        return false; // let the dev server serve assets
    }
}

// The private folder (code, database, videos, .env) lives outside the web root.
$candidates = [
    (string)getenv('VM_PRIVATE_DIR'),
    dirname(__DIR__) . '/private',                    // repository layout (local testing)
    dirname(__DIR__, 2) . '/video-manager-private',   // hosting layout: ~/video-manager-private
];
$private = null;
foreach ($candidates as $c) {
    if ($c !== '' && is_file($c . '/src/bootstrap.php')) {
        $private = $c;
        break;
    }
}
if ($private === null) {
    http_response_code(500);
    echo 'Server files not found. See docs/INSTALL.md, step "Upload the private folder".';
    exit;
}
define('APP_DIR', $private);
require APP_DIR . '/src/bootstrap.php';
App::run();
