# Pulpit light sensor (ESP8266)

The sensor sits under the pulpit light. The pastor turns that light on just before the sermon and off at the end,
which gives the system its cut points. It needs no changes to how it is mounted or used.

1. Install the Arduino IDE and the ESP8266 board package.
2. Install the libraries **BH1750** (Christopher Laws) and **NTPClient** (Fabrice Weinberg).
3. Copy `pulpit-light/secrets.h.example` to `pulpit-light/secrets.h` and fill in Wi-Fi, `API_URL` and `API_TOKEN`.
4. Flash `pulpit-light/pulpit-light.ino`, open the Serial Monitor at 115200 baud, and look for `POST lux=... -> 200`.

What changed from the old sketch: the token header, a `secrets.h` file, Wi-Fi recovery, readings are only marked as sent
after the server accepts them, it also reports signal strength and firmware version, and the nightly restart now waits for
24 h of uptime and a dark room instead of restarting on a fixed hour. The old restart may explain a daily gap
of 60 to 75 minutes in `esp.log`.
