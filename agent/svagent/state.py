"""Small JSON state file so a restart (or a power cut) does not lose track of what was recorded."""
from __future__ import annotations

import json
import os
import threading
from pathlib import Path


class State(dict):
    def __init__(self, path: Path):
        super().__init__()
        self.path = path
        self._lock = threading.Lock()
        self.setdefault("segments", {})   # service id (str) -> [{"path", "started_utc", "stopped_utc"}]
        self.setdefault("recording_sid", None)
        self.setdefault("jobs_done", [])
        self.setdefault("outbox", [])     # events the server could not receive yet
        self.setdefault("last_next", None)
        self.setdefault("retention_days", 14)
        self.setdefault("pinned", [])
        self.setdefault("record_dir", "")
        if path.is_file():
            try:
                self.update(json.loads(path.read_text(encoding="utf-8")))
            except (OSError, ValueError):
                pass

    def save(self) -> None:
        with self._lock:
            tmp = self.path.with_suffix(".tmp")
            tmp.write_text(json.dumps(self, indent=1), encoding="utf-8")
            os.replace(tmp, self.path)
