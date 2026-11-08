"""File inventory and two-week cleanup for the PC. Only video files directly inside the given folders are touched."""
from __future__ import annotations

import logging
import os
import time
from pathlib import Path
from typing import Iterable

log = logging.getLogger("svagent.cleanup")
VIDEO_EXT = (".mp4", ".mkv", ".mov", ".flv", ".ts")
MIN_AGE_SECONDS = 6 * 3600  # never touch anything modified in the last few hours


def norm(path: str) -> str:
    return os.path.normcase(os.path.normpath(str(path)))


def obs_named(name: str) -> bool:
    """OBS's default file name: '2026-11-02 21-15-51.mp4'."""
    try:
        time.strptime(Path(name).stem, "%Y-%m-%d %H-%M-%S")
        return True
    except ValueError:
        return False


def ours(kind: str, name: str, path: str, known_raw: Iterable[str]) -> bool:
    """Raw folders (like Videos) can hold unrelated files, so only OBS-named files or ones this agent recorded are ours."""
    return kind != "raw" or obs_named(name) or norm(path) in known_raw


def inventory(dirs: list[tuple[str, str]], known_raw: Iterable[str] = frozenset()) -> list[dict]:
    """dirs: [(folder, kind)] where kind is 'raw' or 'final'."""
    known_raw = set(known_raw)
    out: list[dict] = []
    for folder, kind in dirs:
        if not folder:
            continue
        try:
            for e in os.scandir(folder):
                if e.is_file() and e.name.lower().endswith(VIDEO_EXT) and not e.name.endswith(".part.mp4") \
                        and ours(kind, e.name, e.path, known_raw):
                    st = e.stat()
                    out.append({"name": e.name, "kind": kind, "size": st.st_size, "mtime_utc": int(st.st_mtime)})
        except OSError:
            continue
    return out


def delete_expired(dirs: list[tuple[str, str]], retention_days: int, pinned: set[str],
                   protect: set[str], now: float | None = None, known_raw: Iterable[str] = frozenset()) -> list[str]:
    now = now or time.time()
    known_raw = set(known_raw)
    limit = now - max(1, retention_days) * 86400
    removed: list[str] = []
    for folder, kind in dirs:
        if not folder:
            continue
        try:
            entries = list(os.scandir(folder))
        except OSError:
            continue
        for e in entries:
            if not (e.is_file() and e.name.lower().endswith(VIDEO_EXT)) or not ours(kind, e.name, e.path, known_raw):
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
