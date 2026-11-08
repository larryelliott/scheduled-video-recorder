"""ffmpeg pipeline: intro card + trimmed sermon, joined into one video."""
from __future__ import annotations

import json
import logging
import os
import shlex
import subprocess
import time
from pathlib import Path
from typing import Callable, Optional

from .winproc import hidden_kwargs

log = logging.getLogger("svagent.media")
Progress = Optional[Callable[[float, str], None]]

# Layout copied from the original video.php (1920x1080 canvas): (field, y, font size)
INTRO_ROWS = [
    ("title", 200, 72), ("subtitle", 320, 48), ("date", 380, 42),
    ("description", 500, 36), ("description2", 550, 36),
    ("text1", 680, 36), ("text2", 730, 36), ("text3", 780, 36), ("text4", 830, 36),
]


class MediaError(RuntimeError):
    pass


def _kwargs(low_priority: bool = True) -> dict:
    # No console window; low priority while OBS is still recording so ffmpeg never starves it.
    return hidden_kwargs(0x00004000 if low_priority else 0)  # BELOW_NORMAL_PRIORITY_CLASS


def video_args(venc: Optional[dict]) -> list[str]:
    """Encoder options. Default is software x264 at CRF 23 with the 'veryfast' preset; a hardware encoder can be chosen in .env."""
    v = venc or {}
    enc = v.get("encoder") or "libx264"
    if enc == "libx264":
        return ["-c:v", "libx264", "-preset", str(v.get("preset") or "veryfast"), "-crf", str(v.get("crf") or "23"),
                "-pix_fmt", "yuv420p"]
    return ["-c:v", enc, *shlex.split(v.get("extra") or ""), "-pix_fmt", "yuv420p"]


def run(cmd: list[str], cwd: Optional[Path] = None, on_seconds: Optional[Callable[[float], None]] = None,
        low_priority: bool = True) -> None:
    """Runs ffmpeg. With on_seconds, expects '-progress pipe:1' in cmd and reports output time."""
    log.debug("run: %s", " ".join(cmd))
    errors: list[str] = []
    with subprocess.Popen(cmd, cwd=str(cwd) if cwd else None, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                          text=True, **_kwargs(low_priority)) as proc:
        assert proc.stdout is not None
        for line in proc.stdout:
            line = line.strip()
            if line.startswith(("out_time_us=", "out_time_ms=")) and on_seconds:
                try:
                    on_seconds(max(0.0, int(line.split("=", 1)[1]) / 1_000_000))
                except ValueError:
                    pass
            elif "=" not in line and line:
                errors.append(line)
        code = proc.wait()
    if code != 0:
        raise MediaError("ffmpeg failed: " + " | ".join(errors[-5:]))


def probe(ffprobe: str, path: str) -> dict:
    out = subprocess.run([ffprobe, "-v", "error", "-show_streams", "-show_format", "-of", "json", path],
                         capture_output=True, text=True, **hidden_kwargs())
    if out.returncode != 0:
        raise MediaError("ffprobe could not read the file: " + out.stderr.strip()[:200])
    data = json.loads(out.stdout)
    v = next((s for s in data["streams"] if s.get("codec_type") == "video"), None)
    a = next((s for s in data["streams"] if s.get("codec_type") == "audio"), None)
    if v is None:
        raise MediaError("The recording has no video stream")
    fps = v.get("r_frame_rate") or "30/1"
    if fps.startswith("0/") or fps == "0":
        fps = "30/1"
    return {
        "width": int(v["width"]), "height": int(v["height"]), "fps": fps,
        "audio_rate": int(a["sample_rate"]) if a and a.get("sample_rate") else 48000,
        "duration": float(data["format"].get("duration") or 0),
    }


def capture_frame(ffmpeg: str, src: str, out_path: Path, seconds: float = 0.5) -> bytes:
    """Extracts a small JPEG preview from a recording or rendered intro."""
    out_path.parent.mkdir(parents=True, exist_ok=True)
    result = subprocess.run(
        [ffmpeg, "-y", "-hide_banner", "-loglevel", "error", "-ss", f"{max(0.0, seconds):.3f}",
         "-i", src, "-frames:v", "1", "-vf", "scale=640:-2", "-q:v", "6", str(out_path)],
        capture_output=True, text=True, timeout=20, **hidden_kwargs())
    if result.returncode != 0 or not out_path.is_file():
        raise MediaError("Could not capture video preview: " + result.stderr.strip()[:200])
    image = out_path.read_bytes()
    if not image.startswith(b"\xff\xd8\xff") or len(image) > 300_000:
        raise MediaError("Captured preview is not a valid small JPEG")
    return image


def _font(path: str) -> str:
    # Inside a drawtext option the drive colon must be escaped: C\:/Windows/Fonts/arial.ttf
    return path.replace("\\", "/").replace(":", "\\:")


def render_intro(ffmpeg: str, intro: dict, info: dict, out_name: str, work: Path, venc: Optional[dict] = None,
                 low_priority: bool = True) -> None:
    dur = int(intro.get("duration", 5))
    fg, bg, accent = intro["text_color"], intro["background_color"], intro["accent_color"]
    font = _font(intro["font"])
    filters = []
    for key, y, size in INTRO_ROWS:
        text = (intro.get(key) or "").strip()
        if not text:
            continue
        # Text goes through a file, so quotes, colons, % and commas never need escaping.
        (work / f"t_{key}.txt").write_text(text, encoding="utf-8")
        filters.append(f"drawtext=fontfile='{font}':textfile=t_{key}.txt:fontcolor={fg}:fontsize={size}:x=(w-text_w)/2:y={y}")
        if key == "title":
            filters.append(f"drawbox=x=100:y=270:w=1720:h=10:color={accent}:t=fill")
    if (info["width"], info["height"]) != (1920, 1080):
        filters.append(f"scale={info['width']}:{info['height']}")
    filters += ["setsar=1", f"fade=t=out:st={max(0, dur - 1)}:d=1", "format=yuv420p"]
    ar = info["audio_rate"]
    run([ffmpeg, "-y", "-hide_banner", "-loglevel", "error",
         "-f", "lavfi", "-i", f"color=c={bg}:s=1920x1080:r={info['fps']}:d={dur}",
         "-f", "lavfi", "-i", f"anullsrc=r={ar}:cl=stereo",
         "-vf", ",".join(filters), "-af", f"afade=t=out:st={max(0, dur - 1)}:d=1", "-t", str(dur),
         *video_args(venc), "-r", info["fps"],
         "-c:a", "aac", "-b:a", "160k", "-ar", str(ar), "-ac", "2", out_name], cwd=work, low_priority=low_priority)


def trim(ffmpeg: str, src: str, start: float, duration: float, info: dict, out_name: str, work: Path,
         progress: Progress = None, venc: Optional[dict] = None, low_priority: bool = True) -> None:
    # -ss before -i seeks quickly instead of decoding from the start of a long recording.
    def on_sec(sec: float) -> None:
        if progress and duration > 0:
            progress(min(1.0, sec / duration), "trimming")

    run([ffmpeg, "-y", "-hide_banner", "-loglevel", "error", "-ss", f"{start:.3f}", "-i", src, "-t", f"{duration:.3f}",
         "-vf", "setsar=1", *video_args(venc),
         "-c:a", "aac", "-b:a", "192k", "-ar", str(info["audio_rate"]), "-ac", "2",
         "-progress", "pipe:1", "-nostats", out_name], cwd=work, on_seconds=on_sec, low_priority=low_priority)


def build_video(ffmpeg: str, ffprobe: str, src: str, start: float, duration: float, intro: dict,
                out_path: Path, work: Path, progress: Progress = None, venc: Optional[dict] = None,
                low_priority: Optional[Callable[[], bool]] = None) -> Path:
    """Returns out_path. `start` and `duration` are seconds within the raw recording."""
    work.mkdir(parents=True, exist_ok=True)
    info = probe(ffprobe, src)
    if info["duration"] and start >= info["duration"]:
        raise MediaError(f"The sermon starts at {start:.0f}s but the recording is only {info['duration']:.0f}s long so far")
    if progress:
        progress(0.0, "making intro")
    low = (lambda: True) if low_priority is None else low_priority
    render_intro(ffmpeg, intro, info, "intro.mp4", work, venc, low())
    t0 = time.time()
    trim(ffmpeg, src, start, duration, info, "main.mp4", work, progress, venc, low())
    took = time.time() - t0
    log.info("Trimmed %.0fs of video in %.0fs (%.1fx real time)", duration, took, duration / max(took, 0.1))
    if progress:
        progress(1.0, "joining")
    (work / "concat.txt").write_text("file 'intro.mp4'\nfile 'main.mp4'\n", encoding="utf-8")
    out_path.parent.mkdir(parents=True, exist_ok=True)
    tmp = out_path.with_name(out_path.stem + ".part.mp4")
    run([ffmpeg, "-y", "-hide_banner", "-loglevel", "error", "-f", "concat", "-safe", "0", "-i", "concat.txt",
         "-c", "copy", "-movflags", "+faststart", str(tmp)], cwd=work, low_priority=low())
    final = probe(ffprobe, str(tmp))["duration"]
    expect = int(intro.get("duration", 5)) + duration
    if abs(final - expect) > 5:
        tmp.unlink(missing_ok=True)
        raise MediaError(f"Finished video is {final:.0f}s but about {expect:.0f}s was expected (the recording may end early)")
    os.replace(tmp, out_path)
    return out_path
