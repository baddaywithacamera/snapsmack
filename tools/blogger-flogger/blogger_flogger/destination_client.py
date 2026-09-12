"""HTTPS client for the scoped BLOGGER FLOGGER destination API."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import json
import mimetypes
from pathlib import Path
from urllib.parse import urlencode, urlparse

import requests


class DestinationError(RuntimeError):
    pass


class DestinationClient:
    def __init__(self, site_url: str, api_key: str, *, session=None, timeout=90):
        site_url = site_url.strip().rstrip("/")
        parsed = urlparse(site_url)
        if parsed.scheme != "https" and parsed.hostname not in {"localhost", "127.0.0.1", "::1"}:
            raise DestinationError("The destination must use HTTPS.")
        self.base = site_url + "/api.php/bloggerflogger"
        self.api_key = api_key.strip()
        self.session = session or requests.Session()
        self.timeout = timeout

    def _request(self, method, path, *, json_body=None, params=None, files=None, data=None):
        url = self.base + path
        if params:
            url += "?" + urlencode(params)
        headers = {"Authorization": f"Bearer {self.api_key}", "Accept": "application/json"}
        try:
            response = self.session.request(method, url, json=json_body, files=files, data=data,
                                            headers=headers, timeout=self.timeout,
                                            allow_redirects=False)
        except requests.RequestException as exc:
            raise DestinationError(f"Could not reach the destination: {exc}") from exc
        if 300 <= response.status_code < 400:
            raise DestinationError("The destination redirected the authenticated request; refused.")
        try:
            payload = response.json()
        except ValueError as exc:
            raise DestinationError(f"Destination returned HTTP {response.status_code}, not JSON.") from exc
        if response.status_code >= 400 or payload.get("status") != "ok":
            raise DestinationError(payload.get("message") or f"Destination returned HTTP {response.status_code}.")
        return payload

    def preflight(self):
        return self._request("GET", "/preflight")

    def lookup(self, source_site_id: str, source_type: str, source_id: str):
        return self._request("GET", "/import-map", params={
            "source_site_id": source_site_id, "source_type": source_type, "source_id": source_id})

    def upload_media(self, path: str | Path, source_site_id: str, source_id: str,
                     *, alt="", source_checksum=""):
        path = Path(path)
        mime = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
        with path.open("rb") as stream:
            return self._request("POST", "/media", files={"file": (path.name, stream, mime)}, data={
                "source_site_id": source_site_id, "source_type": "media",
                "source_id": source_id, "source_checksum": source_checksum, "alt": alt})

    def create_post(self, body: dict):
        return self._request("POST", "/posts", json_body=body)

    def create_page(self, body: dict):
        return self._request("POST", "/pages", json_body=body)

    def create_comment(self, body: dict):
        return self._request("POST", "/comments", json_body=body)

    def verify(self, destination_type: str, destination_id: int):
        return self._request("GET", f"/verify/{destination_type}/{int(destination_id)}")

# ===== SNAPSMACK EOF =====
