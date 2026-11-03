# The schedule (Google Sheet)

Share the sheet as **Anyone with the link can view**, then put its id (the long part of the URL between `/d/` and `/edit`) in `SHEET_ID`.
If your service list is on a later tab, set `SHEET_GID` to the number after `gid=` in the address bar.

The first row must contain these column names (any order, capitalisation does not matter):

| Column | Used for |
|---|---|
| `Date` | Any date format Google or PHP understands, e.g. `Sunday, November 1, 2026` or `11/1/2026` |
| `Service Time` | e.g. `10:30 AM`. Rows without a time are skipped unless `DEFAULT_SERVICE_TIME` is set |
| `Subtitle` | Second line of the intro; also becomes the video file name, with spaces and punctuation turned into hyphens (`All-Saints-Day.mp4`). Using the same subtitle twice makes the second video replace the first one on the PC |
| `Description` | First description line on the intro |
| `Description2` | Second description line |
| `Text4` | Bottom line of the intro |

Extra columns are ignored. Rows with no date are ignored. Times are read in `APP_TIMEZONE`.

The title and the three fixed lines (`INTRO_TITLE`, `INTRO_TEXT1` to `INTRO_TEXT3`) come from the server `.env`.
The server re-reads the sheet about every 10 minutes while the PC is checking in, at every cron run, and when someone taps **Refresh schedule**.
Services edited on the website are never overwritten by the sheet; services you delete from the sheet disappear from the site unless they were edited.
To skip a week, tap **Skip this service** on the website.
