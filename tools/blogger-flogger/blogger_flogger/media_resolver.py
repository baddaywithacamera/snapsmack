"""Bounded HTTPS acquisition for media referenced by Blogger entries."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import hashlib
import ipaddress
import os
import socket
import re
from pathlib import Path
from urllib.parse import urljoin, urlparse

import requests


class MediaRefused(ValueError):
    pass


def original_quality_url(url: str) -> str:
    """Ask Blogger's image CDN for the original asset, not a feed thumbnail."""
    parsed = urlparse(url)
    host = (parsed.hostname or "").lower()
    if not (host.endswith("blogger.googleusercontent.com") or
            host.endswith("blogspot.com") or host.endswith("googleusercontent.com")):
        return url
    path = re.sub(r"/(?:s|w)\d+(?:-[a-z0-9-]+)?/", "/s0/", parsed.path,
                  count=1, flags=re.IGNORECASE)
    path = re.sub(r"=(?:s|w)\d+(?:-[a-z0-9-]+)?$", "=s0", path,
                  count=1, flags=re.IGNORECASE)
    return parsed._replace(path=path).geturl()


def _public_host(host: str) -> bool:
    try:
        records = socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)
    except socket.gaierror:
        return False
    if not records:
        return False
    for record in records:
        ip = ipaddress.ip_address(record[4][0].split("%", 1)[0])
        if not ip.is_global:
            return False
    return True


def validate_url(url: str) -> str:
    parsed = urlparse(url)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise MediaRefused("Referenced media must use a credential-free HTTPS URL.")
    if parsed.port not in {None, 443}:
        raise MediaRefused("Referenced media uses a non-standard network port.")
    if not _public_host(parsed.hostname):
        raise MediaRefused("Referenced media does not resolve exclusively to public addresses.")
    return url


def download(url: str, directory: str | os.PathLike[str], *, session=None,
             max_bytes=20 * 1024**3, redirects=5) -> tuple[Path, str, str]:
    session = session or requests.Session()
    current = validate_url(original_quality_url(url))
    response = None
    for _ in range(redirects + 1):
        response = session.get(current, stream=True, timeout=(15, 120), allow_redirects=False,
                               headers={"User-Agent": "BLOGGER-FLOGGER/0.1"})
        if response.status_code in {301, 302, 303, 307, 308}:
            location = response.headers.get("Location", "")
            response.close()
            if not location:
                raise MediaRefused("Media redirect has no destination.")
            current = validate_url(urljoin(current, location))
            continue
        break
    if response is None or 300 <= response.status_code < 400:
        raise MediaRefused("Referenced media redirected too many times.")
    if response.status_code != 200:
        raise MediaRefused(f"Referenced media returned HTTP {response.status_code}.")
    declared = int(response.headers.get("Content-Length", "0") or 0)
    if declared > max_bytes:
        response.close(); raise MediaRefused("Referenced media exceeds the size limit.")
    mime = response.headers.get("Content-Type", "application/octet-stream").split(";", 1)[0].strip()
    suffixes = {"image/jpeg": ".jpg", "image/png": ".png", "image/gif": ".gif",
                "image/webp": ".webp", "image/avif": ".avif"}
    if mime not in suffixes:
        response.close(); raise MediaRefused(f"Referenced file is not a supported image ({mime}).")
    root = Path(directory); root.mkdir(parents=True, exist_ok=True)
    temp = root / (hashlib.sha256(current.encode()).hexdigest() + suffixes[mime] + ".part")
    digest = hashlib.sha256(); received = 0
    try:
        with temp.open("wb") as out:
            for chunk in response.iter_content(1024 * 1024):
                if not chunk:
                    continue
                received += len(chunk)
                if received > max_bytes:
                    raise MediaRefused("Referenced media exceeded the size limit while downloading.")
                digest.update(chunk); out.write(chunk)
    except Exception:
        temp.unlink(missing_ok=True)
        raise
    finally:
        response.close()
    final = temp.with_suffix("")
    temp.replace(final)
    return final, digest.hexdigest(), mime

# ===== SNAPSMACK EOF =====
