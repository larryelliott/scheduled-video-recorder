# Scheduled Video Recorder

Records a church service on a Windows PC with OBS, finds the sermon from a light sensor under the pulpit,
trims it, adds an intro card, and puts the finished video on a web page you can open from your phone.
Built so a pastor, not a programmer, can run it.

```
 Google Sheet ──(schedule)──►  Web server (PHP + SQLite)  ◄──(light readings)──  ESP8266 sensor
   (service times, titles)       the phone-friendly site         under the pulpit light
                                   ▲        │ schedule, sermon times
                       heartbeat,  │        ▼
                       video       └──── Windows PC agent (Python) ───► OBS (WebSocket) + ffmpeg
```

- **One place to look.** A mobile web page shows what is happening now, what is next, a preview of the video intro, and the finished videos.
- **Nothing to schedule on the PC.** The agent asks the server for the next service, starts OBS 10 minutes early, stops after 100 minutes,
  and starts trimming as soon as the light sensor says the sermon is over (the recording keeps running).
- **Sign in once.** Each person gets a private magic link. Open it, tap one button, and that phone stays signed in for a year.
- **Notifications you choose.** Email and/or phone push (ntfy), per person and per event. Offline alerts fire once and can be silenced until fixed.
- **Self-cleaning.** Videos on the server and on the PC are deleted after 14 days unless someone taps Keep.
- **Easy to test.** "Run a test recording" makes a 100-second recording and walks it through the whole chain.

## Parts

| Folder | What it is | Runs on |
|---|---|---|
| `server/` | PHP site and API, SQLite database | any shared PHP 8 host (built for DreamHost) |
| `agent/` | Python agent: OBS control, ffmpeg, upload, cleanup | the Windows recording PC |
| `firmware/` | ESP8266 sketch for the light sensor | the sensor |
| `docs/` | install guide, API contract, Google Sheet format | |

Start with [`docs/INSTALL.md`](docs/INSTALL.md).

## Checking that it works

```
php server/private/bin/selftest.php          # server logic, using real recorded light data
cd agent && python -m unittest discover -s tests -v   # agent, with a fake OBS, fake server and real ffmpeg
```

## Credits and licence

MIT licence, see `LICENSE`. Settings and secrets live in `.env` files and `secrets.h`, none of which are in the repository.
