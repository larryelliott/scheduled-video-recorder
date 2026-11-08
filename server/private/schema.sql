-- Scheduled Video Recorder: server schema (SQLite).
-- All times are UTC unix seconds. Safe to run repeatedly.

CREATE TABLE IF NOT EXISTS users (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  role        TEXT NOT NULL DEFAULT 'user',      -- 'admin' or 'user'
  email       TEXT NOT NULL DEFAULT '',
  ntfy_topic  TEXT NOT NULL DEFAULT '',
  prefs       TEXT NOT NULL DEFAULT '{}',        -- {"event": {"email": bool, "ntfy": bool}}
  active      INTEGER NOT NULL DEFAULT 1,
  created_utc INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS links (               -- magic links (only a hash is stored)
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  token_hash  TEXT NOT NULL UNIQUE,
  created_utc INTEGER NOT NULL,
  revoked_utc INTEGER
);

CREATE TABLE IF NOT EXISTS sessions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL,
  token_hash    TEXT NOT NULL UNIQUE,
  csrf          TEXT NOT NULL,
  link_id       INTEGER,
  created_utc   INTEGER NOT NULL,
  last_seen_utc INTEGER NOT NULL,
  expires_utc   INTEGER NOT NULL,
  ua            TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS services (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  start_utc             INTEGER NOT NULL UNIQUE,
  subtitle              TEXT NOT NULL DEFAULT '',
  text4                 TEXT NOT NULL DEFAULT '',
  description           TEXT NOT NULL DEFAULT '',
  description2          TEXT NOT NULL DEFAULT '',
  date_text             TEXT NOT NULL DEFAULT '',   -- optional override for the intro date line
  intro_edited          INTEGER NOT NULL DEFAULT 0, -- 1 = schedule refresh must not overwrite
  is_sheet              INTEGER NOT NULL DEFAULT 1,
  is_test               INTEGER NOT NULL DEFAULT 0,
  skip                  INTEGER NOT NULL DEFAULT 0,
  rec_start_utc         INTEGER,                    -- override of the default recording window
  rec_stop_utc          INTEGER,
  status                TEXT NOT NULL DEFAULT 'scheduled', -- scheduled|recording|recorded|ready|failed
  video_started_utc     INTEGER,
  video_file            TEXT,
  recording_stopped_utc INTEGER,
  cut_start_utc         INTEGER,
  cut_end_utc           INTEGER,
  cut_source            TEXT NOT NULL DEFAULT 'auto', -- auto|manual|test
  cut_note              TEXT NOT NULL DEFAULT '',
  job_state             TEXT NOT NULL DEFAULT '',   -- ''|pending|running|done|failed
  job_msg               TEXT NOT NULL DEFAULT '',
  job_progress          INTEGER NOT NULL DEFAULT 0,
  job_attempts          INTEGER NOT NULL DEFAULT 0,
  job_updated_utc       INTEGER,
  output_name           TEXT,
  ready_utc             INTEGER,
  missed_alerted        INTEGER NOT NULL DEFAULT 0,
  attention_sent        INTEGER NOT NULL DEFAULT 0,
  auto_light            INTEGER NOT NULL DEFAULT 0, -- 1 = started automatically because the pulpit light came on
  updated_utc           INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_services_status ON services(status);

CREATE TABLE IF NOT EXISTS light_events (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  ts_utc       INTEGER NOT NULL,        -- effective time of the reading
  lux          REAL NOT NULL,
  duration     INTEGER,
  received_utc INTEGER NOT NULL,
  rssi         INTEGER,
  fw           TEXT
);
CREATE INDEX IF NOT EXISTS idx_light_ts ON light_events(ts_utc);

CREATE TABLE IF NOT EXISTS devices (       -- 'agent' (winpc) and 'esp' (light sensor)
  name          TEXT PRIMARY KEY,
  last_seen_utc INTEGER,
  info          TEXT NOT NULL DEFAULT '{}',
  alert_state   TEXT NOT NULL DEFAULT 'ok',   -- ok|alerted|silenced
  alert_utc     INTEGER
);

CREATE TABLE IF NOT EXISTS files (         -- videos stored on this server
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  service_id  INTEGER,
  name        TEXT NOT NULL,
  path        TEXT NOT NULL,
  size        INTEGER NOT NULL DEFAULT 0,
  created_utc INTEGER NOT NULL,
  public_token TEXT UNIQUE
);

CREATE TABLE IF NOT EXISTS youtube_publications (
  file_id          INTEGER NOT NULL,
  service_id       INTEGER NOT NULL,
  file_name        TEXT NOT NULL,
  state            TEXT NOT NULL, -- publishing|published|failed|unknown
  attempted_utc    INTEGER NOT NULL,
  published_utc    INTEGER,
  youtube_video_id TEXT,
  error            TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (service_id, file_name)
);

CREATE TABLE IF NOT EXISTS notification_images (
  service_id  INTEGER NOT NULL,
  kind        TEXT NOT NULL,
  image_data  TEXT NOT NULL,
  public_token TEXT NOT NULL UNIQUE,
  created_utc INTEGER NOT NULL,
  PRIMARY KEY (service_id, kind)
);

CREATE TABLE IF NOT EXISTS agent_files (   -- latest inventory reported by winpc
  name      TEXT NOT NULL,
  kind      TEXT NOT NULL,                -- raw|final|file
  size      INTEGER NOT NULL DEFAULT 0,
  mtime_utc INTEGER NOT NULL,
  PRIMARY KEY (name, kind)
);

CREATE TABLE IF NOT EXISTS pins (          -- "keep this one": exempt from the 2-week cleanup, any machine
  name       TEXT PRIMARY KEY,
  pinned_utc INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS notif_log (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  ts_utc  INTEGER NOT NULL,
  event   TEXT NOT NULL,
  title   TEXT NOT NULL,
  sent_to TEXT NOT NULL DEFAULT ''
);
