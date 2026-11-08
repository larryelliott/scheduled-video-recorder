"""The agent loop: check in, follow the schedule, record, trim, upload, clean up."""
from __future__ import annotations

import base64
import logging
import os
import shutil
import threading
import time
from pathlib import Path
from typing import Optional

from . import __version__, cleanup, media
from .api import Server
from .obs import default_videos_dir, make_obs, newest_video, parse_obs_name, video_near_any
from .state import State

log = logging.getLogger("svagent")
MAX_START_FAILURES = 4
START_RETRY_SECONDS = 30


class JobError(RuntimeError):
    pass


class Agent:
    def __init__(self, cfg, server: Optional[Server] = None, obs=None):
        self.cfg = cfg
        self.server = server or Server(cfg.server_url, cfg.agent_token, cfg.verify_tls)
        self.obs = obs or make_obs(cfg)
        self.state = State(cfg.state_file)
        self.stop_event = threading.Event()
        self.job_thread: Optional[threading.Thread] = None
        self.busy_sid: Optional[int] = None
        self.last_obs_ok: Optional[bool] = None
        self.last_message = ""
        self.start_failures: dict[int, int] = {}
        self.last_start_attempt = 0.0
        self.last_cleanup = 0.0
        self.last_dir_check = 0.0
        self.lock = threading.Lock()

    # ------------------------------------------------------------------ helpers
    def record_dirs(self) -> list[str]:
        """Folders where raw recordings may be: what OBS reports first, then RECORD_DIR, then the Videos folder.
        The finished-video folder is never treated as a raw folder."""
        out: list[str] = []
        final = cleanup.norm(str(self.cfg.output_dir))
        for d in (self.state.get("obs_record_dir"), self.cfg.record_dir, self.state.get("record_dir"), default_videos_dir()):
            if d and os.path.isdir(d) and cleanup.norm(d) != final and cleanup.norm(d) not in [cleanup.norm(x) for x in out]:
                out.append(d)
        return out

    def record_dir(self) -> str:
        dirs = self.record_dirs()
        return dirs[0] if dirs else ""

    def known_raw(self) -> set[str]:
        return {cleanup.norm(s["path"]) for segs in self.state["segments"].values() for s in segs if s.get("path")}

    def dirs(self) -> list[tuple[str, str]]:
        return [(d, "raw") for d in self.record_dirs()] + [(str(self.cfg.output_dir), "final")]

    def send_event(self, sid: int, event: str, **extra) -> None:
        payload = {"service_id": sid, "event": event, "ts_utc": int(self.server.now()), **extra}
        if not self.server.recording(payload):
            self.state["outbox"].append(["recording", payload])
            self.state.save()

    def send_job(self, sid: int, state: str, message: str = "", progress: int = 0, **extra) -> None:
        payload = {"service_id": sid, "state": state, "message": message, "progress": int(progress), **extra}
        if not self.server.job(payload):
            if state != "running":  # progress updates are not worth queueing
                self.state["outbox"].append(["job", payload])
                self.state.save()

    def flush_outbox(self) -> None:
        pending, self.state["outbox"] = self.state["outbox"], []
        for kind, payload in pending:
            ok = self.server.recording(payload) if kind == "recording" else self.server.job(payload)
            if not ok:
                self.state["outbox"].append([kind, payload])
        self.state.save()

    def heartbeat_payload(self) -> dict:
        recording = self.state["recording_sid"] is not None
        if self.busy_sid is not None:
            st = "processing"
        elif recording:
            st = "recording"
        elif self.start_failures and max(self.start_failures.values()) >= MAX_START_FAILURES:
            st = "error"
        else:
            st = "idle"
        target = self.record_dir() or str(self.cfg.output_dir)
        try:
            free = round(shutil.disk_usage(target if Path(target).exists() else Path.home()).free / 1e9, 1)
        except OSError:
            free = None
        return {"version": __version__, "state": st, "recording": recording, "obs_ok": self.last_obs_ok,
                "disk_free_gb": free, "message": self.last_message, "busy_service_id": self.busy_sid,
                "files": cleanup.inventory(self.dirs(), self.known_raw())}

    # ------------------------------------------------------------------ recording
    def _obs_recording(self) -> Optional[bool]:
        try:
            self.obs.ensure_running()
            rec = self.obs.is_recording()
            self.last_obs_ok = True
            if self.last_message.startswith("OBS:"):
                self.last_message = ""
            if time.time() - self.last_dir_check > 300 or not self.state.get("obs_record_dir"):
                self.last_dir_check = time.time()
                found = self.obs.record_dir()
                if found:
                    self.state["obs_record_dir"] = found
            return rec
        except Exception as exc:
            self.last_obs_ok = False
            self.last_message = f"OBS: {exc}"
            log.warning("OBS check failed: %s", exc)
            return None

    def _segments(self, sid: int) -> list[dict]:
        return self.state["segments"].setdefault(str(sid), [])

    def _close_segment(self, sid: int, when: float) -> None:
        segs = self._segments(sid)
        if segs and segs[-1].get("stopped_utc") is None:
            segs[-1]["stopped_utc"] = when

    def control_recording(self, nxt: Optional[dict], now: float) -> None:
        sid = self.state["recording_sid"]
        obs_rec = self._obs_recording()

        if sid is not None:
            want = nxt is not None and nxt["id"] == sid and now < nxt["rec_stop_utc"]
            if obs_rec is False:
                log.error("OBS stopped recording by itself")
                self._close_segment(sid, now)
                self.state["recording_sid"] = None
                self.state.save()
                self.send_event(sid, "failed", message="OBS stopped recording unexpectedly. The agent will try to restart it.")
            elif obs_rec is True and not want:
                self.stop_recording(sid)
                return
            else:
                return

        if nxt is None or not (nxt["rec_start_utc"] <= now < nxt["rec_stop_utc"]):
            return
        nid = nxt["id"]
        if obs_rec is True:  # something else (e.g. the old scheduler) already started it: adopt that recording
            self.adopt_recording(nxt)
            return
        if self.start_failures.get(nid, 0) >= MAX_START_FAILURES or time.time() - self.last_start_attempt < START_RETRY_SECONDS:
            return
        self.start_recording(nxt)

    def start_recording(self, nxt: dict) -> None:
        sid = nxt["id"]
        self.last_start_attempt = time.time()
        try:
            self.obs.ensure_running()
            self.obs.connect()
            local_before = time.time()
            self.obs.start()
            started = self.server.now()
            path = self.obs.wait_for_file(local_before, self.record_dirs())
        except Exception as exc:
            n = self.start_failures[sid] = self.start_failures.get(sid, 0) + 1
            self.last_message = f"Could not start recording: {exc}"
            log.error("%s (attempt %d)", self.last_message, n)
            if n >= 2:
                self.send_event(sid, "failed", message=f"{self.last_message} (attempt {n} of {MAX_START_FAILURES})")
            return
        self._begin_segment(sid, path, started)

    def adopt_recording(self, nxt: dict) -> None:
        sid = nxt["id"]
        try:
            started = self.server.now() - self.obs.status()["duration_s"]
        except Exception:
            started = self.server.now()
        found = [(newest_video(d, time.time() - 6 * 3600), d) for d in self.record_dirs()]
        found = [p for p, _ in found if p]
        path = max(found, key=os.path.getmtime) if found else None
        named = parse_obs_name(Path(path).name) if path else None
        if named is not None:  # the file name carries the exact start second
            started = named + self.server.offset
        log.info("Adopting a recording that was already running: %s", path)
        self._begin_segment(sid, path, started)

    def _begin_segment(self, sid: int, path: Optional[str], started: float) -> None:
        self._segments(sid).append({"path": path, "started_utc": started, "stopped_utc": None})
        self.state["recording_sid"] = sid
        self.state.save()
        self.last_message = ""
        log.info("Recording started for service %s -> %s", sid, path)
        recording_image = None
        if path:
            preview = self.cfg.work_dir / f"{sid}-recording-preview.jpg"
            deadline = time.monotonic() + 10
            last_preview_error = ""
            try:
                while time.monotonic() < deadline:
                    wait = 3.0 - (self.server.now() - started)
                    if wait > 0:
                        time.sleep(min(wait, 0.5))
                        continue
                    if Path(path).is_file() and Path(path).stat().st_size > 0:
                        try:
                            image = media.capture_frame(self.cfg.ffmpeg, path, preview, seconds=2.0)
                            recording_image = base64.b64encode(image).decode("ascii")
                            break
                        except Exception as exc:
                            last_preview_error = str(exc)
                    time.sleep(0.5)
                if not recording_image:
                    log.warning("Could not capture recording-start preview: %s",
                                last_preview_error or "recording file was not readable in time")
            finally:
                preview.unlink(missing_ok=True)
        extra = {"video_started_utc": int(started), "video_file": Path(path).name if path else ""}
        if recording_image:
            extra["recording_image"] = recording_image
        self.send_event(sid, "started", **extra)

    def stop_recording(self, sid: int) -> None:
        try:
            path = self.obs.stop()
        except Exception as exc:
            self.last_message = f"Could not stop recording: {exc}"
            log.error(self.last_message)
            return
        now = self.server.now()
        segs = self._segments(sid)
        path = path or self.obs.last_output_path()
        if path and segs and not segs[-1].get("path"):
            segs[-1]["path"] = path
        self._close_segment(sid, now)
        self.state["recording_sid"] = None
        self.state.save()
        log.info("Recording stopped for service %s", sid)
        self.send_event(sid, "stopped", video_file=Path(path).name if path else "")

    # ------------------------------------------------------------------ processing
    def find_segment(self, sid: int, cut_start: float, cut_end: float) -> Optional[dict]:
        """The recording that overlaps the sermon the most. A recording that began a little after the light came on
        (or ended a little before it went off) is still used; the video is simply clipped to what was recorded."""
        need = min(60.0, (cut_end - cut_start) * 0.5)
        best, best_overlap = None, 0.0
        for seg in self._segments(sid):
            ends = seg.get("stopped_utc")
            end = max(cut_end, self.server.now()) if ends is None else ends
            overlap = min(cut_end, end) - max(cut_start, seg["started_utc"])
            if overlap > need and overlap > best_overlap:
                best, best_overlap = seg, overlap
        return best

    def resolve_path(self, seg: dict) -> Optional[str]:
        """Finds the raw file for a segment. The path is not always known while OBS is still writing it."""
        p = seg.get("path")
        if p and Path(p).is_file():
            return p
        dirs = self.record_dirs()
        if not dirs:
            return None
        found = video_near_any(dirs, seg["started_utc"] - self.server.offset)
        if found:
            seg["path"] = found
            self.state.save()
            log.info("Found the recording file: %s", found)
        return found

    def refresh_paths(self) -> None:
        for segs in self.state["segments"].values():
            for seg in segs:
                if (not seg.get("path") or not Path(seg["path"]).is_file()) and time.time() - seg["started_utc"] < 3 * 86400:
                    self.resolve_path(seg)

    def explain_no_segment(self, sid: int, cut_start: float, cut_end: float) -> str:
        def t(x: float) -> str:
            return time.strftime("%H:%M:%S", time.localtime(x))
        have = "; ".join(f"{t(s['started_utc'])} to {t(s['stopped_utc']) if s.get('stopped_utc') else 'still recording'}"
                         for s in self._segments(sid)) or "none"
        return (f"No recording on the Windows PC covers the sermon times ({t(cut_start)} to {t(cut_end)}). "
                f"Recordings the PC has for this service: {have}. If the recording was started late or stopped early, "
                "set the start and end by hand on the service page.")

    def maybe_start_job(self, job: Optional[dict]) -> None:
        if not job or (self.job_thread and self.job_thread.is_alive()):
            return
        if job["service_id"] in self.state["jobs_done"]:
            return
        self.job_thread = threading.Thread(target=self.process_job, args=(job,), daemon=True, name="job")
        self.busy_sid = job["service_id"]
        self.job_thread.start()

    def process_job(self, job: dict) -> None:
        sid = job["service_id"]
        work = self.cfg.work_dir / str(sid)
        last_sent = [0.0]

        def report(pct: float, msg: str) -> None:
            if time.time() - last_sent[0] > 5:
                last_sent[0] = time.time()
                self.send_job(sid, "running", msg, pct)

        try:
            cut_start, cut_end = float(job["cut_start_utc"]), float(job["cut_end_utc"])
            seg = self.find_segment(sid, cut_start, cut_end)
            if seg is None:
                raise JobError(self.explain_no_segment(sid, cut_start, cut_end))
            path = self.resolve_path(seg)
            if not path or not Path(path).is_file():
                raise JobError("The raw recording file could not be found on the PC (looked in: "
                               + (", ".join(self.record_dirs()) or "no folder") + "). In OBS, check Settings > Output > Recording Path, "
                               "and remove RECORD_DIR from the agent .env unless it matches that folder.")
            self.send_job(sid, "running", "waiting for the recording to catch up", 0)
            while seg.get("stopped_utc") is None and self.server.now() < cut_end + 15 and not self.stop_event.is_set():
                time.sleep(3)
            start = max(cut_start, seg["started_utc"])
            end = cut_end if seg.get("stopped_utc") is None else min(cut_end, seg["stopped_utc"])
            start_offset, duration = start - seg["started_utc"], end - start
            if start - cut_start > 1:
                log.warning("Recording began %.0fs after the sermon started; clipping", start - cut_start)
            out = self.cfg.output_dir / job["output_name"]
            venc = {"encoder": self.cfg.video_encoder, "preset": self.cfg.x264_preset, "crf": self.cfg.x264_crf,
                    "extra": self.cfg.video_encoder_args}
            media.build_video(self.cfg.ffmpeg, self.cfg.ffprobe, path, start_offset, duration, job["intro"], out, work,
                              lambda f, m: report(5 + f * 70, m), venc, lambda: self.state["recording_sid"] is not None)
            done_extra = {}
            try:
                preview = work / "intro-preview.jpg"
                done_extra["intro_image"] = base64.b64encode(
                    media.capture_frame(self.cfg.ffmpeg, str(work / "intro.mp4"), preview, seconds=0.5)
                ).decode("ascii")
            except Exception as exc:
                log.warning("Could not capture YouTube intro preview: %s", exc)
            if self.cfg.upload_after_trim:
                self.send_job(sid, "running", "uploading to the web server", 80)
                self.server.upload(sid, job["output_name"], str(out),
                                   lambda sent, total: report(80 + 19 * sent / max(1, total), "uploading"))
            self.send_job(sid, "done", "", 100, **done_extra)
            self.state["jobs_done"].append(sid)
            self.state.save()
            shutil.rmtree(work, ignore_errors=True)
            log.info("Service %s finished: %s", sid, out)
        except Exception as exc:
            log.exception("Job for service %s failed", sid)
            self.send_job(sid, "failed", str(exc)[:400])
            self.state["jobs_done"].append(sid)  # do not loop; the web page has a retry button that clears this on the server
            self.state.save()
        finally:
            self.busy_sid = None

    # ------------------------------------------------------------------ housekeeping
    def maybe_cleanup(self) -> None:
        if time.time() - self.last_cleanup < 1800:
            return
        self.last_cleanup = time.time()
        protect = {str(Path(s["path"]).resolve()) for segs in self.state["segments"].values() for s in segs
                   if s.get("path") and s.get("stopped_utc") is None}
        removed = cleanup.delete_expired(self.dirs(), int(self.state["retention_days"]), set(self.state["pinned"]), protect,
                                         known_raw=self.known_raw())
        if removed:
            log.info("Cleanup removed %d old file(s)", len(removed))
        # forget bookkeeping for services older than the retention period
        limit = self.server.now() - (int(self.state["retention_days"]) + 2) * 86400
        for sid in list(self.state["segments"]):
            segs = self.state["segments"][sid]
            if segs and all((s.get("stopped_utc") or 0) and s["stopped_utc"] < limit for s in segs):
                del self.state["segments"][sid]
        self.state.save()

    # ------------------------------------------------------------------ main loop
    def tick(self) -> None:
        hb = self.server.heartbeat(self.heartbeat_payload())
        if hb is not None:
            nxt = hb.get("next_service")
            self.state["last_next"] = nxt
            self.state["retention_days"] = hb.get("retention_days", 14)
            self.state["pinned"] = hb.get("pinned", [])
            self.flush_outbox()
            job = hb.get("job")
            # The server offers a job again only after someone presses Try again, so forget our "done" mark for it.
            if job and job["service_id"] in self.state["jobs_done"] and not (self.job_thread and self.job_thread.is_alive()):
                self.state["jobs_done"].remove(job["service_id"])
        else:
            nxt = self.state.get("last_next")  # keep following the last known schedule while the server is unreachable
            job = None
            self.last_message = f"Server unreachable: {self.server.last_error}"
        self.control_recording(nxt, self.server.now())
        self.refresh_paths()
        self.maybe_start_job(job)
        self.maybe_cleanup()

    def run_forever(self) -> None:
        log.info("Agent %s started. Server: %s", __version__, self.cfg.server_url)
        self.cfg.work_dir.mkdir(parents=True, exist_ok=True)
        self.cfg.output_dir.mkdir(parents=True, exist_ok=True)
        while not self.stop_event.is_set():
            try:
                self.tick()
            except Exception:
                log.exception("Unexpected error in the main loop")
            self.stop_event.wait(self.cfg.heartbeat_seconds)
