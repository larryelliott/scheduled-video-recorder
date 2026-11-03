"""End-to-end test of the agent against a fake server, with a fake OBS and real ffmpeg.
Run from the agent folder:   python -m unittest discover -s tests -v
"""
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
sys.path.insert(0, str(Path(__file__).resolve().parent))
os.environ["NO_PROXY"] = "127.0.0.1,localhost"

from fake_server import FakeServer  # noqa: E402
from svagent import cleanup, media  # noqa: E402
from svagent.config import Config  # noqa: E402
from svagent.runner import Agent  # noqa: E402

FONT = "C:/Windows/Fonts/arial.ttf" if os.name == "nt" else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"


def duration(path) -> float:
    out = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "default=nw=1:nk=1", str(path)],
                         capture_output=True, text=True).stdout
    return float(out)


def wait_for(cond, timeout, step=0.5):
    end = time.time() + timeout
    while time.time() < end:
        if cond():
            return True
        time.sleep(step)
    return cond()


class EndToEnd(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        (self.tmp / "up").mkdir()
        self.srv = FakeServer(self.tmp / "up").start()
        self.cfg = Config.load(str(self.tmp / "none.env"), server_url=self.srv.url, agent_token=self.srv.token,
                               fake_obs=True, record_dir=str(self.tmp / "rec"), output_dir=self.tmp / "out",
                               work_dir=self.tmp / "work", state_file=self.tmp / "state.json",
                               log_file=self.tmp / "agent.log", heartbeat_seconds=1.0)
        self.agent = Agent(self.cfg)
        self.thread = threading.Thread(target=self.agent.run_forever, daemon=True)

    def tearDown(self):
        self.agent.stop_event.set()
        if self.thread.is_alive():
            self.thread.join(timeout=30)
        self.srv.stop()
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_full_service(self):
        now = time.time()
        self.srv.next_service = {"id": 7, "start_utc": int(now) + 60, "rec_start_utc": int(now) - 1,
                                 "rec_stop_utc": int(now) + 3600, "subtitle": "Test", "is_test": 0}
        self.thread.start()
        self.assertTrue(wait_for(lambda: any(e["event"] == "started" for e in self.srv.recording_events), 20), "recording never started")
        started = next(e for e in self.srv.recording_events if e["event"] == "started")["video_started_utc"]
        self.assertTrue(wait_for(lambda: time.time() - started > 22, 40))

        # The light turned on and off: server now offers the job while recording continues.
        self.srv.job = {"service_id": 7, "cut_start_utc": int(started) + 4, "cut_end_utc": int(started) + 16,
                        "output_name": "2026-11-01-Test.mp4",
                        "intro": {"duration": 5, "title": "Test Church's Sermons: 100%", "subtitle": "All Saints Day", "date": "November 1, 2026",
                                  "description": "Line, with comma", "description2": "", "text1": "a", "text2": "", "text3": "", "text4": "bottom",
                                  "background_color": "0x000000", "text_color": "0xFFFFFF", "accent_color": "0x7F0000", "font": FONT}}
        self.assertTrue(wait_for(lambda: any(e["state"] in ("done", "failed") for e in self.srv.job_events), 120), "job never finished")
        states = [e["state"] for e in self.srv.job_events]
        self.assertIn("done", states, f"job failed: {[e.get('message') for e in self.srv.job_events if e['state'] == 'failed']}")
        self.assertEqual(len(self.srv.uploads), 1)
        d = duration(self.srv.uploads[0])
        self.assertAlmostEqual(d, 17.0, delta=1.5, msg=f"uploaded video is {d:.1f}s")
        self.assertTrue((self.cfg.output_dir / "2026-11-01-Test.mp4").is_file())
        self.assertIsNotNone(self.agent.state["recording_sid"], "recording must continue while processing")

        # Window closes: the agent stops recording and reports it.
        self.srv.next_service = None
        self.assertTrue(wait_for(lambda: any(e["event"] == "stopped" for e in self.srv.recording_events), 20), "never stopped")
        self.assertIsNone(self.agent.state["recording_sid"])
        names = {f["name"]: f["kind"] for f in self.srv.last_files}
        self.assertIn("raw", names.values())
        self.assertIn("final", names.values())

    def test_keeps_schedule_when_server_is_down(self):
        now = time.time()
        self.agent.state["last_next"] = {"id": 9, "start_utc": int(now) + 600, "rec_start_utc": int(now) - 5,
                                         "rec_stop_utc": int(now) + 600, "subtitle": "x", "is_test": 0}
        self.srv.stop()  # server unreachable
        self.agent.tick()
        self.assertEqual(self.agent.state["recording_sid"], 9, "agent must follow the last known schedule offline")
        self.assertEqual(self.agent.state["outbox"][0][1]["event"], "started")  # queued for later
        self.agent.obs.stop()


class Units(unittest.TestCase):
    def test_cleanup_keeps_new_pinned_and_protected(self):
        tmp = Path(tempfile.mkdtemp())
        try:
            now = time.time()
            for name, age_days in [("old.mp4", 20), ("new.mp4", 1), ("pinned.mp4", 30), ("live.mp4", 30), ("notes.txt", 40)]:
                p = tmp / name
                p.write_bytes(b"x")
                os.utime(p, (now - age_days * 86400,) * 2)
            removed = cleanup.delete_expired([(str(tmp), "raw")], 14, {"pinned.mp4"}, {str((tmp / "live.mp4").resolve())}, now)
            self.assertEqual(removed, ["old.mp4"])
            inv = {f["name"] for f in cleanup.inventory([(str(tmp), "raw")])}
            self.assertEqual(inv, {"new.mp4", "pinned.mp4", "live.mp4"})
        finally:
            shutil.rmtree(tmp, ignore_errors=True)

    def test_intro_text_with_awkward_characters(self):
        tmp = Path(tempfile.mkdtemp())
        try:
            intro = {"duration": 2, "title": "It's 100%: a \"test\", with commas", "subtitle": "C:\\path\\x", "date": "", "description": "",
                     "description2": "", "text1": "", "text2": "", "text3": "", "text4": "", "background_color": "0x000000",
                     "text_color": "0xFFFFFF", "accent_color": "0x7F0000", "font": FONT}
            media.render_intro("ffmpeg", intro, {"width": 1280, "height": 720, "fps": "30000/1001", "audio_rate": 44100}, "i.mp4", tmp)
            self.assertAlmostEqual(duration(tmp / "i.mp4"), 2.0, delta=0.2)
        finally:
            shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    unittest.main()
