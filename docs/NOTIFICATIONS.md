# Notifications

Each person chooses, in **Settings**, which events reach them and how: email, phone push (ntfy), or both.
New admins start with everything on; everyone else starts with everything off.

## Email

PHP's built-in `mail()` is the default, but shared hosts often block it or send it to spam. For reliable delivery, send through a real mailbox.
Put these in the server `.env`, then open **Settings** and tap **Send me a test email**. A failure shows the mail server's own message.

| Setting | Meaning |
|---|---|
| `SMTP_HOST` | Mail server name. Leave blank to use `mail()` |
| `SMTP_PORT` | `587` for STARTTLS (the usual), `465` for implicit TLS |
| `SMTP_SECURE` | `tls` (STARTTLS), `ssl` (implicit), or `none` |
| `SMTP_USER` / `SMTP_PASS` | The mailbox's full address and password |
| `MAIL_FROM` | The "from" address. Most servers require it to be the mailbox itself, so it defaults to `SMTP_USER` |
| `SMTP_VERIFY_PEER` | `1` (default) checks the server's certificate. Set `0` only if the certificate name does not match `SMTP_HOST` |

Examples (check your provider's current instructions):

- **DreamHost:** create a mailbox for your domain in the panel (Mail, Manage Email), then use the mail server name shown there, port 587, `tls`, and the full mailbox address and its password.
- **Gmail / Google Workspace:** `smtp.gmail.com`, port 587, `tls`, your address, and an **app password** (not your normal password; 2-step verification must be on).

The last email problem is shown to admins at the bottom of Settings and in the server's `data/error.log`.

## Phone push (ntfy)

Install the free ntfy app, subscribe to a long random topic, and type the same topic in Settings. `NTFY_SERVER` defaults to the public `https://ntfy.sh`.

## What is sent

The available events include recording started/stopped, pulpit light first turned on, video processing started, video ready, YouTube video published, needs attention (sermon times not found, recording missed, recording started automatically), errors, and offline alerts for the PC and light sensor. Offline alerts fire once per outage and can be silenced from the dashboard. Each person can enable email and/or phone push for each event in **Settings**.

When available, the recording-started notification includes a frame captured from the active recording, and the YouTube-published notification includes the video intro frame. Email clients may require permission to load remote images. ntfy receives the image as an attachment. The small JPEG previews are served through unguessable, event-specific URLs and removed after the configured video retention period; this does not expose the private video directory.

When YouTube publishing is configured, completed non-test videos are published automatically after processing. A confirmed success sends the YouTube-published notification. If the result is uncertain, the existing duplicate-prevention safeguard blocks another automatic attempt; check YouTube before using the guarded admin retry.
