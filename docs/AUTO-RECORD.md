# Automatic recording from the pulpit light

If the pulpit light turns on and **no recording is scheduled**, the server creates a recording by itself. It ends when the light goes off, or after
`AUTO_LIGHT_MAX_MINUTES` (default 30), whichever comes first. Set `AUTO_LIGHT_RECORD=0` to switch it off.

- It only reacts to the moment the light turns on (a reading above `CUT_LUX_ON` after one below it), not to a light that is simply still on.
- It does nothing if a scheduled recording window is open right now (including a service you skipped on purpose), or if a scheduled recording is about to
  open within the next `AUTO_LIGHT_MAX_MINUTES`; that one will cover it.
- The Windows PC checks in every 20 seconds, so recording begins up to about 20 seconds after the light comes on. The first seconds of the sermon may be
  missing from the video; the agent trims to whatever was recorded.
- The new service is named **Sermon YYYY-MM-DD**, marked "started automatically", and you get a "needs attention" notification. Open it to change the
  subtitle and intro, then tap **Make the video again** so the finished video uses them.
- The **Pretend light ON/OFF** buttons trigger this too, which makes it easy to test.
