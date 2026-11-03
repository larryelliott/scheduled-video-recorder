"""ffmpeg pipeline: intro card + trimmed sermon, joined into one video."""
from __future__ import annotations

import json
import logging
import os
import subprocess
from pathlib import Path
from typing import Callable, Optional

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


def _flags() -> int:
    # Keep ffmpeg from starving OBS while it is still recording.
    return 0x00004000 if os.name == "nt" else 0  # BELOW_NORMAL_PRIORITY_CLASS


def run(cmd: list[str], cwd: Optional[Path] = None, on_seconds: Optional[Callable[[float], None]] = None) -> None:
    """Runs ffmpeg. With on_seconds, expects '-progress pipe:1' in cmd and reports output time."""
    log.debug("run: %s", " ".join(cmd))
    errors: list[str] = []
    with subprocess.Popen(cmd, cwd=str(cwd) if cwd else None, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                          text=True, creationflags=_flags()) as proc:
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
                         capture_output=True, text=True)
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


def _font(path: str) -> str:
    # Inside a drawtext option the drive colon must be escaped: C\:/Windows/Fonts/arial.ttf
    return path.replace("\\", "/").replace(":", "\\:")


def render_intro(ffmpeg: str, intro: dict, info: dict, out_name: str, work: Path) -> None:
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
         "-c:v", "libx264", "-preset", "fast", "-pix_fmt", "yuv420p", "-r", info["fps"],
         "-c:a", "aac", "-b:a", "160k", "-ar", str(ar), "-ac", "2", out_name], cwd=work)


def trim(ffmpeg: str, src: str, start: float, duration: float, info: dict, out_name: str, work: Path,
         progress: Progress = None) -> None:
    # -ss before -i seeks quickly instead of decoding from the start of a long recording.
    def on_sec(sec: float) -> None:
        if progress and duration > 0:
            progress(min(1.0, sec / duration), "trimming")

    run([ffmpeg, "-y", "-hide_banner", "-loglevel", "error", "-ss", f"{start:.3f}", "-i", src, "-t", f"{duration:.3f}",
         "-vf", "setsar=1", "-c:v", "libx264", "-preset", "fast", "-pix_fmt", "yuv420p",
         "-c:a", "aac", "-b:a", "192k", "-ar", str(info["audio_rate"]), "-ac", "2",
         "-progress", "pipe:1", "-nostats", out_name], cwd=work, on_seconds=on_sec)


def build_video(ffmpeg: str, ffprobe: str, src: str, start: float, duration: float, intro: dict,
                out_path: Path, work: Path, progress: Progress = None) -> Path:
    """Returns out_path. `start` and `duration` are seconds within the raw recording."""
    work.mkdir(parents=True, exist_ok=True)
    info = probe(ffprobe, src)
    if info["duration"] and start >= info["duration"]:
        raise MediaError(f"The sermon starts at {start:.0f}s but the recording is only {info['duration']:.0f}s long so far")
    if progress:
        progress(0.0, "making intro")
    render_intro(ffmpeg, intro, info, "intro.mp4", work)
    trim(ffmpeg, src, start, duration, info, "main.mp4", work, progress)
    if progress:
        progress(1.0, "joining")
    (work / "concat.txt").write_text("file 'intro.mp4'\nfile 'main.mp4'\n", encoding="utf-8")
    out_path.parent.mkdir(parents=True, exist_ok=True)
    tmp = out_path.with_name(out_path.stem + ".part.mp4")
    run([ffmpeg, "-y", "-hide_banner", "-loglevel", "error", "-f", "concat", "-safe", "0", "-i", "concat.txt",
         "-c", "copy", "-movflags", "+faststart", str(tmp)], cwd=work)
    final = probe(ffprobe, str(tmp))["duration"]
    expect = int(intro.get("duration", 5)) + duration
    if abs(final - expect) > 5:
        tmp.unlink(missing_ok=True)
        raise MediaError(f"Finished video is {final:.0f}s but about {expect:.0f}s was expected (the recording may end early)")
    os.replace(tmp, out_path)
    return out_path
