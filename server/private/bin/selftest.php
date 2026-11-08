<?php
/**
 * Self-test. Uses a throwaway in-memory database, so it is safe to run anywhere:
 *     php bin/selftest.php
 * Every line should say PASS. Send any FAIL lines to whoever is helping you.
 */
if (PHP_SAPI !== 'cli') {
    exit("Command line only\n");
}
define('APP_DIR', dirname(__DIR__));
require APP_DIR . '/src/bootstrap.php';

Env::set('APP_TIMEZONE', 'America/Detroit');
date_default_timezone_set('America/Detroit');
Env::set('BASE_URL', 'https://example.test/vm');
Env::set('BASE_PATH', '/vm');
Env::set('VIDEO_DIR', sys_get_temp_dir() . '/vm-selftest-' . bin2hex(random_bytes(4)));
Env::set('SHEET_ID', 'testsheet');
Env::set('INTRO_TITLE', 'Test Church Sermons');
// The self-test must not depend on your real .env, so every setting it relies on is pinned here.
foreach (['SHEET_URL', 'SHEET_GID', 'SHEET_CSV_URL', 'DEFAULT_SERVICE_TIME', 'SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'MAIL_FROM',
          'GOOGLE_APPS_SCRIPT_URL', 'YOUTUBE_PLAYLIST_ID'] as $k) {
    Env::set($k, '');
}
foreach ([
    'RECORD_LEAD_MINUTES' => '10', 'RECORD_MAX_MINUTES' => '100', 'CUT_LUX_ON' => '100', 'CUT_LUX_OFF' => '50',
    'CUT_MIN_SECONDS' => '180', 'CUT_MAX_SECONDS' => '5400', 'CUT_PAD_START_SECONDS' => '0', 'CUT_PAD_END_SECONDS' => '0',
    'CUT_WINDOW_BEFORE_MIN' => '20', 'CUT_WINDOW_AFTER_MIN' => '180', 'AUTO_LIGHT_RECORD' => '1', 'AUTO_LIGHT_MAX_MINUTES' => '30',
    'FILE_RETENTION_DAYS' => '14', 'AGENT_OFFLINE_MINUTES' => '60', 'ESP_OFFLINE_MINUTES' => '120', 'INTRO_SECONDS' => '5',
] as $k => $v) {
    Env::set($k, $v);
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}
function at(string $local): int
{
    return (new DateTimeImmutable($local, new DateTimeZone('America/Detroit')))->getTimestamp();
}

Db::open(':memory:');
check('schema creates tables', (int)Db::val("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'") >= 10);
$markedV3 = new PDO('sqlite::memory:');
$markedV3->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, service_id INTEGER, name TEXT NOT NULL, path TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0, created_utc INTEGER NOT NULL)');
$markedV3->exec('PRAGMA user_version = 3');
$migrate = new ReflectionMethod('Db', 'migrate');
$migrate->setAccessible(true);
$migrate->invoke(null, $markedV3);
$v3Files = array_column($markedV3->query('PRAGMA table_info(files)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check('version 3 migration repairs a missing YouTube publication table',
    in_array('public_token', $v3Files, true)
    && $markedV3->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'youtube_publications'")->fetchColumn() !== false);

// ---- light sensor cut detection against real recorded Sundays -----------------------------------------
$n = EspLog::import(APP_DIR . '/tests/fixtures/esp-sample.log');
check('imports sample esp.log', $n === 291, "imported $n");
$expected = [
    [1776610800, 1776612285, 1776613046],   // 2026-04-19, light 11:24:45 to 11:37:26
    [1778425200, 1778426929, 1778427778],   // 2026-05-10
    [1779030000, 1779031520, 1779032413],   // 2026-05-17
];
foreach ($expected as [$start, $a, $b]) {
    $r = Cuts::detect($start);
    check('cuts for ' . fmt_local($start, 'Y-m-d'), $r['status'] === 'ok' && $r['start'] === $a && $r['end'] === $b,
        json_encode($r));
}
$base = 1000000;
$mk = function (array $pairs) use ($base) {
    return array_map(function ($p) use ($base) {
        return ['ts_utc' => $base + $p[0], 'lux' => $p[1]];
    }, $pairs);
};
$r = Cuts::analyze($mk([[0, 5], [60, 200], [100, 10], [300, 180], [1000, 8]]));
check('short light flicker is ignored, real sermon chosen', $r['status'] === 'ok' && $r['start'] === $base + 300 && $r['end'] === $base + 1000, json_encode($r));
$r = Cuts::analyze($mk([[0, 150], [400, 5], [600, 150], [1500, 5]]));
check('two plausible cycles: longest wins and a note is added', $r['status'] === 'ok' && $r['end'] === $base + 1500 && strpos($r['note'], '2 light cycles') !== false, json_encode($r));
$r = Cuts::analyze($mk([[0, 5], [100, 150]]));
check('light still on is reported as waiting', $r['status'] === 'waiting', json_encode($r));
$r = Cuts::analyze($mk([[0, 5], [900, 6]]));
check('no light cycle is reported as none', $r['status'] === 'none', json_encode($r));

// ---- schedule parsing --------------------------------------------------------------------------------
$csv = "Date,Service Time,Subtitle,Text4,Description,Description2\r\n"
    . "\"Sunday, November 1, 2026\",10:30 AM,All Saints Day,Fourth line,\"Line one, with a comma\",Line two\r\n"
    . "\"Sunday, November 8, 2026\",10:30 AM,Twenty-Second Sunday,,,\r\n"
    . ",,,,,\r\n";
$rows = Schedule::parse($csv);
check('parses two service rows', count($rows) === 2, (string)count($rows));
check('parses the time in the church time zone (DST change day)', $rows[0]['start'] === at('2026-11-01 10:30'), (string)$rows[0]['start']);
check('keeps commas inside quoted cells', $rows[0]['description'] === 'Line one, with a comma');

Clock::$fixed = at('2026-10-31 12:00');
Http::$fetcher = function ($method, $url, $body, $headers) use ($csv) {
    return strpos($url, 'testsheet') !== false ? $csv : null;
};
$r = Schedule::refresh();
check('refresh reads the sheet', $r['ok'] && $r['count'] === 2, json_encode($r));
$next = Schedule::next();
check('next service is Nov 1', $next !== null && (int)$next['start_utc'] === at('2026-11-01 10:30'));
[$wa, $wb] = Schedule::window($next);
check('recording window is start-10 min to +100 min', $wa === at('2026-11-01 10:20') && $wb === at('2026-11-01 10:20') + 6000);
check('output name is the subtitle with hyphens', Schedule::outputName($next) === 'All-Saints-Day.mp4', Schedule::outputName($next));
check('intro carries sheet text and config', Schedule::intro($next)['text4'] === 'Fourth line' && Schedule::intro($next)['title'] === 'Test Church Sermons');
Services::saveIntro((int)$next['id'], ['subtitle' => 'All Saints Day (edited)']);
Schedule::refresh();
check('refresh does not overwrite an edited intro', Services::get((int)$next['id'])['subtitle'] === 'All Saints Day (edited)');

// ---- people, links, notifications ------------------------------------------------------------------------
$larry = Auth::createUser('Larry', 'admin', 'larry@example.test');
$pastor = Auth::createUser('Pastor', 'user', 'pastor@example.test');
$sessionToken = Auth::token();
$sessionCsrf = Auth::token();
Db::run('INSERT INTO sessions (user_id, token_hash, csrf, created_utc, last_seen_utc, expires_utc) VALUES (?, ?, ?, ?, ?, ?)',
    [$larry, Auth::hash($sessionToken), $sessionCsrf, Clock::now(), Clock::now(), Clock::now() + Auth::LIFETIME]);
$_COOKIE[Auth::COOKIE] = $sessionToken;
$tok = Auth::createLink($larry);
$u = Auth::redeem($tok);
check('magic link signs the right person in', $u !== null && $u['name'] === 'Larry');
check('link url uses BASE_URL', strpos(Auth::linkUrl($tok), 'https://example.test/vm/m/') === 0);
Auth::revokeLink((int)$u['link_id']);
check('revoked link stops working', Auth::redeem($tok) === null);
$sent = [];
Notify::$transport = function ($ch, $user, $title) use (&$sent) {
    $sent[] = $user['name'] . '|' . $ch . '|' . $title;
};
$titles = function (string $needle) use (&$sent): array {
    return array_values(array_filter($sent, function ($s) use ($needle) {
        return strpos($s, $needle) !== false;
    }));
};

// ---- a whole service, end to end ---------------------------------------------------------------------------
Clock::$fixed = at('2026-11-01 10:21');
$hb = Api::heartbeat(['version' => 't', 'state' => 'armed', 'recording' => false, 'files' => [['name' => 'raw1.mp4', 'kind' => 'raw', 'size' => 5, 'mtime_utc' => Clock::now()]]]);
check('heartbeat returns the next service', $hb['next_service'] !== null && $hb['next_service']['id'] === (int)$next['id']);
check('heartbeat stores the PC file list', (int)Db::val('SELECT COUNT(*) FROM agent_files') === 1);
$sid = (int)$next['id'];
Api::recording(['service_id' => $sid, 'event' => 'started', 'video_started_utc' => Clock::now(), 'video_file' => 'raw1.mp4']);
check('Larry is told recording started', count($titles('Larry|email|Recording started')) === 1);
check('the pastor is not told by default', count($titles('Pastor|')) === 0);
Services::saveIntro($sid, ['subtitle' => 'x']); // harmless; keeps intro_edited set

Clock::$fixed = at('2026-11-01 10:55');
Api::esp(['lux' => 150.5, 'duration' => 900000, 'timestamp' => Clock::now(), 'rssi' => -60, 'fw' => '2.0']);
$hb = Api::heartbeat(['state' => 'recording', 'recording' => true]);
check('no job while the light is still on', $hb['job'] === null);
Clock::$fixed = at('2026-11-01 11:08');
Api::esp(['lux' => 12, 'duration' => 800000, 'timestamp' => Clock::now()]);
$hb = Api::heartbeat(['state' => 'recording', 'recording' => true]);
check('status card shows the PC as recording', strpos(View::statusCard(), 'badge rec') !== false);
Api::heartbeat(['state' => 'idle', 'recording' => false]);
check('status card flags a PC that is not recording while the site says it is', strpos(View::statusCard(), 'Recording problem') !== false);
Api::heartbeat(['state' => 'recording', 'recording' => true]);
check('a job appears once the light turns off, even while still recording', $hb['job'] !== null
    && $hb['job']['cut_start_utc'] === at('2026-11-01 10:55') && $hb['job']['cut_end_utc'] === at('2026-11-01 11:08'), json_encode($hb['job']));
check('job carries the intro and file name', $hb['job']['intro']['title'] === 'Test Church Sermons' && $hb['job']['output_name'] === 'x.mp4', json_encode($hb['job']['output_name'] ?? null));

Api::job(['service_id' => $sid, 'state' => 'running', 'progress' => 40, 'message' => 'trimming']);
check('job shows as processing', Services::state(Services::get($sid))[0] === 'processing');
$live = Pages::serviceLive($sid);
check('service page live parts are produced', $live !== null && isset($live['head'], $live['video'], $live['cut'], $live['rec']));
check('live video section shows a progress bar while processing', strpos($live['video'], 'progressbar') !== false && strpos($live['video'], '40%') !== false);
check('whole service page renders', strpos(Pages::service($sid), 'id="svc-head"') !== false);
$hb = Api::heartbeat(['state' => 'processing', 'busy_service_id' => $sid]);
check('a running job is not offered again', $hb['job'] === null);
Api::recording(['service_id' => $sid, 'event' => 'stopped', 'ts_utc' => Clock::now()]);

$tmp = Files::dir() . '/up.part';
file_put_contents($tmp, str_repeat('v', 2048));
$up = Api::finishUpload($sid, 'x.mp4', $tmp, 2048);
check('upload is stored', !empty($up['ok']) && $up['size'] === 2048, json_encode($up));
$bad = Files::dir() . '/up2.part';
file_put_contents($bad, 'abc');
$up2 = Api::finishUpload($sid, 'bad.mp4', $bad, 999);
check('truncated upload is rejected', empty($up2['ok']));
$done = Api::job(['service_id' => $sid, 'state' => 'done']);
check('job done marks the video ready', !empty($done['ok']) && Services::get($sid)['status'] === 'ready');
check('Larry is told the video is ready', count($titles('Larry|email|Video ready')) === 1);

// Pastor opts in to two events.
Db::run('UPDATE users SET prefs = ? WHERE id = ?', [json_encode(['video_ready' => ['email' => true, 'ntfy' => false]]), $pastor]);
check('pastor preferences are honoured', Notify::prefsFor(Db::one('SELECT * FROM users WHERE id = ?', [$pastor]))['video_ready']['email'] === true);

// ---- offline alerts ------------------------------------------------------------------------------------------
Monitor::seen('agent', []);
Clock::$fixed += 61 * 60;
$sent = [];
Monitor::checkDevices();
check('offline alert fires once the PC is silent for an hour', count($titles('Windows PC has not checked in')) === 1, json_encode($sent));
Monitor::checkDevices();
check('and does not repeat', count($titles('Windows PC has not checked in')) === 1);
check('sensor (2 hour limit) is not flagged yet', count($titles('light sensor')) === 0);
Monitor::seen('agent', []);
check('a back-online message follows recovery', count($titles('is back online')) === 1);
Monitor::setSilenced('agent', true);
Clock::$fixed += 3 * 3600;
$sent = [];
Monitor::checkDevices();
check('silenced device stays quiet', count($titles('Windows PC')) === 0);

// ---- retention and pins ------------------------------------------------------------------------------------------
$fileName = Db::val('SELECT name FROM files LIMIT 1');
check('file is kept before two weeks', Files::cleanup() === []);
Clock::$fixed += 15 * 86400;
Files::togglePin((string)$fileName);
check('pinned file survives cleanup', Files::cleanup() === [] && (int)Db::val('SELECT COUNT(*) FROM files') === 1);
Files::togglePin((string)$fileName);
check('expired file is deleted', count(Files::cleanup()) === 1 && (int)Db::val('SELECT COUNT(*) FROM files') === 0);

// ---- YouTube publishing ------------------------------------------------------------------------------------------
Env::set('YOUTUBE_PLAYLIST_ID', 'playlist-test');
Env::set('GOOGLE_APPS_SCRIPT_URL', 'http://script.google.com/macros/s/test/exec');
check('publisher refuses a non-HTTPS endpoint', !YouTubePublisher::isConfigured());
Env::set('GOOGLE_APPS_SCRIPT_URL', 'https://script.google.com/macros/s/test/exec');
$makePublishFile = function (string $name) use ($sid): int {
    $tmp = Files::dir() . '/publish-' . bin2hex(random_bytes(4)) . '.part';
    file_put_contents($tmp, 'video');
    $r = Files::register($sid, $name, $tmp);
    return (int)$r['file_id'];
};
$publishedFileId = $makePublishFile('publish.mp4');
$requests = [];
$todayBefore = Pages::dashboard();
$serviceBefore = Pages::service($sid);
check('admin sees publish buttons on Today and the service page',
    strpos($todayBefore, 'Publish to YouTube') !== false
    && strpos($serviceBefore, 'Publish to YouTube') !== false);
Http::$fetcher = function ($method, $url, $body, $headers) use (&$requests) {
    $requests[] = [$method, $url, $body, $headers];
    return '{"ok":true,"videoId":"yt-test-123"}';
};
$published = YouTubePublisher::publish($publishedFileId);
$publishedPayload = json_decode($requests[0][2], true);
$publicPath = (string)parse_url($publishedPayload['url'] ?? '', PHP_URL_PATH);
preg_match('#/public-video/' . $publishedFileId . '/([A-Za-z0-9_-]{43})$#', $publicPath, $tokenMatch);
check('publisher sends metadata and a scoped public video URL',
    $published['ok'] && $requests[0][0] === 'POST'
    && $requests[0][1] === 'https://script.google.com/macros/s/test/exec'
    && ($publishedPayload['url'] ?? '') === ($publishedPayload['videoUrl'] ?? '')
    && ($publishedPayload['playlistId'] ?? '') === 'playlist-test'
    && ($publishedPayload['title'] ?? '') === Services::label(Services::get($sid))
    && isset($tokenMatch[1]), json_encode($publishedPayload));
$todayPage = Pages::dashboard();
$servicePage = Pages::service($sid);
check('published status removes repeat buttons from both pages',
    strpos($todayPage, 'Published to YouTube') !== false
    && strpos($servicePage, 'Published to YouTube') !== false
    && strpos($todayPage, 'Publish to YouTube') === false
    && strpos($servicePage, 'Publish to YouTube') === false);
check('public video token grants access only to its registered file',
    isset($tokenMatch[1]) && Files::publicFile($publishedFileId, $tokenMatch[1]) !== null
    && Files::publicFile($publishedFileId, str_repeat('x', 43)) === null);
$duplicate = YouTubePublisher::publish($publishedFileId);
check('published video cannot be submitted twice', !$duplicate['ok'] && count($requests) === 1
    && YouTubePublisher::status($publishedFileId)['state'] === 'published');
Files::delete($publishedFileId);
$replacementFileId = $makePublishFile('publish.mp4');
$replacementDuplicate = YouTubePublisher::publish($replacementFileId);
check('published state survives deleting and re-uploading the same service file',
    !$replacementDuplicate['ok'] && count($requests) === 1
    && YouTubePublisher::status($replacementFileId)['state'] === 'published');

$failedFileId = $makePublishFile('failed-publish.mp4');
$attempt = 0;
Http::$fetcher = function ($method, $url, $body, $headers) use (&$attempt) {
    $attempt++;
    return $attempt === 1 ? '{"ok":false,"error":"Quota exceeded"}' : '{"ok":true,"videoId":"yt-retry-1"}';
};
$failed = YouTubePublisher::publish($failedFileId);
$retried = YouTubePublisher::publish($failedFileId);
check('only a confirmed failure can be retried', !$failed['ok'] && $retried['ok'] && $attempt === 2
    && YouTubePublisher::status($failedFileId)['state'] === 'published');

$unknownFileId = $makePublishFile('unknown-publish.mp4');
$unknownAttempts = 0;
Http::$fetcher = function () use (&$unknownAttempts) {
    $unknownAttempts++;
    return null;
};
$uncertain = YouTubePublisher::publish($unknownFileId);
$blocked = YouTubePublisher::publish($unknownFileId);
check('ambiguous outcome blocks another publishing request',
    !$uncertain['ok'] && !$blocked['ok'] && $unknownAttempts === 1
    && YouTubePublisher::status($unknownFileId)['state'] === 'unknown');
$unknownPage = Pages::service($sid);
check('admin sees a guarded retry action after an unknown outcome',
    strpos($unknownPage, 'Retry after checking YouTube') !== false
    && strpos($unknownPage, 'confirmed this video was not uploaded') !== false);
$unknownAttempts = 0;
Http::$fetcher = function () use (&$unknownAttempts) {
    $unknownAttempts++;
    return '{"ok":true,"videoId":"yt-manual-retry"}';
};
$manualRetry = YouTubePublisher::retryUnknown($unknownFileId);
check('admin-confirmed unknown outcome can be retried',
    $manualRetry['ok'] && $unknownAttempts === 1
    && YouTubePublisher::status($unknownFileId)['state'] === 'published');
Http::$fetcher = null;
Env::set('GOOGLE_APPS_SCRIPT_URL', '');
Env::set('YOUTUBE_PLAYLIST_ID', '');

// ---- controls ------------------------------------------------------------------------------------------------------------
Clock::$fixed = at('2026-11-02 09:00');
Schedule::refresh();
Db::run('UPDATE services SET skip = 0');
$nx = Schedule::next();
check('next service is the following Sunday', $nx !== null && (int)$nx['start_utc'] === at('2026-11-08 10:30'));
Services::setSkip((int)$nx['id'], true);
check('skipped service is not offered', Schedule::next() === null);
Services::setSkip((int)$nx['id'], false);
$s = Services::startNow();
check('start now creates a manual recording when nothing is near', $s['subtitle'] === 'Manual recording' && (int)$s['rec_start_utc'] === Clock::now());
check('stop now ends the window', Services::stopNow() && Schedule::window(Services::get((int)$s['id']))[1] === Clock::now());
Api::recording(['service_id' => (int)$s['id'], 'event' => 'started', 'video_started_utc' => Clock::now()]);
$seenBefore = (int)Monitor::device('esp')['last_seen_utc'];
Env::set('AUTO_LIGHT_RECORD', '0'); // this test is about sermon times, not automatic recording
Cuts::simulate(true);
Clock::$fixed += 240;
Cuts::simulate(false);
$sv = Services::get((int)$s['id']);
check('pretend light on then off produces sermon times', $sv['job_state'] === 'pending' && (int)$sv['cut_end_utc'] - (int)$sv['cut_start_utc'] === 240, json_encode([$sv['job_state'], $sv['cut_start_utc'], $sv['cut_end_utc']]));
Env::set('AUTO_LIGHT_RECORD', '1');
check('pretend readings do not count as the sensor checking in', (int)Monitor::device('esp')['last_seen_utc'] === $seenBefore);
$t = Services::createTest();
$hb = Api::heartbeat([]);
check('test recording is offered to the PC', $hb['next_service']['id'] === $t && $hb['next_service']['is_test'] === 1);

// ---- one-time notices (no message text in the address bar) ------------------------------------------------------
[$fm, $ft] = Flash::decode(Flash::encode('Saved. Cafe & "quotes" <b>', 'bad'));
check('flash notice survives the cookie round trip', $fm === 'Saved. Cafe & "quotes" <b>' && $ft === 'bad', $fm . '|' . $ft);
check('a damaged flash cookie is ignored', Flash::decode('not-base64!!') === ['', 'ok']);

// ---- spreadsheet link ---------------------------------------------------------------------------------------
check('sheet link is built from SHEET_ID', Schedule::sheetUrl() === 'https://docs.google.com/spreadsheets/d/testsheet/edit#gid=0', (string)Schedule::sheetUrl());
Env::set('SHEET_ID', '');
Env::set('SHEET_URL', 'https://docs.google.com/spreadsheets/d/abc123_-X/edit?gid=987#gid=987');
check('SHEET_URL supplies the sheet id', Schedule::sheetId() === 'abc123_-X', Schedule::sheetId());
check('SHEET_URL supplies the tab', Schedule::sheetGid() === '987', Schedule::sheetGid());
check('SHEET_URL is the link shown on the site', Schedule::sheetUrl() === Env::get('SHEET_URL'));
Env::set('SHEET_URL', '');
Env::set('SHEET_ID', 'testsheet');

// ---- email --------------------------------------------------------------------------------------------------------
Env::set('SMTP_HOST', '127.0.0.1');
Env::set('SMTP_PORT', '1');
check('an unreachable mail server is reported, not thrown', Mailer::send('a@example.test', 's', 'b') === false && Mailer::$lastError !== '', Mailer::$lastError);
Env::set('SMTP_HOST', '');

// ---- automatic recording when the light comes on with nothing scheduled --------------------------------------------------
Db::run('DELETE FROM services WHERE auto_light = 1');
$sent = [];
$autoCount = function (): int {
    return (int)Db::val('SELECT COUNT(*) FROM services WHERE auto_light = 1');
};
Clock::$fixed = at('2026-11-08 09:55'); // the scheduled recording opens in 25 minutes
Cuts::simulate(false);
Cuts::simulate(true);
check('no automatic recording when a scheduled one starts soon', $autoCount() === 0);
Clock::$fixed = at('2026-11-08 10:25'); // inside the scheduled window
Cuts::simulate(false);
Cuts::simulate(true);
check('no automatic recording while a scheduled recording is open', $autoCount() === 0);

Clock::$fixed = at('2026-11-20 13:59');
Cuts::simulate(false);
Clock::$fixed = at('2026-11-20 14:00');
Cuts::simulate(true);
$auto = Db::one('SELECT * FROM services WHERE auto_light = 1');
check('light on with nothing scheduled starts a recording', $auto !== null && (int)$auto['rec_start_utc'] <= Clock::now()
    && (int)$auto['rec_stop_utc'] === Clock::now() + 1800, json_encode($auto));
check('the PC is told to record it', Schedule::next() !== null && (int)Schedule::next()['id'] === (int)$auto['id']);
check('Larry is told it started automatically', count($titles('Recording started automatically')) === 1);
Clock::$fixed = at('2026-11-20 14:05');
Cuts::simulate(true);
check('light staying on does not start a second recording', $autoCount() === 1);
Clock::$fixed = at('2026-11-20 14:10');
Cuts::simulate(false);
check('light going off ends the recording', Schedule::window(Services::get((int)$auto['id']))[1] === Clock::now());
Env::set('AUTO_LIGHT_RECORD', '0');
Clock::$fixed = at('2026-11-21 14:00');
Cuts::simulate(false);
Cuts::simulate(true);
check('automatic recording can be switched off', $autoCount() === 1);
Env::set('AUTO_LIGHT_RECORD', '1');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);