# API contract

All requests are HTTPS to `BASE_URL`. JSON in, JSON out. Times are UTC unix seconds.
Machines authenticate with the header `X-Api-Token: <token>` (`Authorization: Bearer <token>` also works).
ESP8266: `ESP_TOKEN`. Windows agent: `AGENT_TOKEN`. A wrong token returns `401`.

## ESP8266

`POST /api/esp`
```json
{"lux": 141.67, "duration": 831556, "timestamp": 1779031520, "rssi": -61, "fw": "2.0", "uptime": 3600}
```
`timestamp` is the sensor's NTP time; the server uses it only if it is within 10 minutes of its own clock (0 or missing = use server time).
Response: `{"ok": true, "server_time": 1779031521}`

## Windows agent

### `POST /api/agent/heartbeat` (every ~20 s)
Request:
```json
{"version": "1.0.0", "state": "idle|recording|processing|error", "recording": false, "obs_ok": true,
 "disk_free_gb": 212.4, "message": "", "busy_service_id": null,
 "files": [{"name": "2026-11-01 10-20-01.mp4", "kind": "raw", "size": 123456, "mtime_utc": 1793000000}]}
```
Response:
```json
{"ok": true, "server_time": 1793000000.25, "retention_days": 14, "pinned": ["keep-me.mp4"],
 "next_service": {"id": 12, "start_utc": 1793028600, "rec_start_utc": 1793028000, "rec_stop_utc": 1793034000,
                  "subtitle": "All Saints Day", "is_test": 0},
 "job": {"service_id": 12, "cut_start_utc": 1793030100, "cut_end_utc": 1793030900, "output_name": "All-Saints-Day.mp4",
         "intro": {"duration": 5, "title": "...", "subtitle": "...", "date": "...", "description": "...", "description2": "...",
                   "text1": "...", "text2": "...", "text3": "...", "text4": "...",
                   "background_color": "0x000000", "text_color": "0xFFFFFF", "accent_color": "0x7F0000", "font": "C:/Windows/Fonts/arial.ttf"}}}
```
- `server_time` (float) lets the agent correct its clock so cut points match the light sensor.
- `next_service` is the earliest service whose recording window has not ended, or `null`. The agent records while `rec_start_utc <= now < rec_stop_utc`.
  "Start now" and "Stop now" on the website simply move these two times, so the agent needs no command channel.
- `job` appears when sermon times are known and the video has not been processed. It stays absent while a job is `running`.
- `files` replaces the server's list of files on the PC each time.

### `POST /api/agent/recording`
```json
{"service_id": 12, "event": "started|stopped|failed", "ts_utc": 1793028001, "video_started_utc": 1793028001,
 "video_file": "2026-11-01 10-20-01.mp4", "recording_image": "optional base64 JPEG", "message": "only for failed"}
```
`started` is idempotent. It can include a small JPEG frame captured from the active recording. `stopped` marks the service as recorded. `failed` sends an error notification.

### `POST /api/agent/job`
```json
{"service_id": 12, "state": "running|failed|done", "message": "trimming", "progress": 40}
```
`running` updates progress and sends the processing-started notification once per attempt. A `done` request may include an `intro_image` field containing a small base64 JPEG. `done` requires the upload to have arrived, marks the video ready, and automatically publishes it to YouTube when publishing is configured (test videos are never published). The response can take as long as the configured YouTube publishing timeout.
A job stuck in `running` for 10 minutes with the agent no longer reporting it is retried, up to 3 attempts.

### `PUT /api/agent/upload?service_id=12&name=All-Saints-Day.mp4`
Raw video bytes as the body (no multipart). The server checks the byte count against `Content-Length`.
Response: `{"ok": true, "size": 398123456, "file_id": 5}`

## Notification images

The agent sends optional JPEG previews with the authenticated recording/job events above. The server serves stored previews to email and ntfy through unguessable links at `/notification-image/{service_id}/{kind}/{token}`. Only `recording_started` and `youtube_published` images are accepted; links expire with file retention. Missing or invalid previews do not prevent recording, processing, or publishing.

## Browser (signed-in users)

Pages: `/`, `/services`, `/services/{id}`, `/files`, `/settings`. `GET /download/{file_id}` supports HTTP Range; add `?inline=1` to watch instead of save.
`GET /api/status-card` returns the live status HTML used by the dashboard. All form posts carry a CSRF token.

## Cron

`php bin/cron.php [--force]` (hourly, 8 AM to 9 PM): refreshes the schedule, sends offline / missed-recording / sermon-times-not-found
alerts (each once), deletes expired server files and prunes old readings.
