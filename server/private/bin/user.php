<?php
/**
 * Manage people and sign-in links from SSH.
 *   php bin/user.php add "Larry" admin larry@example.org   (prints a sign-in link)
 *   php bin/user.php link 1                                (new link for user 1)
 *   php bin/user.php list
 */
if (PHP_SAPI !== 'cli') {
    exit("Command line only\n");
}
define('APP_DIR', dirname(__DIR__));
require APP_DIR . '/src/bootstrap.php';
$cmd = $argv[1] ?? '';
if ($cmd === 'add' && isset($argv[2])) {
    $uid = Auth::createUser($argv[2], ($argv[3] ?? 'user') === 'admin' ? 'admin' : 'user', $argv[4] ?? '');
    echo "Created user $uid. Sign-in link (shown once):\n" . Auth::linkUrl(Auth::createLink($uid)) . "\n";
} elseif ($cmd === 'link' && isset($argv[2])) {
    echo Auth::linkUrl(Auth::createLink((int)$argv[2])) . "\n";
} elseif ($cmd === 'list') {
    foreach (Db::all('SELECT id, name, role, email FROM users') as $u) {
        echo "{$u['id']}  {$u['name']}  {$u['role']}  {$u['email']}\n";
    }
} else {
    echo "Usage: php bin/user.php add \"Name\" admin|user [email] | link <userId> | list\n";
}
