# YouTube publishing Apps Script

The video manager sends a server-to-server request to a Google Apps Script web app. The script downloads the selected video from its public, unguessable URL, uploads it to the YouTube channel authorized by the script owner, and optionally adds it to a playlist. The media URL works without signing in, but grants access only to that one video; the server's private video directory is not exposed for browsing.

The working script is in [`youtube-uploader.js`](youtube-uploader.js).

## 1. Create the Apps Script project

1. Sign in to the Google account that owns or manages the YouTube channel, then create a project at [script.google.com](https://script.google.com/).
2. Replace the contents of `Code.gs` with `youtube-uploader.js` from this folder and save.
3. In the Apps Script editor, open **Services** (the plus icon), add **YouTube Data API v3**, and accept any prompt to enable the API in its associated Google Cloud project.
4. Confirm the YouTube channel is the one you intend to use. The script uploads as the account that authorizes it.

## 2. Authorize and test

The first execution that uses the YouTube service asks you to review and grant permissions. Use the channel-owning account and approve the requested access.

To test from the editor, edit `testDoPost()` in `youtube-uploader.js` and replace `URL_TO_YOUR_VIDEO_FILE` with a direct URL to a real video file and `PLAYLIST_ID_FOR_TESTING` with a playlist ID. Then select `testDoPost` in the function menu and click **Run**. Open **Execution log** to see the JSON response.

This test performs a real YouTube upload and may add the video to the specified playlist. The test function defaults to `private`; use a temporary test playlist and remove the test video afterward if appropriate. For production requests, the video manager explicitly requests public visibility.

The video URL must be reachable by Google without signing in and must return the video file itself, not a webpage, login screen, or HTML error page. Open the full URL in a private/incognito browser window to check access. A URL to a page that plays or links to a video is not necessarily a direct media-file URL.

## 3. Deploy as a web app

1. Select **Deploy → New deployment**, choose **Web app**, and configure:
   - **Execute as:** Me
   - **Who has access:** Anyone
2. Authorize the deployment if prompted, then deploy and copy the web app URL ending in `/exec`.
3. If you change the script later, select **Deploy → Manage deployments**, edit the deployment, choose **New version**, and deploy again. Saving the editor does not update an existing deployed version.

The endpoint must accept anonymous requests so the video manager's server can call it. If **Anyone** is unavailable, the Google Workspace administrator may prohibit public web apps; ask the administrator or deploy under an account whose policy allows it. Do not change access to “Anyone within [domain]”—the video manager server is not signed in to that domain.

Treat the `/exec` URL as a credential: anyone who obtains it can ask the script to upload videos using the authorizing Google account. Keep it only in the server's private `.env` file; never put it in browser code, commit it, or paste it into logs or public support requests.

## 4. Configure the video manager

In `server/private/.env`, set:

```dotenv
GOOGLE_APPS_SCRIPT_URL=https://script.google.com/macros/s/your-deployment-id/exec
YOUTUBE_PLAYLIST_ID=your_playlist_id
YOUTUBE_PUBLISH_TIMEOUT=360
```

Get the playlist ID from the playlist URL (the value of its `list` parameter). Keep `.env` private and do not commit it. The video manager automatically sends a JSON POST after a non-test video finishes processing and is stored on the server:

| Field | Meaning |
|---|---|
| `url` | Public, token-protected URL to the selected server-stored video. The script reads this field. |
| `videoUrl` | Alias for `url`, for compatibility. |
| `expectedBytes` | Exact stored file size. The script checks the downloaded blob against it before uploading. |
| `fileName` | Name assigned to the downloaded video blob. |
| `title` | Service title. |
| `description` | Service description. |
| `playlistId` | Destination playlist ID. |
| `privacyStatus` | `public` for the video's normal publishing flow. |

The script responds with JSON containing `status: "success"`, the YouTube `videoId`, and the verified `downloadedBytes` / `expectedBytes` when the upload succeeds. It responds with `status: "error"` and a message when it reports an error. The video manager records confirmed successes and failures; an unavailable or unrecognized response is treated as uncertain to avoid an automatic duplicate attempt. Check YouTube before taking any further action after an uncertain result.

## Troubleshooting

| Symptom | What to check |
|---|---|
| HTTP 401 or a Google sign-in/error page | The deployment must be **Execute as: Me** and **Who has access: Anyone**. Workspace policy may prevent anonymous access. |
| HTTP 405 | Confirm the video manager is configured with the deployed `/exec` URL, not an editor or test URL. Its server-side HTTP client follows Google's redirect. |
| `Missing mandatory 'url' parameter` | Ensure the latest script version is deployed and the incoming JSON includes `url`. The current video manager sends both `url` and `videoUrl`; the script must read `data.url`. |
| `Media type 'text/html' is not supported` | The URL returned HTML rather than the media file. Check for a login page, incorrect path, expired link, or web-server error; test the exact URL without signing in. |
| YouTube video is much shorter than the server file | Confirm the active site has the latest server code and Apps Script has a newly deployed version. Check the Apps Script execution log for downloaded and expected byte counts; the upload is rejected if the full response size cannot be verified. |
| Editor test succeeds, but server response is old or different | Deploy a **New version** and confirm the server `.env` contains that deployment's `/exec` URL. |
| Apps Script returns an error or no JSON | Open **Executions** in the Apps Script project and inspect the failed run and its error details. Avoid retrying an outcome that might have uploaded successfully; check YouTube first. |
