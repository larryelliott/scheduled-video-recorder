# OBS settings and processing speed

## "The recording file is there but 0 KB"

While OBS is still recording, Windows Explorer often keeps showing 0 KB for the file. The directory entry is only refreshed now and then
while a program holds the file open; the data is being written. Hybrid MP4 writes the recording in small pieces precisely so that a half-finished file
stays readable, which is also what lets the agent trim a sermon while OBS keeps recording. To see the real size, run
`.venv\Scripts\python.exe -m svagent probe` (it describes the newest recording) or reopen the folder after stopping.

## Where the files should live

Keep **raw recordings and finished videos in different folders**, for example:

| What | Folder |
|---|---|
| OBS recordings (raw, large) | `C:\Users\PeacePC\Videos` (OBS Settings > Output > Recording Path) |
| Finished videos | `C:\Users\PeacePC\Documents\video` (`OUTPUT_DIR` in `agent\.env`) |

Leave `RECORD_DIR` **blank** in `agent\.env`. The agent asks OBS where it saves and also looks in your Videos folder. If `RECORD_DIR` is set to a
different folder than OBS uses, `python -m svagent check` now says so.

Both folders are cleaned up after the retention period and listed on the website's Files page. In the raw folder the agent only touches files named the
way OBS names them (`2026-11-02 10-20-01.mp4`) or files it recorded itself, so unrelated videos in the same folder are safe.

## Smaller raw files, faster trimming

Trimming re-encodes the sermon, so processing time depends on how big the raw file is and how fast the PC encodes. Run
`.venv\Scripts\python.exe -m svagent probe` on a recent recording and look at the bitrate; a 1080p30 recording of a mostly still pulpit rarely needs more than
about 6 Mbit/s. If yours is far higher, these OBS settings (Settings > Output, Output Mode: **Advanced**, Recording tab) usually shrink the files a lot:

| Setting | Suggestion |
|---|---|
| Recording Format | Hybrid MP4 (keep) |
| Encoder | A hardware encoder if the PC has one (NVIDIA NVENC, Intel QuickSync, AMD AMF); otherwise x264 |
| Rate Control | CBR |
| Bitrate | 6000 Kbps (raise to 8000 if the picture looks soft) |
| Keyframe Interval | 2 s |
| Audio | Track 1 only, 160 Kbps |
| Settings > Video | Output (scaled) resolution 1920x1080, FPS 30 |

On the agent side, `X264_PRESET=veryfast` (the new default) is about twice as fast as the old `fast`. If the PC has a supported GPU, a hardware encoder in
`agent\.env` (`VIDEO_ENCODER` and `VIDEO_ENCODER_ARGS`, examples in `.env.example`) is far faster still. After each change, run a **test recording**
and look at the agent log: it prints how many times faster than real time the trim ran.

## Why not just copy the video without re-encoding?

Copying is nearly instant, but it only cuts at keyframes and, more importantly, the intro card must be encoded exactly like the OBS video or the joined file can
glitch where the intro ends. That depends on your encoder and settings. Run `probe` on a recording and send the output; with it I can tell whether a safe
"copy" mode is possible for your OBS setup.
