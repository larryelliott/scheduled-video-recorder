"""File inventory and two-week cleanup for the PC. Only video files directly inside the given folders are touched."""
from __future__ import annotations

import logging
import os
import time
from pathlib import Path

log = logging.getLogger("svagent.cleanup")
VIDEO_EXT = (".mp4", ".mkv", ".mov", ".flv", ".ts")
MIN_AGE_SECONDS = 6 * 3600  # never touch anything modified in the last few hours


def inventory(dirs: list[tuple[str, str]]) -> list[dict]:
    """dirs: [(folder, kind)] where kind is 'raw' or 'final'."""
    out: list[dict] = []
    for folder, kind in dirs:
        if not folder:
            continue
        try:
            for e in os.scandir(folder):
                if e.is_file() and e.name.lower().endswith(VIDEO_EXT) and not e.name.endswith(".part.mp4"):
                    st = e.stat()
                    out.append({"name": e.name, "kind": kind, "size": st.st_size, "mtime_utc": int(st.st_mtime)})
        except OSError:
            continue
    return out


def delete_expired(dirs: list[tuple[str, str]], retention_days: int, pinned: set[str],
                   protect: set[str], now: float | None = None) -> list[str]:
    now = now or time.time()
    limit = now - max(1, retention_days) * 86400
    removed: list[str] = []
    for folder, _kind in dirs:
        if not folder:
            continue
        try:
            entries = list(os.scandir(folder))
        except OSError:
            continue
        for e in entries:
            if not (e.is_file() and e.name.lower().endswith(VIDEO_EXT)):
                continue
            if e.name in pinned or str(Path(e.path).resolve()) in protect:
                continue
            m = e.stat().st_mtime
            if m < limit and now - m > MIN_AGE_SECONDS:
                try:
                    os.remove(e.path)
                    removed.append(e.name)
                    log.info("Deleted expired file %s", e.path)
                except OSError as exc:
                    log.warning("Could not delete %s: %s", e.path, exc)
    return removed
