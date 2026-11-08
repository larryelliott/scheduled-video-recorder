# Install guide

You will set up three things: the **web server**, the **Windows PC**, and the **light sensor**. Do them in that order.
Budget an evening for the first two and a few minutes for the sensor.

## 0. Before you start

- A shared web host with PHP 8.1 or newer, SSH access, cron, and HTTPS (written for DreamHost Shared).
- The Windows PC that runs OBS (OBS 28 or newer; hybrid MP4 recording is fine).
- Python 3.10+ and ffmpeg on that PC. Check with `python --version` and `ffmpeg -version` in PowerShell.
- Your schedule as a Google Sheet (see [SHEET.md](SHEET.md)), shared as "anyone with the link can view".
- Make three random secrets (run it three times, in PowerShell with PHP, or on the server over SSH):
  `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"`  Name them `AGENT_TOKEN`, `ESP_TOKEN`, `SETUP_KEY`.

## 1. Web server

1. **Make the settings file.** Copy `server/private/.env.example` to `server/private/.env` and fill it in (it is commented).
   To enable YouTube publishing, set `GOOGLE_APPS_SCRIPT_URL` to the HTTPS web-app URL and `YOUTUBE_PLAYLIST_ID` to the destination playlist.
   `YOUTUBE_PUBLISH_TIMEOUT` controls the outbound request timeout in seconds (default 120, capped at 300).
   The Apps Script endpoint must accept a JSON POST with `url`, `title`, `description`, and `playlistId` (`videoUrl` is also sent as an alias); return JSON with
   `{"ok":true,"videoId":"..."}` after a confirmed upload, or `{"ok":false,"error":"..."}` only when it knows the upload failed.
   Transport errors or unrecognized responses are treated as uncertain and cannot be retried in the app, to prevent duplicate uploads.
   The server provides the selected video through an unguessable, per-video URL that works without an app sign-in; do not share that URL beyond the publishing integration.
2. **Upload two folders over SFTP.** Never put the private folder inside a web folder.
   - `server/private/` to `~/video-manager-private/` (your home folder, next to your domain folders, not inside them)
   - the **contents** of `server/public/` to `~/<your-domain>/video-manager/`, including `.htaccess`
3. **Check the PHP version** in the DreamHost panel (Websites, Manage, PHP version for the domain: 8.1 or newer).
4. **Run the self-test** over SSH. Every line must say PASS:
   `php ~/video-manager-private/bin/selftest.php`
   If PHP on the command line is a different version, DreamHost lists the full path for each version (for example `/usr/local/php83/bin/php`).
5. **Create the first admin.** Visit `https://<your-domain>/video-manager/setup?key=<SETUP_KEY>`, enter your name and email, then open the link it shows on your phone
   and tap **Open on this device**. (This page stops working once a person exists.)
6. **Cron.** In the DreamHost panel (Advanced, Cron Jobs) add a job that runs hourly:
   `php /home/<user>/video-manager-private/bin/cron.php`
   It does nothing between 9 PM and 8 AM. Run it once by hand with `--force` to see it work.
7. **Add the pastor.** On your phone: Settings, People, add his name and email, tap **Share** on the link it shows, and text it to him.
   He opens it and taps one button. He starts with all notifications off; he can turn on the ones he wants in Settings.
8. **Optional: bring in history.** `php ~/video-manager-private/bin/import-esp-log.php /path/to/esp.log`

### Phone push notifications (iPhone)
Install the free **ntfy** app, subscribe to a long random topic name, and type the same topic in Settings. Email works without any of this.

## 2. Windows PC

1. **OBS:** Tools, WebSocket Server Settings: enable it, keep port 4455, set a password. Recording format: Hybrid MP4 or MKV. Turn on "Start OBS at sign-in" in Windows if you like.
2. **Copy the `agent/` folder** to the PC, for example `C:\scheduled-video-recorder\agent`.
3. **Install:** in PowerShell, from that folder, run
   `powershell -ExecutionPolicy Bypass -File .\install.ps1`
   It creates a Python environment, opens `.env` for you to fill in (`SERVER_URL`, `AGENT_TOKEN`, `OBS_PASSWORD`, folders), runs a checklist, and sets up a task that starts the agent every time this user signs in.
   OBS needs a signed-in desktop, so the agent is a sign-in task rather than a hidden Windows service. Turn on automatic sign-in for this account so it comes back by itself after a power cut.
4. **Read the checklist** (`.\.venv\Scripts\python.exe -m svagent check`). Every line should say OK. Common fixes are in the table below.
5. **Preview the intro:** `python -m svagent intro-test` writes a sample card you can open and look at.
6. Remote support still works exactly as before (AnyDesk). The agent log is `agent\agent.log`; `run-agent.bat` runs it in a visible window for troubleshooting.

## 3. Light sensor
See [../firmware/README.md](../firmware/README.md). Use the same `ESP_TOKEN` and `https://<your-domain>/video-manager/api/esp`.
The dashboard shows the sensor's latest reading within a minute of it connecting.

## 4. Test before Sunday

1. On the dashboard tap **Run a test recording** (admin). Within about 20 seconds the status lamp turns red, the PC records for 100 seconds,
   trims 25 seconds, adds the intro, uploads, and you get a "Video ready" message. Tap **Download** on your phone and watch it.
   For a real server-stored video, admins can use **Publish to YouTube** from Today’s Latest Video or that service’s page. Test videos cannot be published.
2. Turn the pulpit light on, wait a few minutes, turn it off. The dashboard device row shows the readings; on a real service they appear on the service page.
3. Unplug the PC's network for an hour (or temporarily lower `AGENT_OFFLINE_MINUTES` to 5) to see the offline alert and the **Silence alerts** button.

## 5. Moving over from the old process

Run both for a few Sundays. The new agent starts and stops OBS through WebSocket; the old scheduled task still sends its hotkeys.
If your OBS hotkeys are separate "start" and "stop" keys, a second start or stop is harmless. If one is a toggle, remove it from the old task first.
The agent also adopts a recording that something else already started, and keeps you covered if the server is unreachable (it follows the last schedule it received).
When the new videos match the old ones, delete the old scheduled tasks and the AutoHotkey scripts.

## Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| Site shows "Server files not found" | The private folder is not at `~/video-manager-private/`, or `src/bootstrap.php` is missing inside it |
| Agent or sensor gets `401` | Token differs between the device and the server `.env` (check for stray spaces). Tokens must be at least 16 characters |
| `check`: OBS WebSocket fails | WebSocket not enabled in OBS, wrong port or password, or OBS not signed in to the desktop |
| `check`: ffmpeg fails | Install ffmpeg and add it to PATH, or set `FFMPEG` and `FFPROBE` to full paths in `agent\.env` |
| Dashboard says schedule error | The sheet is not shared as "anyone with the link", or `SHEET_ID` / `SHEET_GID` is wrong |
| "Sermon times not found" alert | The light never crossed 100 lux and back below 50 for at least 3 minutes. Set the times by hand on the service page |
| Intro text is missing or garbled | `INTRO_FONT` does not exist on the PC. Run `intro-test` |
| Video downloads stop at the same size on the phone | The host cut off a long request; tell us which size, and use **Watch** or retry on Wi-Fi |
| No emails arrive | Check spam; confirm `MAIL_FROM` is an address on your own domain |
