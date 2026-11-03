<?php
/** Front controller: routes requests to pages, API handlers and actions. */
final class App
{
    public static function run(): void
    {
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $bp = base_path();
        if ($bp !== '' && strpos($path, $bp) === 0) {
            $path = substr($path, strlen($bp));
        }
        $path = '/' . trim($path, '/');
        $isApi = strpos($path, '/api/') === 0;
        try {
            self::dispatch($method, $path);
        } catch (Throwable $e) {
            error_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            if ($isApi) {
                json_out(['ok' => false, 'error' => 'Server error'], 500);
            }
            http_response_code(500);
            echo View::layout('Error', '<section class="card"><h1>Something went wrong</h1><p>It has been logged. Try again in a minute.</p></section>');
        }
    }

    private static function html(string $s): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $s;
    }

    private static function dispatch(string $method, string $path): void
    {
        $m = [];

        // ---- machine APIs (token auth) ----
        if ($path === '/api/esp' && $method === 'POST') {
            self::needToken('ESP_TOKEN');
            json_out(Api::esp(Http::json()));
        }
        if (strpos($path, '/api/agent/') === 0) {
            self::needToken('AGENT_TOKEN');
            if ($path === '/api/agent/heartbeat' && $method === 'POST') {
                json_out(Api::heartbeat(Http::json()));
            }
            if ($path === '/api/agent/recording' && $method === 'POST') {
                json_out(Api::recording(Http::json()));
            }
            if ($path === '/api/agent/job' && $method === 'POST') {
                json_out(Api::job(Http::json()));
            }
            if ($path === '/api/agent/upload' && $method === 'PUT') {
                @set_time_limit(0);
                $tmp = Files::dir() . '/incoming-' . bin2hex(random_bytes(6)) . '.part';
                $in = fopen('php://input', 'rb');
                $out = fopen($tmp, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
                $r = Api::finishUpload((int)($_GET['service_id'] ?? 0), (string)($_GET['name'] ?? ''), $tmp, $declared);
                json_out($r, !empty($r['ok']) ? 200 : 400);
            }
            json_out(['ok' => false, 'error' => 'Not found'], 404);
        }

        // ---- sign-in (no session yet) ----
        if ($path === '/login') {
            self::html(Pages::login());
            return;
        }
        if (preg_match('#^/m/([A-Za-z0-9_-]{20,})$#', $path, $m) && ($method === 'GET' || $method === 'POST')) {
            self::html(Pages::magic($method, $m[1]));
            return;
        }
        if ($path === '/setup') {
            self::html(Pages::setup($method));
            return;
        }

        // ---- everything else needs a session ----
        $user = Auth::user();
        if ($user === null) {
            if (strpos($path, '/api/') === 0) {
                json_out(['ok' => false, 'error' => 'Sign in required'], 401);
            }
            redirect('/login');
        }

        if ($method === 'POST') {
            Auth::checkCsrf();
            self::post($path);
            return;
        }

        if ($path === '/') {
            self::html(Pages::dashboard((string)($_GET['m'] ?? '')));
        } elseif ($path === '/api/status-card') {
            self::html(View::statusCard());
        } elseif ($path === '/services') {
            self::html(Pages::services());
        } elseif (preg_match('#^/services/(\d+)$#', $path, $m)) {
            self::html(Pages::service((int)$m[1], (string)($_GET['m'] ?? ''), (string)($_GET['t'] ?? 'ok')));
        } elseif ($path === '/files') {
            self::html(Pages::files((string)($_GET['m'] ?? '')));
        } elseif ($path === '/settings') {
            self::html(Pages::settings((string)($_GET['m'] ?? '')));
        } elseif (preg_match('#^/download/(\d+)$#', $path, $m)) {
            $f = Db::one('SELECT * FROM files WHERE id = ?', [(int)$m[1]]);
            if ($f === null) {
                http_response_code(404);
                self::html(View::layout('Not found', '<section class="card"><h1>File not found</h1><p>It may have been deleted after two weeks.</p></section>'));
                return;
            }
            Files::stream($f['path'], $f['name'], isset($_GET['inline']));
        } else {
            http_response_code(404);
            self::html(View::layout('Not found', '<section class="card"><h1>Page not found</h1></section>'));
        }
    }

    private static function needToken(string $key): void
    {
        if (!Api::tokenOk($key)) {
            json_out(['ok' => false, 'error' => 'Bad token'], 401);
        }
    }

    private static function back(string $path, string $msg, string $tone = 'ok'): void
    {
        redirect($path . '?m=' . rawurlencode($msg) . '&t=' . $tone);
    }

    /** Form posts from signed-in users. */
    private static function post(string $path): void
    {
        $m = [];
        $admin = Auth::isAdmin();
        $u = Auth::user();

        if ($path === '/logout') {
            Auth::logout();
            redirect('/login');
        }
        if ($path === '/actions/start') {
            $s = Services::startNow();
            self::back('/', 'Starting. The Windows PC picks this up within about 20 seconds. ' . Services::label($s));
        }
        if ($path === '/actions/stop') {
            self::back('/', Services::stopNow() ? 'Stopping. The Windows PC will stop within about 20 seconds.' : 'Nothing is recording right now.');
        }
        if ($path === '/actions/refresh') {
            $r = Schedule::refresh();
            self::back('/', $r['ok'] ? 'Schedule refreshed (' . $r['count'] . ' services read).' : $r['error'], $r['ok'] ? 'ok' : 'bad');
        }
        if ($path === '/actions/test' && $admin) {
            Services::createTest();
            self::back('/', 'Test recording queued. Watch the status above.');
        }
        if ($path === '/actions/silence') {
            Monitor::setSilenced((string)($_POST['device'] ?? ''), ($_POST['on'] ?? '') === '1');
            redirect('/');
        }
        if ($path === '/actions/light' && $admin) {
            $on = ($_POST['state'] ?? '') === 'on';
            Cuts::simulate($on);
            $ret = (string)($_POST['return'] ?? '/');
            if ($ret !== '/' && !preg_match('#^/services/\d+$#', $ret)) {
                $ret = '/';
            }
            self::back($ret, 'Pretended the pulpit light turned ' . ($on ? 'on' : 'off') . ' at ' . fmt_local(Clock::now(), 'g:i:s A') . '.');
        }
        if (preg_match('#^/services/(\d+)/(skip|unskip|intro|cuts|resetcuts|retry)$#', $path, $m)) {
            $id = (int)$m[1];
            $to = '/services/' . $id;
            if ($m[2] === 'skip' || $m[2] === 'unskip') {
                Services::setSkip($id, $m[2] === 'skip');
                self::back($to, $m[2] === 'skip' ? 'This service will not be recorded.' : 'This service will be recorded.');
            }
            if ($m[2] === 'intro') {
                Services::saveIntro($id, $_POST);
                self::back($to, 'Intro saved.');
            }
            if ($m[2] === 'cuts') {
                $s = Services::get($id);
                $date = $s ? fmt_local((int)$s['start_utc'], 'Y-m-d') : '';
                $a = strtotime($date . ' ' . (string)($_POST['start'] ?? ''));
                $b = strtotime($date . ' ' . (string)($_POST['end'] ?? ''));
                if ($a === false || $b === false) {
                    self::back($to, 'Enter both times.', 'bad');
                }
                $err = Services::saveCuts($id, (int)$a, (int)$b);
                self::back($to, $err ?? 'Times saved. The video will be processed next.', $err ? 'bad' : 'ok');
            }
            if ($m[2] === 'resetcuts') {
                Services::resetCuts($id);
                self::back($to, 'Using the light sensor times again.');
            }
            Services::retry($id);
            self::back($to, 'Queued again.');
        }
        if ($path === '/files/pin') {
            $on = Files::togglePin((string)($_POST['name'] ?? ''));
            self::back('/files', $on ? 'Kept. It will not be deleted automatically.' : 'No longer kept. It will be deleted on schedule.');
        }
        if ($path === '/files/delete' && $admin) {
            Files::delete((int)($_POST['id'] ?? 0));
            self::back('/files', 'Deleted.');
        }
        if ($path === '/settings/me') {
            $prefs = [];
            $in = isset($_POST['p']) && is_array($_POST['p']) ? $_POST['p'] : [];
            foreach (Notify::EVENTS as $ev => $_l) {
                $prefs[$ev] = ['email' => !empty($in[$ev]['email']), 'ntfy' => !empty($in[$ev]['ntfy'])];
            }
            Db::run('UPDATE users SET name = ?, email = ?, ntfy_topic = ?, prefs = ? WHERE id = ?',
                [trim((string)($_POST['name'] ?? '')) ?: $u['name'], trim((string)($_POST['email'] ?? '')),
                 preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['ntfy_topic'] ?? '')), json_encode($prefs), (int)$u['id']]);
            self::back('/settings', 'Saved.');
        }
        if ($admin && $path === '/admin/user') {
            $uid = Auth::createUser(trim((string)($_POST['name'] ?? '')) ?: 'New person', 'user', trim((string)($_POST['email'] ?? '')));
            self::html(Pages::settings('Person added.', Auth::linkUrl(Auth::createLink($uid))));
            return;
        }
        if ($admin && $path === '/admin/link') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if (Db::val('SELECT 1 FROM users WHERE id = ?', [$uid]) === null) {
                self::back('/settings', 'Unknown person.', 'bad');
            }
            self::html(Pages::settings('', Auth::linkUrl(Auth::createLink($uid))));
            return;
        }
        if ($admin && $path === '/admin/revoke') {
            Auth::revokeLink((int)($_POST['link_id'] ?? 0));
            self::back('/settings', 'Link turned off.');
        }
        http_response_code(404);
        self::html(View::layout('Not found', '<section class="card"><h1>Not allowed or not found</h1></section>'));
    }
}
