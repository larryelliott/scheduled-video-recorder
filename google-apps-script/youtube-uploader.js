// The entry point for your PHP server's POST requests
function doPost(e) {
  try {
    // Parse the payload sent from your PHP server
    var data = JSON.parse(e.postData.contents);
    
    // Extract parameters or fall back to defaults if missing
    var url = data.url;
    var title = data.title || "Untitled Sermon Video";
    var description = data.description || "";
    var topics = data.topics || [];
    var privacyStatus = data.privacyStatus || "private";
    var playlistId = data.playlistId || "";
    
    if (!url) {
      throw new Error("Missing mandatory 'url' parameter.");
    }
    
    // 1. Call your upload function to post to YouTube
    var uploadResult = upload(url, title, description, topics, privacyStatus, data.expectedBytes, data.fileName);
    var videoId = uploadResult.videoId;
    
    // 2. Add the video to your specified playlist
    var playlistItemId = "";
    var playlistWarning = "";
    if (playlistId !== "") {
      try {
        playlistItemId = addToPlaylist(playlistId, videoId);
      } catch (playlistError) {
        playlistWarning = "Video uploaded, but adding it to the playlist failed: " + playlistError.message;
        console.log(playlistWarning);
      }
    }
    
    return ContentService.createTextOutput(JSON.stringify({
      "status": "success",
      "message": playlistWarning || "Video successfully uploaded and added to playlist!",
      "videoId": videoId,
      "playlistItemId": playlistItemId,
      "warning": playlistWarning,
      "downloadedBytes": uploadResult.downloadedBytes,
      "expectedBytes": uploadResult.expectedBytes
    })).setMimeType(ContentService.MimeType.JSON);
    
  } catch (err) {
    return ContentService.createTextOutput(JSON.stringify({
      "status": err.outcomeUnknown ? "unknown" : "error",
      "message": err.message
    })).setMimeType(ContentService.MimeType.JSON);
  }
}

var UPLOAD_CHUNK_BYTES = 4 * 1024 * 1024;
var UPLOAD_RETRIES = 3;
var YOUTUBE_RESUMABLE_URL = "https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status";

// Streams the source through bounded ranges into a YouTube resumable upload session.
function upload(url, title, description, topics, privacyStatus, expectedBytes, fileName) {
  var sourceChunk = readSourceChunk(url, 0, expectedBytes);
  var totalBytes = sourceChunk.totalBytes;
  console.log("Starting YouTube upload for " + (fileName || "video") + " (" + totalBytes + " bytes).");
  var resource = {
    snippet: {
      title: title,
      description: description,
      tags: topics
    },
    status: {
      privacyStatus: privacyStatus
    }
  };
  var token = ScriptApp.getOAuthToken();
  var session = UrlFetchApp.fetch(YOUTUBE_RESUMABLE_URL, {
    method: "post",
    contentType: "application/json; charset=UTF-8",
    payload: JSON.stringify(resource),
    headers: {
      Authorization: "Bearer " + token,
      "X-Upload-Content-Length": String(totalBytes),
      "X-Upload-Content-Type": "video/mp4"
    },
    muteHttpExceptions: true
  });
  if (session.getResponseCode() !== 200) {
    throw new Error("Could not start YouTube upload (HTTP " + session.getResponseCode() + "): " + session.getContentText().slice(0, 500));
  }
  var sessionUrl = headerValue(session.getHeaders(), "Location");
  if (!sessionUrl) {
    throw new Error("YouTube did not return a resumable upload URL.");
  }

  var offset = 0;
  var chunk = sourceChunk;
  var retries = 0;
  while (offset < totalBytes) {
    if (!chunk || chunk.start !== offset) {
      chunk = readSourceChunk(url, offset, totalBytes);
    }
    var end = offset + chunk.bytes.length - 1;
    var response = null;
    try {
      response = UrlFetchApp.fetch(sessionUrl, {
        method: "put",
        contentType: "video/mp4",
        payload: chunk.bytes,
        headers: {
          Authorization: "Bearer " + token,
          "Content-Range": "bytes " + offset + "-" + end + "/" + totalBytes
        },
        muteHttpExceptions: true
      });
    } catch (err) {
      response = null;
    }

    if (response && (response.getResponseCode() === 200 || response.getResponseCode() === 201)) {
      if (end + 1 !== totalBytes) {
        throw unknownUploadError("YouTube reported completion before all source bytes were sent.");
      }
      var completed;
      try {
        completed = JSON.parse(response.getContentText());
      } catch (err) {
        throw unknownUploadError("YouTube may have completed the upload, but its response could not be read.");
      }
      if (!completed.id) {
        throw unknownUploadError("YouTube may have completed the upload without returning a video ID.");
      }
      return {videoId: completed.id, downloadedBytes: totalBytes, expectedBytes: totalBytes};
    }

    if (response && response.getResponseCode() === 308) {
      var accepted = nextUploadOffset(response.getHeaders(), totalBytes);
      if (accepted > offset) {
        if (accepted === totalBytes) {
          var finished = checkUploadStatus(sessionUrl, token, totalBytes);
          if (finished.complete) {
            return {videoId: finished.video.id, downloadedBytes: totalBytes, expectedBytes: totalBytes};
          }
          throw unknownUploadError("YouTube received all video bytes but did not confirm the upload.");
        }
        offset = accepted;
        chunk = null;
        retries = 0;
        console.log("YouTube accepted " + offset + " of " + totalBytes + " bytes.");
        continue;
      }
      retries++;
    } else if (response && response.getResponseCode() >= 400 && response.getResponseCode() < 500) {
      throw new Error("YouTube rejected the upload (HTTP " + response.getResponseCode() + "): " + response.getContentText().slice(0, 500));
    } else {
      retries++;
    }

    var status = checkUploadStatus(sessionUrl, token, totalBytes);
    if (status.complete) {
      return {videoId: status.video.id, downloadedBytes: totalBytes, expectedBytes: totalBytes};
    }
    if (status.offset > offset) {
      offset = status.offset;
      if (offset === totalBytes) {
        var finalStatus = checkUploadStatus(sessionUrl, token, totalBytes);
        if (finalStatus.complete) {
          return {videoId: finalStatus.video.id, downloadedBytes: totalBytes, expectedBytes: totalBytes};
        }
        throw unknownUploadError("YouTube received all video bytes but did not confirm the upload.");
      }
      chunk = null;
      retries = 0;
      continue;
    }
    if (status.error) {
      throw unknownUploadError("YouTube upload outcome is unknown. Check YouTube before retrying. " + status.error);
    }
    if (retries >= UPLOAD_RETRIES) {
      throw unknownUploadError("YouTube upload stalled at byte " + offset + ". Check YouTube before retrying.");
    }
    Utilities.sleep(1000 * Math.pow(2, retries - 1));
  }

  throw new Error("YouTube upload ended without a confirmed video ID.");
}

function unknownUploadError(message) {
  var error = new Error(message);
  error.outcomeUnknown = true;
  return error;
}

function readSourceChunk(url, start, expectedTotal) {
  var requestedEnd = start + UPLOAD_CHUNK_BYTES - 1;
  if (expectedTotal && expectedTotal > 0) {
    requestedEnd = Math.min(requestedEnd, expectedTotal - 1);
  }
  var response = UrlFetchApp.fetch(url, {
    headers: {Range: "bytes=" + start + "-" + requestedEnd},
    muteHttpExceptions: true
  });
  if (response.getResponseCode() !== 206) {
    throw new Error("Video host must support byte-range requests; received HTTP " + response.getResponseCode() + ".");
  }
  var contentRange = headerValue(response.getHeaders(), "Content-Range");
  var match = /^bytes (\d+)-(\d+)\/(\d+)$/i.exec(contentRange);
  if (!match) {
    throw new Error("Video host returned an invalid Content-Range header.");
  }
  var rangeStart = Number(match[1]);
  var rangeEnd = Number(match[2]);
  var totalBytes = Number(match[3]);
  var bytes = response.getContent();
  if (rangeStart !== start || rangeEnd < rangeStart || rangeEnd > requestedEnd
      || bytes.length !== rangeEnd - rangeStart + 1) {
    throw new Error("Video host returned an incomplete or mismatched byte range.");
  }
  if (!Number.isSafeInteger(totalBytes) || totalBytes <= 0
      || (expectedTotal && Number(expectedTotal) !== totalBytes)) {
    throw new Error("Video size does not match the expected file size.");
  }
  if (rangeEnd !== requestedEnd && rangeEnd !== totalBytes - 1) {
    throw new Error("Video host returned a shorter-than-requested byte range.");
  }
  return {start: start, bytes: bytes, totalBytes: totalBytes};
}

function nextUploadOffset(headers, totalBytes) {
  var range = headerValue(headers, "Range");
  if (!range) {
    return 0;
  }
  var match = /^bytes=0-(\d+)$/i.exec(range);
  if (!match) {
    throw new Error("YouTube returned an invalid resumable upload range.");
  }
  var offset = Number(match[1]) + 1;
  if (offset > totalBytes) {
    throw new Error("YouTube reported more uploaded bytes than the source contains.");
  }
  return offset;
}

function checkUploadStatus(sessionUrl, token, totalBytes) {
  var response;
  try {
    response = UrlFetchApp.fetch(sessionUrl, {
      method: "put",
      contentType: "video/mp4",
      payload: "",
      headers: {
        Authorization: "Bearer " + token,
        "Content-Range": "bytes */" + totalBytes
      },
      muteHttpExceptions: true
    });
  } catch (err) {
    return {error: "Could not query the resumable upload session."};
  }
  if (response.getResponseCode() === 200 || response.getResponseCode() === 201) {
    var video;
    try {
      video = JSON.parse(response.getContentText());
    } catch (err) {
      return {error: "YouTube completed the upload but returned an unreadable response."};
    }
    return video.id ? {complete: true, video: video} : {error: "YouTube returned no video ID."};
  }
  if (response.getResponseCode() === 308) {
    return {offset: nextUploadOffset(response.getHeaders(), totalBytes)};
  }
  return {error: "Upload status check returned HTTP " + response.getResponseCode() + "."};
}

function headerValue(headers, name) {
  var target = name.toLowerCase();
  for (var key in headers) {
    if (key.toLowerCase() === target) {
      return headers[key];
    }
  }
  return "";
}

// Function to add a video to a specific playlist
function addToPlaylist(playlistId, videoId) {
  var resource = {
    snippet: {
      playlistId: playlistId,
      resourceId: {
        kind: "youtube#video",
        videoId: videoId
      }
    }
  };
  
  var response = YouTube.PlaylistItems.insert(resource, "snippet");
  return response.id; // Returns the unique relation ID inside the playlist
}

function testDoPost() {
  // 1. Simulate the payload format that your PHP cURL script sends
  var mockPayload = {
    "url": "URL_TO_YOUR_VIDEO_FILE", // Replace with an actual video URL for real testing
    "fileName": "test-video.mp4",
    "title": "doPost API Test Sermon 2",
    "description": "Testing the PHP entry point function directly within the editor.",
    "topics": ["API Test", "doPost"],
    "playlistId": "PLAYLIST_ID_FOR_TESTING" // Replace with an actual playlist ID for real testing
  };

  // 2. Build the exact event object structure ('e') that Apps Script expects
  var e = {
    postData: {
      contents: JSON.stringify(mockPayload)
    }
  };

  Logger.log("🚀 Simulating incoming POST request to doPost()...");

  try {
    // 3. Execute doPost synchronously with our fake event object
    var output = doPost(e);
    
    // 4. Read the text result returned by ContentService
    var responseText = output.getContent();
    Logger.log("✅ Response from doPost(): \n" + responseText);
    
  } catch (err) {
    Logger.log("❌ Execution crashed before returning text! Error: " + err.message);
  }
}
