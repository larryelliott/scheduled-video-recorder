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

Recording started, recording stopped, video ready, needs attention (sermon times not found, recording missed, recording started automatically),
errors, and offline alerts for the PC and the light sensor. Offline alerts fire once per outage and can be silenced from the dashboard.
