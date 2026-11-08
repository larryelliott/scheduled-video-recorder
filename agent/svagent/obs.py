"""OBS control over OBS WebSocket v5 (built into OBS 28+), plus a fake OBS for testing."""
from __future__ import annotations

import logging
import os
import subprocess
import time
from pathlib import Path
from typing import Optional

from .winproc import hidden_kwargs

log = logging.getLogger("svagent.obs")
VIDEO_EXT = (".mp4", ".mkv", ".mov", ".flv", ".ts")


class ObsError(RuntimeError):
    pass


def parse_obs_name(name: str) -> Optional[float]:
    """OBS names files like '2026-11-02 21-15-51.mp4' (local time). Returns the local epoch, or None."""
    try:
        return time.mktime(time.strptime(Path(name).stem, "%Y-%m-%d %H-%M-%S"))
    except ValueError:
        return None


def video_near_any(directories: list[str], started_local: float, window: float = 180) -> Optional[str]:
    """video_near() across several folders; the closest match wins."""
    best, best_diff = None, window + 1
    for d in directories:
        try:
            for e in os.scandir(d):
                if not (e.is_file() and e.name.lower().endswith(VIDEO_EXT)):
                    continue
                t = parse_obs_name(e.name)
                if t is None:
                    t = e.stat().st_ctime
                if abs(t - started_local) < best_diff:
                    best, best_diff = e.path, abs(t - started_local)
        except OSError:
            continue
    return best if best_diff <= window else None


def default_videos_dir() -> str:
    """OBS saves to the user's Videos folder unless told otherwise."""
    p = Path.home() / "Videos"
    return str(p) if p.is_dir() else ""


def video_near(directory: str, started_local: float, window: float = 180) -> Optional[str]:
    """The recording file whose start time (from its OBS-style name, else creation time) is closest to started_local."""
    best, best_diff = None, window + 1
    try:
        for e in os.scandir(directory):
            if not (e.is_file() and e.name.lower().endswith(VIDEO_EXT)):
                continue
            t = parse_obs_name(e.name)
            if t is None:
                t = e.stat().st_ctime
            diff = abs(t - started_local)
            if diff < best_diff:
                best, best_diff = e.path, diff
    except OSError:
        return None
    return best if best_diff <= window else None


def newest_video(directory: str, since: float) -> Optional[str]:
    """Newest recording file in `directory` modified at or after `since` (local epoch)."""
    best, best_m = None, 0.0
    try:
        for entry in os.scandir(directory):
            if entry.is_file() and entry.name.lower().endswith(VIDEO_EXT):
                m = entry.stat().st_mtime
                if m >= since and m > best_m:
                    best, best_m = entry.path, m
    except OSError:
        return None
    return best


class RealObs:
    def __init__(self, cfg):
        self.cfg = cfg
        self.client = None
        self.events = None
        self.current_path: Optional[str] = None  # exact file OBS reported when the recording started

    # -- process ---------------------------------------------------------------------------
    def _running(self) -> bool:
        try:
            if os.name == "nt":
                out = subprocess.run(["tasklist", "/FI", "IMAGENAME eq obs64.exe"], capture_output=True, text=True,
                                     timeout=15, **hidden_kwargs()).stdout
                return "obs64.exe" in out.lower()
            return subprocess.run(["pgrep", "-x", "obs"], capture_output=True, timeout=10).returncode == 0
        except (OSError, subprocess.SubprocessError):
            return False

    def ensure_running(self) -> None:
        if self.client is not None:  # already connected, so OBS is obviously running (and no tasklist call needed)
            return
        if self._running() or not self.cfg.obs_exe or not os.path.isfile(self.cfg.obs_exe):
            return
        log.info("OBS is not running; starting it")
        # OBS must be launched from its own folder or it cannot find its data files.
        subprocess.Popen([self.cfg.obs_exe, *self.cfg.obs_args], cwd=os.path.dirname(self.cfg.obs_exe))

    # -- connection --------------------------------------------------------------------------
    def connect(self, timeout: float = 90) -> None:
        if self.client is not None:
            return
        import obsws_python as obsws  # imported late so the rest of the agent works without it

        deadline = time.time() + timeout
        last: Exception = ObsError("not tried")
        while time.time() < deadline:
            try:
                self.client = obsws.ReqClient(host=self.cfg.obs_host, port=self.cfg.obs_port,
                                              password=self.cfg.obs_password, timeout=5)
                self.client.get_version()
                self._start_events(obsws)
                return
            except Exception as exc:  # the library raises several different types
                last = exc
                self.client = None
                time.sleep(3)
        raise ObsError(f"Could not connect to OBS WebSocket: {last}")

    def disconnect(self) -> None:
        self.client = None
        try:
            if self.events is not None:
                self.events.disconnect()
        except Exception:
            pass
        self.events = None

    def _start_events(self, obsws) -> None:
        """OBS announces the exact file path when a recording starts; remember it so we never have to guess."""
        if self.events is not None:
            return
        try:
            ev = obsws.EventClient(host=self.cfg.obs_host, port=self.cfg.obs_port, password=self.cfg.obs_password, timeout=5)
            outer = self

            def on_record_state_changed(data):  # the name selects the OBS event (RecordStateChanged)
                path = getattr(data, "output_path", None)
                state = str(getattr(data, "output_state", "")).upper()
                if path and "STARTED" in state:
                    outer.current_path = path
                    log.info("OBS is recording to %s", path)

            ev.callback.register(on_record_state_changed)
            self.events = ev
        except Exception as exc:
            log.warning("Could not subscribe to OBS recording events (falling back to searching folders): %s", exc)

    def last_output_path(self) -> Optional[str]:
        return self.current_path

    def _call(self, name: str, *args):
        self.connect()
        try:
            return getattr(self.client, name)(*args)
        except Exception:
            self.client = None  # force a reconnect next time
            raise

    # -- recording -------------------------------------------------------------------------------
    def status(self) -> dict:
        r = self._call("get_record_status")
        dur = getattr(r, "output_duration", 0) or 0
        return {"active": bool(getattr(r, "output_active", False)), "duration_s": float(dur) / 1000.0,
                "bytes": int(getattr(r, "output_bytes", 0) or 0)}

    def is_recording(self) -> bool:
        return self.status()["active"]

    def record_dir(self) -> Optional[str]:
        try:
            return getattr(self._call("get_record_directory"), "record_directory", None)
        except Exception as exc:
            log.warning("Could not ask OBS for its recording folder: %s", exc)
            return None

    def start(self) -> None:
        self.current_path = None
        self._call("start_record")

    def stop(self) -> Optional[str]:
        r = self._call("stop_record")
        return getattr(r, "output_path", None)

    def wait_for_file(self, since_local: float, dirs: list[str], timeout: float = 20) -> Optional[str]:
        """After start(), find the file OBS is writing: the path OBS announced, else the newest file in the candidate folders."""
        deadline = time.time() + timeout
        while time.time() < deadline:
            if self.current_path and os.path.exists(self.current_path):
                return self.current_path
            for d in dirs:
                found = newest_video(d, since_local - 2)
                if found:
                    return found
            time.sleep(1)
        found = video_near_any(dirs, since_local, 120)
        if not found:
            log.warning("Could not find the recording file. Looked in: %s", ", ".join(dirs) or "no folders")
        return found


class FakeObs:
    """Stands in for OBS during development: 'records' a test pattern with ffmpeg."""

    def __init__(self, cfg):
        self.cfg = cfg
        self.proc: Optional[subprocess.Popen] = None
        self.path: Optional[str] = None
        self.t0 = 0.0

    def ensure_running(self) -> None: ...
    def connect(self, timeout: float = 0) -> None: ...
    def disconnect(self) -> None: ...

    def status(self) -> dict:
        active = self.proc is not None and self.proc.poll() is None
        return {"active": active, "duration_s": time.time() - self.t0 if active else 0.0, "bytes": 0}

    def is_recording(self) -> bool:
        return self.status()["active"]

    def record_dir(self) -> Optional[str]:
        return self.cfg.record_dir or None

    def start(self) -> None:
        Path(self.cfg.record_dir).mkdir(parents=True, exist_ok=True)
        self.path = str(Path(self.cfg.record_dir) / time.strftime("%Y-%m-%d %H-%M-%S.mp4"))
        cmd = [self.cfg.ffmpeg, "-y", "-hide_banner", "-loglevel", "error",
               "-f", "lavfi", "-i", "testsrc2=size=1280x720:rate=30",
               "-f", "lavfi", "-i", "sine=frequency=440:sample_rate=48000",
               "-c:v", "libx264", "-preset", "ultrafast", "-pix_fmt", "yuv420p", "-c:a", "aac",
               # fragmented MP4 stays readable while it grows, like OBS "Hybrid MP4"
               "-movflags", "+frag_keyframe+empty_moov+default_base_moof", self.path]
        self.proc = subprocess.Popen(cmd, stdin=subprocess.PIPE)
        self.t0 = time.time()

    def stop(self) -> Optional[str]:
        if self.proc and self.proc.poll() is None:
            try:
                self.proc.stdin.write(b"q")
                self.proc.stdin.flush()
                self.proc.stdin.close()
            except OSError:
                pass
            try:
                self.proc.wait(timeout=20)
            except subprocess.TimeoutExpired:
                self.proc.kill()
        return self.path

    def wait_for_file(self, since_local: float, dirs: list[str] = (), timeout: float = 20) -> Optional[str]:
        time.sleep(1)
        return self.path

    def last_output_path(self) -> Optional[str]:
        return self.path


def make_obs(cfg):
    return FakeObs(cfg) if cfg.fake_obs else RealObs(cfg)
