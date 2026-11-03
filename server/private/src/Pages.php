<?php
/** Web pages. Each method returns a full HTML string; App routes to them. */
final class Pages
{
    private static function flash(string $msg, string $tone = 'ok'): string
    {
        return $msg === '' ? '' : '<p class="flash ' . h($tone) . '" role="status">' . h($msg) . '</p>';
    }

    // ---- sign-in -------------------------------------------------------------------------

    public static function login(): string
    {
        return View::layout('Sign in', '<section class="card narrow"><h1>Sermon video</h1>'
            . '<p>This site is private. Open the personal link you were sent to sign in on this device.</p>'
            . '<p class="muted">Lost it? Ask the person who runs the church video for a new link.</p></section>');
    }

    public static function magic(string $method, string $token): string
    {
        $user = Auth::redeem($token);
        if ($user === null) {
            http_response_code(404);
            return View::layout('Link not valid', '<section class="card narrow"><h1>This link has stopped working</h1>'
                . '<p>It may have been replaced or turned off. Ask for a new link.</p></section>');
        }
        if ($method === 'POST') {
            Auth::start($user, (int)$user['link_id']);
            redirect('/');
        }
        $form = '<form method="post" action="' . h(base_path() . '/m/' . $token) . '"><button class="btn primary block">Open on this device</button></form>';
        return View::layout('Sign in', '<section class="card narrow"><h1>Hello, ' . h($user['name']) . '</h1>'
            . '<p>Tap the button to stay signed in on this phone or computer.</p>' . $form . '</section>');
    }

    /** First-run: create the admin account with SETUP_KEY. Only works while there are no users. */
    public static function setup(string $method): string
    {
        $key = (string)Env::get('SETUP_KEY', '');
        $given = (string)($_GET['key'] ?? $_POST['key'] ?? '');
        if ((int)Db::val('SELECT COUNT(*) FROM users') > 0 || $key === '' || !hash_equals($key, $given)) {
            http_response_code(404);
            return View::layout('Not found', '<section class="card narrow"><h1>Not found</h1></section>');
        }
        if ($method === 'POST') {
            $name = trim((string)($_POST['name'] ?? '')) ?: 'Admin';
            $uid = Auth::createUser($name, 'admin', trim((string)($_POST['email'] ?? '')));
            $link = Auth::linkUrl(Auth::createLink($uid));
            return View::layout('Admin created', '<section class="card narrow"><h1>Admin created</h1>'
                . '<p>Open this link on your phone to sign in. It is shown once.</p>'
                . '<p class="linkbox">' . h($link) . '</p>'
                . '<p class="muted">After signing in, add your pastor from Settings and send him his own link.</p></section>');
        }
        return View::layout('First-time setup', '<section class="card narrow"><h1>First-time setup</h1>'
            . '<form method="post" class="form"><input type="hidden" name="key" value="' . h($given) . '">'
            . '<label>Your name<input name="name" required></label><label>Your email (for notifications)<input name="email" type="email"></label>'
            . '<button class="btn primary block">Create admin</button></form></section>');
    }

    // ---- dashboard -----------------------------------------------------------------------

    public static function dashboard(string $msg = ''): string
    {
        $next = Schedule::next();
        $body = self::flash($msg) . '<div id="status-wrap">' . View::statusCard() . '</div>';

        if ($next !== null) {
            [$a, $b] = Schedule::window($next);
            $intro = Schedule::intro($next);
            $body .= '<section class="card"><p class="kicker">Next service</p><h2>' . h(Services::label($next)) . '</h2>'
                . '<p>' . h(fmt_local((int)$next['start_utc'])) . '. Recording window ' . h(fmt_local($a, 'g:i A')) . ' to ' . h(fmt_local($b, 'g:i A'))
                . '. <span data-countdown="' . $a . '"></span></p>'
                . View::introPreview($intro)
                . '<div class="actions"><a class="btn" href="' . h(base_path() . '/services/' . (int)$next['id']) . '">Edit intro and times</a>'
                . ($next['status'] === 'scheduled' ? View::post('/services/' . (int)$next['id'] . '/skip', 'Skip this service', 'btn', 'Skip recording this service?') : '')
                . '</div></section>';
        } else {
            $err = Db::setting('schedule_error', '');
            $body .= '<section class="card"><h2>No upcoming service</h2><p>' . ($err !== '' ? h($err) : 'The schedule has nothing coming up. Add rows to the Google Sheet, then refresh.') . '</p></section>';
        }

        $body .= '<section class="card"><h2>Controls</h2><div class="actions">'
            . View::post('/actions/start', 'Start recording now', 'btn primary', 'Start recording right now?')
            . View::post('/actions/stop', 'Stop recording now', 'btn danger', 'Stop the recording now?')
            . View::post('/actions/refresh', 'Refresh schedule', 'btn')
            . (Auth::isAdmin() ? View::post('/actions/test', 'Run a test recording', 'btn', 'Record a 100 second test? The Windows PC will record, trim and upload it.') : '')
            . '</div>'
            . (Auth::isAdmin() ? '<p class="muted">Pretend the pulpit light (for testing):</p><div class="actions">'
                . View::post('/actions/light', 'Pretend light ON', 'btn', '', ['state' => 'on', 'return' => '/'])
                . View::post('/actions/light', 'Pretend light OFF', 'btn', '', ['state' => 'off', 'return' => '/']) . '</div>' : '')
            . '</section>';

        $ready = Services::latestReady();
        if ($ready !== null) {
            $body .= '<section class="card"><p class="kicker">Latest video</p><h2>' . h(Services::label($ready)) . '</h2>'
                . self::fileButtons((int)$ready['id']) . '</section>';
        }
        return View::layout('Today', $body, 'dash');
    }

    private static function fileButtons(int $serviceId): string
    {
        $out = '';
        foreach (Files::forService($serviceId) as $f) {
            $u = h(base_path() . '/download/' . (int)$f['id']);
            $out .= '<p class="file"><strong>' . h($f['name']) . '</strong><br><span class="muted">' . h(fmt_bytes((int)$f['size'])) . ', deletes '
                . h(fmt_local(Files::expiresAt((int)$f['created_utc']), 'M j')) . '</span></p>'
                . '<div class="actions"><a class="btn primary" href="' . $u . '">Download</a><a class="btn" href="' . $u . '?inline=1">Watch</a></div>';
        }
        return $out !== '' ? $out : '<p class="muted">No file on the server yet.</p>';
    }

    // ---- services ------------------------------------------------------------------------

    public static function services(): string
    {
        $row = function (array $s): string {
            return '<li><a href="' . h(base_path() . '/services/' . (int)$s['id']) . '"><span><strong>' . h(Services::label($s)) . '</strong><br>'
                . '<span class="muted">' . h(fmt_local((int)$s['start_utc'])) . '</span></span>' . View::badge(Services::state($s)) . '</a></li>';
        };
        $up = array_map($row, Services::upcoming(8));
        $past = array_map($row, Services::past(20));
        $body = '<h1>Services</h1><section class="card"><h2>Upcoming</h2><ul class="list">' . ($up ? implode('', $up) : '<li class="muted">Nothing scheduled.</li>') . '</ul></section>'
            . '<section class="card"><h2>Past</h2><ul class="list">' . ($past ? implode('', $past) : '<li class="muted">Nothing yet.</li>') . '</ul></section>';
        return View::layout('Services', $body, 'services');
    }

    public static function service(int $id, string $msg = '', string $tone = 'ok'): string
    {
        $s = Services::get($id);
        if ($s === null) {
            http_response_code(404);
            return View::layout('Not found', '<section class="card"><h1>Service not found</h1></section>', 'services');
        }
        $state = Services::state($s);
        [$ra, $rb] = Schedule::window($s);
        $intro = Schedule::intro($s);
        $b = h(base_path());
        $body = '<p><a href="' . $b . '/services">Back to services</a></p>' . self::flash($msg, $tone)
            . '<h1>' . h(Services::label($s)) . '</h1><p>' . View::badge($state) . ' ' . h(fmt_local((int)$s['start_utc'])) . '</p>'
            . '<section class="card"><h2>Video</h2>' . self::fileButtons($id)
            . ($s['job_state'] === 'failed' ? '<p class="flash bad">' . h($s['job_msg']) . '</p>' . View::post("/services/$id/retry", 'Try processing again', 'btn') : '')
            . '</section>';

        // Intro editor with live preview.
        $body .= '<section class="card"><h2>Intro</h2>' . View::introPreview($intro)
            . '<form method="post" action="' . $b . '/services/' . $id . '/intro" class="form" data-live>' . View::csrf()
            . '<label>Subtitle<input name="subtitle" data-f="subtitle" value="' . h($s['subtitle']) . '"></label>'
            . '<label>Date line (blank = automatic)<input name="date_text" data-f="date" value="' . h($s['date_text']) . '" placeholder="' . h(fmt_local((int)$s['start_utc'], 'F j, Y')) . '"></label>'
            . '<label>Description<input name="description" data-f="description" value="' . h($s['description']) . '"></label>'
            . '<label>Description, second line<input name="description2" data-f="description2" value="' . h($s['description2']) . '"></label>'
            . '<label>Bottom line<input name="text4" data-f="text4" value="' . h($s['text4']) . '"></label>'
            . '<button class="btn">Save intro</button></form>'
            . ((int)$s['intro_edited'] === 1 && (int)$s['is_sheet'] === 1 ? '<p class="muted">Edited here, so changes to the Google Sheet will not overwrite this service.</p>' : '')
            . '</section>';

        // Cut times.
        $cs = $s['cut_start_utc'] !== null ? (int)$s['cut_start_utc'] : null;
        $ce = $s['cut_end_utc'] !== null ? (int)$s['cut_end_utc'] : null;
        $body .= '<section class="card"><h2>Sermon start and end</h2>';
        if ($cs !== null && $ce !== null) {
            $body .= '<p>' . h(fmt_local($cs, 'g:i:s A')) . ' to ' . h(fmt_local($ce, 'g:i:s A')) . ', ' . h(fmt_duration($ce - $cs)) . ' long. '
                . ($s['cut_source'] === 'manual' ? 'Set by hand.' : ($s['cut_source'] === 'test' ? 'Test cut.' : 'Found from the pulpit light.')) . '</p>';
        } else {
            $body .= '<p>Not known yet.</p>';
        }
        if ($s['cut_note'] !== '') {
            $body .= '<p class="muted">' . h($s['cut_note']) . '</p>';
        }
        $body .= '<form method="post" action="' . $b . '/services/' . $id . '/cuts" class="form two">' . View::csrf()
            . '<label>Start<input type="time" step="1" name="start" value="' . ($cs !== null ? h(fmt_local($cs, 'H:i:s')) : '') . '" required></label>'
            . '<label>End<input type="time" step="1" name="end" value="' . ($ce !== null ? h(fmt_local($ce, 'H:i:s')) : '') . '" required></label>'
            . '<button class="btn">Use these times and process</button></form>'
            . ($s['cut_source'] === 'manual' ? View::post("/services/$id/resetcuts", 'Go back to the light sensor times', 'btn') : '')
            . (Auth::isAdmin() ? '<p class="muted">Pretend the pulpit light (the light must stay on for at least '
                . h((string)Env::int('CUT_MIN_SECONDS', 180)) . ' seconds to count as a sermon):</p><div class="actions">'
                . View::post('/actions/light', 'Pretend light ON', 'btn small', '', ['state' => 'on', 'return' => "/services/$id"])
                . View::post('/actions/light', 'Pretend light OFF', 'btn small', '', ['state' => 'off', 'return' => "/services/$id"]) . '</div>' : '');
        [$from, $to] = Cuts::window((int)$s['start_utc']);
        $ev = Cuts::events($from, $to);
        $evHtml = '';
        foreach ($ev as $e) {
            $evHtml .= '<li class="' . ((float)$e['lux'] > (float)Env::get('CUT_LUX_ON', '100') ? 'hi' : '') . '">' . h(fmt_local((int)$e['ts_utc'], 'g:i:s A')) . ' reading ' . h(round((float)$e['lux'])) . ' lux' . (($e['fw'] ?? '') === 'manual' ? ' (pretend)' : '') . '</li>';
        }
        $body .= '<details><summary>Light sensor readings (' . count($ev) . ')</summary><ul class="events">' . ($evHtml ?: '<li>No readings in the window.</li>') . '</ul></details></section>';

        // Recording window / skip.
        $vs = $s['video_started_utc'] !== null ? (int)$s['video_started_utc'] : null;
        $vend = $s['recording_stopped_utc'] !== null ? (int)$s['recording_stopped_utc'] : null;
        if ($vs !== null) {
            $actual = '<p><strong>Recorded</strong> ' . h(fmt_local($vs, 'g:i:s A')) . ' to '
                . ($vend !== null && $vend >= $vs ? h(fmt_local($vend, 'g:i:s A')) . ' (' . h(fmt_duration($vend - $vs)) . ')' : 'now, still recording') . '.</p>';
        } else {
            $actual = '<p class="muted">Not recorded yet.</p>';
        }
        $body .= '<section class="card"><h2>Recording</h2>' . $actual . '<p class="muted">Planned window ' . h(fmt_local($ra, 'g:i:s A')) . ' to ' . h(fmt_local($rb, 'g:i:s A')) . '.</p><div class="actions">'
            . ((int)$s['skip'] === 1 ? View::post("/services/$id/unskip", 'Record this service again', 'btn')
               : ($s['status'] === 'scheduled' ? View::post("/services/$id/skip", 'Skip this service', 'btn', 'Skip recording this service?') : ''))
            . '</div></section>';
        return View::layout(Services::label($s), $body, 'services');
    }

    // ---- files ---------------------------------------------------------------------------

    public static function files(string $msg = ''): string
    {
        $pins = array_flip(Files::pinnedNames());
        $days = Files::retentionDays();
        $body = '<h1>Files</h1>' . self::flash($msg) . '<p class="muted">Everything is deleted automatically after ' . $days . ' days unless you keep it.</p>';

        $srv = '';
        foreach (Files::allServerFiles() as $f) {
            $pinned = isset($pins[$f['name']]);
            $srv .= '<li><span><strong>' . h($f['name']) . '</strong><br><span class="muted">' . h(fmt_bytes((int)$f['size'])) . ', '
                . ($pinned ? 'kept' : 'deletes ' . h(fmt_local(Files::expiresAt((int)$f['created_utc']), 'M j, g:i A'))) . '</span></span>'
                . '<span class="actions"><a class="btn small" href="' . h(base_path() . '/download/' . (int)$f['id']) . '">Download</a>'
                . View::post('/files/pin', $pinned ? 'Stop keeping' : 'Keep', 'btn small', '', ['name' => $f['name']])
                . (Auth::isAdmin() ? View::post('/files/delete', 'Delete', 'btn small danger', 'Delete this file from the server now?', ['id' => (string)$f['id']]) : '')
                . '</span></li>';
        }
        $body .= '<section class="card"><h2>On the web server</h2><ul class="list">' . ($srv ?: '<li class="muted">No files.</li>') . '</ul></section>';

        $pc = '';
        foreach (Db::all('SELECT * FROM agent_files ORDER BY mtime_utc DESC') as $f) {
            $pinned = isset($pins[$f['name']]);
            $pc .= '<li><span><strong>' . h($f['name']) . '</strong><br><span class="muted">' . h($f['kind']) . ', ' . h(fmt_bytes((int)$f['size'])) . ', '
                . ($pinned ? 'kept' : 'deletes ' . h(fmt_local((int)$f['mtime_utc'] + $days * 86400, 'M j, g:i A'))) . '</span></span>'
                . '<span class="actions">' . View::post('/files/pin', $pinned ? 'Stop keeping' : 'Keep', 'btn small', '', ['name' => $f['name']]) . '</span></li>';
        }
        $agent = Monitor::device('agent');
        $body .= '<section class="card"><h2>On the Windows PC</h2>'
            . '<p class="muted">As of the last check-in' . ($agent && $agent['last_seen_utc'] ? ' (' . h(ago((int)$agent['last_seen_utc'])) . ')' : '') . '.</p>'
            . '<ul class="list">' . ($pc ?: '<li class="muted">No files reported.</li>') . '</ul></section>';
        return View::layout('Files', $body, 'files');
    }

    // ---- settings ------------------------------------------------------------------------

    public static function settings(string $msg = '', string $newLink = ''): string
    {
        $u = Auth::user();
        $prefs = Notify::prefsFor($u);
        $b = h(base_path());
        $body = '<h1>Settings</h1>' . self::flash($msg);
        if ($newLink !== '') {
            $body .= '<section class="card highlight"><h2>New sign-in link</h2><p>Send this to them in a text. It is shown only once.</p>'
                . '<p class="linkbox" id="newlink">' . h($newLink) . '</p><div class="actions"><button class="btn" data-copy="#newlink">Copy link</button>'
                . '<button class="btn" data-share="#newlink">Share</button></div></section>';
        }

        $rows = '';
        foreach (Notify::EVENTS as $ev => $label) {
            $rows .= '<tr><th scope="row">' . h($label) . '</th><td><input type="checkbox" name="p[' . h($ev) . '][email]" value="1"' . ($prefs[$ev]['email'] ? ' checked' : '') . ' aria-label="Email"></td>'
                . '<td><input type="checkbox" name="p[' . h($ev) . '][ntfy]" value="1"' . ($prefs[$ev]['ntfy'] ? ' checked' : '') . ' aria-label="Phone push"></td></tr>';
        }
        $body .= '<section class="card"><h2>My notifications</h2><form method="post" action="' . $b . '/settings/me" class="form">' . View::csrf()
            . '<label>Name<input name="name" value="' . h($u['name']) . '" required></label>'
            . '<label>Email<input type="email" name="email" value="' . h($u['email']) . '"></label>'
            . '<label>Phone push topic (ntfy app)<input name="ntfy_topic" value="' . h($u['ntfy_topic']) . '" autocapitalize="off" autocorrect="off" placeholder="a long random word"></label>'
            . '<div class="tablewrap"><table class="prefs"><thead><tr><th></th><th>Email</th><th>Push</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<button class="btn primary">Save</button></form>'
            . '<p class="muted">For push on iPhone, install the free ntfy app, subscribe to the topic above, and keep the topic private.</p></section>';

        if (Auth::isAdmin()) {
            $body .= '<section class="card"><h2>People</h2><ul class="list">';
            foreach (Db::all('SELECT * FROM users ORDER BY id') as $p) {
                $links = Db::all('SELECT * FROM links WHERE user_id = ? AND revoked_utc IS NULL ORDER BY id', [(int)$p['id']]);
                $body .= '<li class="stack"><span><strong>' . h($p['name']) . '</strong> <span class="muted">' . h($p['role']) . ', ' . count($links) . ' active link(s)</span></span><span class="actions">'
                    . View::post('/admin/link', 'New link', 'btn small', '', ['user_id' => (string)$p['id']]);
                foreach ($links as $l) {
                    $body .= View::post('/admin/revoke', 'Turn off link from ' . fmt_local((int)$l['created_utc'], 'M j'), 'btn small danger', 'Turn off this link? Anyone signed in with it is signed out.', ['link_id' => (string)$l['id']]);
                }
                $body .= '</span></li>';
            }
            $body .= '</ul><form method="post" action="' . $b . '/admin/user" class="form two">' . View::csrf()
                . '<label>Add a person<input name="name" placeholder="Name" required></label><label>Email<input type="email" name="email"></label>'
                . '<button class="btn">Add and make a link</button></form></section>';
            $sched = Db::setting('schedule_error', '');
            $ok = (int)Db::setting('schedule_ok_utc', '0');
            $body .= '<section class="card"><h2>System</h2><p>Schedule last read ' . h($ok ? ago($ok) : 'never') . '.' . ($sched !== '' ? ' <span class="bad-text">' . h($sched) . '</span>' : '') . '</p></section>';
        }
        $body .= '<section class="card">' . View::post('/logout', 'Sign out on this device', 'btn') . '</section>';
        return View::layout('Settings', $body, 'settings');
    }
}
