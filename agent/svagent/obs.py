"""OBS control over OBS WebSocket v5 (built into OBS 28+), plus a fake OBS for testing."""
from __future__ import annotations

import logging
import os
import subprocess
import time
from pathlib import Path
from typing import Optional

log = logging.getLogger("svagent.obs")
VIDEO_EXT = (".mp4", ".mkv", ".mov", ".flv", ".ts")


class ObsError(RuntimeError):
    pass


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

    # -- process ---------------------------------------------------------------------------
    def _running(self) -> bool:
        try:
            if os.name == "nt":
                out = subprocess.run(["tasklist", "/FI", "IMAGENAME eq obs64.exe"], capture_output=True, text=True, timeout=15).stdout
                return "obs64.exe" in out.lower()
            return subprocess.run(["pgrep", "-x", "obs"], capture_output=True, timeout=10).returncode == 0
        except (OSError, subprocess.SubprocessError):
            return False

    def ensure_running(self) -> None:
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
                return
            except Exception as exc:  # the library raises several different types
                last = exc
                self.client = None
                time.sleep(3)
        raise ObsError(f"Could not connect to OBS WebSocket: {last}")

    def disconnect(self) -> None:
        self.client = None

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
        except Exception:
            return None

    def start(self) -> None:
        self._call("start_record")

    def stop(self) -> Optional[str]:
        r = self._call("stop_record")
        return getattr(r, "output_path", None)

    def wait_for_file(self, since_local: float, timeout: float = 20) -> Optional[str]:
        """After start(), find the file OBS is writing."""
        directory = self.cfg.record_dir or self.record_dir()
        deadline = time.time() + timeout
        while directory and time.time() < deadline:
            found = newest_video(directory, since_local - 2)
            if found:
                return found
            time.sleep(1)
        return None


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

    def wait_for_file(self, since_local: float, timeout: float = 20) -> Optional[str]:
        time.sleep(1)
        return self.path


def make_obs(cfg):
    return FakeObs(cfg) if cfg.fake_obs else RealObs(cfg)
