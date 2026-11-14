"""A tiny stand-in for the PHP server, implementing the agent API in docs/API.md (used by the tests)."""
from __future__ import annotations

import json
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse


class FakeServer:
    def __init__(self, upload_dir: Path, token: str = "t" * 24):
        self.token = token
        self.upload_dir = upload_dir
        self.next_service = None
        self.job = None
        self.recording_events: list[dict] = []
        self.job_events: list[dict] = []
        self.uploads: list[Path] = []
        self.upload_expected_bytes: list[int] = []
        self.upload_size_override: int | None = None
        self.heartbeats = 0
        self.last_files: list = []
        fs = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):  # quiet
                pass

            def _auth(self) -> bool:
                if self.headers.get("X-Api-Token") != fs.token:
                    self._send(401, {"ok": False, "error": "Bad token"})
                    return False
                return True

            def _send(self, code: int, data: dict) -> None:
                body = json.dumps(data).encode()
                self.send_response(code)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def do_POST(self):
                if not self._auth():
                    return
                n = int(self.headers.get("Content-Length", 0))
                body = json.loads(self.rfile.read(n) or b"{}")
                path = urlparse(self.path).path
                if path == "/api/agent/heartbeat":
                    fs.heartbeats += 1
                    fs.last_files = body.get("files", [])
                    self._send(200, {"ok": True, "server_time": time.time(), "retention_days": 14, "pinned": [],
                                     "next_service": fs.next_service, "job": fs.job})
                elif path == "/api/agent/recording":
                    fs.recording_events.append(body)
                    self._send(200, {"ok": True})
                elif path == "/api/agent/job":
                    fs.job_events.append(body)
                    if body.get("state") == "done":
                        fs.job = None
                    self._send(200, {"ok": True})
                else:
                    self._send(404, {"ok": False})

            def do_PUT(self):
                if not self._auth():
                    return
                q = parse_qs(urlparse(self.path).query)
                n = int(self.headers.get("Content-Length", 0))
                fs.upload_expected_bytes.append(int(q.get("expected_bytes", [n])[0]))
                dest = fs.upload_dir / q["name"][0]
                with open(dest, "wb") as f:
                    left = n
                    while left > 0:
                        chunk = self.rfile.read(min(65536, left))
                        if not chunk:
                            break
                        f.write(chunk)
                        left -= len(chunk)
                fs.uploads.append(dest)
                stored_size = n if fs.upload_size_override is None else fs.upload_size_override
                self._send(200, {"ok": True, "size": stored_size})

        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.url = f"http://127.0.0.1:{self.httpd.server_address[1]}"
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)

    def start(self):
        self.thread.start()
        return self

    def stop(self):
        self.httpd.shutdown()
        self.httpd.server_close()
