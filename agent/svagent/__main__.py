"""Usage:
    python -m svagent            run the agent
    python -m svagent check      test ffmpeg, the server and OBS, then exit
    python -m svagent intro-test make a sample intro card to preview the layout
Options: --env FILE   use a different .env file;  --fake-obs  use a pretend OBS (development)
"""
from __future__ import annotations

import argparse
import logging
import logging.handlers
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path

from . import __version__, media
from .config import Config


def setup_logging(cfg: Config, console: bool) -> None:
    fmt = logging.Formatter("%(asctime)s %(levelname)s %(name)s: %(message)s")
    root = logging.getLogger()
    root.setLevel(logging.INFO)
    fh = logging.handlers.RotatingFileHandler(cfg.log_file, maxBytes=2_000_000, backupCount=3, encoding="utf-8")
    fh.setFormatter(fmt)
    root.addHandler(fh)
    if console and sys.stderr is not None:
        sh = logging.StreamHandler()
        sh.setFormatter(fmt)
        root.addHandler(sh)


def ok(label: str, good: bool, detail: str = "") -> bool:
    print(("OK    " if good else "FAIL  ") + label + (f"  ({detail})" if detail else ""))
    return good


def check(cfg: Config) -> int:
    from .api import Server
    from .obs import make_obs

    results = []
    for tool in (cfg.ffmpeg, cfg.ffprobe):
        try:
            v = subprocess.run([tool, "-version"], capture_output=True, text=True, timeout=20).stdout.splitlines()[0]
            results.append(ok(f"{tool} runs", True, v[:60]))
        except (OSError, IndexError, subprocess.SubprocessError) as exc:
            results.append(ok(f"{tool} runs", False, f"{exc}. Install ffmpeg and add it to PATH, or set FFMPEG/FFPROBE in .env"))
    results.append(ok("server URL and token set", bool(cfg.server_url and cfg.agent_token), cfg.server_url))
    server = Server(cfg.server_url, cfg.agent_token, cfg.verify_tls)
    hb = server.heartbeat({"version": __version__, "state": "check", "message": "check command"})
    results.append(ok("web server answers", hb is not None, server.last_error or f"clock offset {server.offset:+.2f}s"))
    if hb:
        nxt = hb.get("next_service")
        print("      next service:", f"{nxt['subtitle']!r} recording from {time.ctime(nxt['rec_start_utc'])}" if nxt else "none scheduled")
    obs = make_obs(cfg)
    try:
        obs.ensure_running()
        obs.connect(timeout=30)
        rd = obs.record_dir()
        results.append(ok("OBS WebSocket connects", True, f"recording={obs.is_recording()}, folder={rd}"))
        results.append(ok("recording folder exists", bool((cfg.record_dir or rd) and Path(cfg.record_dir or rd).is_dir()), cfg.record_dir or rd or ""))
    except Exception as exc:
        results.append(ok("OBS WebSocket connects", False, f"{exc}. Check OBS > Tools > WebSocket Server Settings, port and password"))
    try:
        cfg.output_dir.mkdir(parents=True, exist_ok=True)
        (cfg.output_dir / ".write-test").write_text("x")
        (cfg.output_dir / ".write-test").unlink()
        results.append(ok("output folder is writable", True, str(cfg.output_dir)))
    except OSError as exc:
        results.append(ok("output folder is writable", False, str(exc)))
    if os.name == "nt":
        results.append(ok("default font found", Path("C:/Windows/Fonts/arial.ttf").is_file()))
    return 0 if all(results) else 1


def intro_test(cfg: Config) -> int:
    work = cfg.work_dir / "intro-test"
    shutil.rmtree(work, ignore_errors=True)
    work.mkdir(parents=True, exist_ok=True)
    font = "C:/Windows/Fonts/arial.ttf" if os.name == "nt" else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
    intro = {"duration": 5, "title": "Sample Church Sermons", "subtitle": "Third Sunday of Advent", "date": "December 14, 2025",
             "description": "Reading: Isaiah 35", "description2": "Sermon: Strengthen the weak hands",
             "text1": "Line one", "text2": "Line two", "text3": "Line three", "text4": "Line four",
             "background_color": "0x000000", "text_color": "0xFFFFFF", "accent_color": "0x7F0000", "font": font}
    media.render_intro(cfg.ffmpeg, intro, {"width": 1920, "height": 1080, "fps": "30/1", "audio_rate": 48000}, "intro.mp4", work)
    print("Sample intro written to", work / "intro.mp4")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(prog="svagent", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("command", nargs="?", default="run", choices=["run", "check", "intro-test"])
    ap.add_argument("--env", help="path to an .env file")
    ap.add_argument("--fake-obs", action="store_true", help="pretend to be OBS (development only)")
    args = ap.parse_args()
    cfg = Config.load(args.env, **({"fake_obs": True} if args.fake_obs else {}))
    cfg.work_dir.mkdir(parents=True, exist_ok=True)
    setup_logging(cfg, console=args.command != "run")
    if args.command == "check":
        return check(cfg)
    if args.command == "intro-test":
        return intro_test(cfg)
    if not cfg.server_url or not cfg.agent_token:
        print("SERVER_URL and AGENT_TOKEN must be set in .env", file=sys.stderr)
        return 2
    from .runner import Agent
    agent = Agent(cfg)
    try:
        agent.run_forever()
    except KeyboardInterrupt:
        agent.stop_event.set()
    return 0


if __name__ == "__main__":
    sys.exit(main())
