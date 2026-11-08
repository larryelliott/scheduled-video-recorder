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
    if (playlistId !== "") {
      var playlistItemId = addToPlaylist(playlistId, videoId);
    }
    
    return ContentService.createTextOutput(JSON.stringify({
      "status": "success",
      "message": "Video successfully uploaded and added to playlist!",
      "videoId": videoId,
      "playlistItemId": playlistItemId,
      "downloadedBytes": uploadResult.downloadedBytes,
      "expectedBytes": uploadResult.expectedBytes
    })).setMimeType(ContentService.MimeType.JSON);
    
  } catch (err) {
    return ContentService.createTextOutput(JSON.stringify({
      "status": "error",
      "message": err.message
    })).setMimeType(ContentService.MimeType.JSON);
  }
}

// Downloads and verifies the video before returning its YouTube ID and byte counts.
function upload(url, title, description, topics, privacyStatus, expectedBytes, fileName) {
  var source = UrlFetchApp.fetch(url, {muteHttpExceptions: true});
  if (source.getResponseCode() !== 200) {
    throw new Error("Video download returned HTTP " + source.getResponseCode() + ".");
  }
  var videoData = source.getBlob()
    .setName(fileName || "video.mp4")
    .setContentType("video/mp4");
  var actualBytes = videoData.getBytes().length;
  var headers = source.getHeaders();
  var contentLength = headerValue(headers, "Content-Length");
  var requiredBytes = Number(expectedBytes || contentLength || 0);
  console.log("Downloaded video: " + actualBytes + " bytes; expected: " + (requiredBytes || "not provided"));
  if (requiredBytes > 0 && actualBytes !== requiredBytes) {
    throw new Error("Video download was incomplete: expected " + requiredBytes + " bytes, received " + actualBytes + ".");
  }
  if (requiredBytes <= 0) {
    throw new Error("Could not verify the downloaded video size. Deploy the server update that sends expectedBytes.");
  }
  
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
  
  var response = YouTube.Videos.insert(resource, "snippet,status", videoData);
  return {videoId: response.id, downloadedBytes: actualBytes, expectedBytes: requiredBytes};
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
