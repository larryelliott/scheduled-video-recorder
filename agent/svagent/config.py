"""Settings come from agent/.env (see .env.example). Real environment variables also work."""
from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def load_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for line in path.read_text(encoding="utf-8-sig").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        val = val.strip()
        if len(val) >= 2 and val[0] == val[-1] and val[0] in "\"'":
            val = val[1:-1]
        values[key.strip()] = val
    return values


@dataclass
class Config:
    server_url: str
    agent_token: str
    verify_tls: bool
    obs_host: str
    obs_port: int
    obs_password: str
    obs_exe: str
    obs_args: list[str]
    fake_obs: bool
    record_dir: str
    output_dir: Path
    work_dir: Path
    state_file: Path
    log_file: Path
    ffmpeg: str
    ffprobe: str
    heartbeat_seconds: float
    upload_after_trim: bool

    @staticmethod
    def load(env_file: str | None = None, **overrides) -> "Config":
        base = Path(__file__).resolve().parent.parent
        file_values = load_env_file(Path(env_file) if env_file else base / ".env")

        def get(key: str, default: str = "") -> str:
            return os.environ.get(key) or file_values.get(key) or default

        home = Path.home()
        cfg = Config(
            server_url=get("SERVER_URL").rstrip("/"),
            agent_token=get("AGENT_TOKEN"),
            verify_tls=get("VERIFY_TLS", "1") not in ("0", "false", "no"),
            obs_host=get("OBS_HOST", "localhost"),
            obs_port=int(get("OBS_PORT", "4455")),
            obs_password=get("OBS_PASSWORD"),
            obs_exe=get("OBS_EXE", r"C:\Program Files\obs-studio\bin\64bit\obs64.exe"),
            obs_args=get("OBS_ARGS", "--disable-shutdown-check").split(),
            fake_obs=get("FAKE_OBS", "0") in ("1", "true", "yes"),
            record_dir=get("RECORD_DIR"),
            output_dir=Path(get("OUTPUT_DIR", str(home / "Documents" / "sermon-videos"))),
            work_dir=Path(get("WORK_DIR", str(base / "work"))),
            state_file=Path(get("STATE_FILE", str(base / "agent_state.json"))),
            log_file=Path(get("LOG_FILE", str(base / "agent.log"))),
            ffmpeg=get("FFMPEG", "ffmpeg"),
            ffprobe=get("FFPROBE", "ffprobe"),
            heartbeat_seconds=float(get("HEARTBEAT_SECONDS", "20")),
            upload_after_trim=get("UPLOAD_TO_SERVER", "1") not in ("0", "false", "no"),
        )
        for k, v in overrides.items():
            setattr(cfg, k, v)
        return cfg
