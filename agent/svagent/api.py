"""Talks to the web server. The PC only ever makes outbound requests."""
from __future__ import annotations

import logging
import time
from typing import Callable, Optional

import requests

log = logging.getLogger("svagent.api")


class ProgressFile:
    """File wrapper that reports upload progress. requests uses __len__ for Content-Length."""

    def __init__(self, path: str, callback: Optional[Callable[[int, int], None]] = None):
        import os
        self._f = open(path, "rb")
        self._total = os.path.getsize(path)
        self._sent = 0
        self._cb = callback
        self._last = 0.0

    def __len__(self) -> int:
        return self._total

    def read(self, n: int = -1) -> bytes:
        chunk = self._f.read(n)
        self._sent += len(chunk)
        now = time.time()
        if self._cb and (now - self._last > 3 or self._sent >= self._total):
            self._last = now
            self._cb(self._sent, self._total)
        return chunk

    def close(self) -> None:
        self._f.close()


class Server:
    def __init__(self, base_url: str, token: str, verify_tls: bool = True):
        self.base = base_url.rstrip("/")
        self.session = requests.Session()
        self.session.headers.update({"X-Api-Token": token, "User-Agent": "svagent"})
        self.session.verify = verify_tls
        self.offset = 0.0           # server clock minus local clock, in seconds
        self.last_error = ""

    def now(self) -> float:
        """Server-corrected time, so cut points line up with the light sensor's clock."""
        return time.time() + self.offset

    def _post(self, path: str, payload: dict, timeout: float = 15) -> Optional[dict]:
        try:
            r = self.session.post(self.base + path, json=payload, timeout=timeout)
            if r.status_code == 401:
                self.last_error = "Server rejected the token (check AGENT_TOKEN)"
                return None
            r.raise_for_status()
            self.last_error = ""
            return r.json()
        except (requests.RequestException, ValueError) as exc:
            self.last_error = str(exc)
            log.warning("POST %s failed: %s", path, exc)
            return None

    def heartbeat(self, payload: dict) -> Optional[dict]:
        t0 = time.time()
        data = self._post("/api/agent/heartbeat", payload)
        t1 = time.time()
        if data and data.get("ok") and "server_time" in data:
            self.offset = float(data["server_time"]) - (t0 + t1) / 2.0
        return data if data and data.get("ok") else None

    def recording(self, payload: dict) -> bool:
        data = self._post("/api/agent/recording", payload)
        return bool(data and data.get("ok"))

    def job(self, payload: dict) -> bool:
        timeout = 390 if payload.get("state") == "done" else 15
        data = self._post("/api/agent/job", payload, timeout=timeout)
        return bool(data and data.get("ok"))

    def upload(self, service_id: int, name: str, path: str,
               progress: Optional[Callable[[int, int], None]] = None, attempts: int = 3) -> dict:
        """PUT the video as a raw body (no multipart limits). Retries on failure."""
        last = "unknown error"
        for attempt in range(1, attempts + 1):
            body = ProgressFile(path, progress)
            try:
                r = self.session.put(self.base + "/api/agent/upload", params={"service_id": service_id, "name": name},
                                     data=body, timeout=(15, 1800))
                if r.status_code == 200 and r.json().get("ok"):
                    return r.json()
                last = f"HTTP {r.status_code}: {r.text[:200]}"
            except (requests.RequestException, ValueError) as exc:
                last = str(exc)
            finally:
                body.close()
            log.warning("Upload attempt %d failed: %s", attempt, last)
            time.sleep(min(30, 5 * attempt))
        raise RuntimeError("Upload failed: " + last)
