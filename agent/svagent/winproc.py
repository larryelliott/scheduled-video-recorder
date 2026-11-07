"""Keeps helper programs (tasklist, ffmpeg, ffprobe) from flashing a console window on Windows."""
from __future__ import annotations

import os
import subprocess


def hidden_kwargs(extra_flags: int = 0) -> dict:
    """Extra keyword arguments for subprocess.run / Popen. Empty on other systems."""
    if os.name != "nt":
        return {}
    si = subprocess.STARTUPINFO()
    si.dwFlags |= subprocess.STARTF_USESHOWWINDOW
    si.wShowWindow = subprocess.SW_HIDE
    return {"creationflags": subprocess.CREATE_NO_WINDOW | extra_flags, "startupinfo": si}
