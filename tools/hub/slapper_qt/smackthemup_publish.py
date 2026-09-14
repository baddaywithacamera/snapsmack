"""Narrow SMACKTHEMUP publishing client used only by SNAP SLAPPER."""

from __future__ import annotations

import json
import mimetypes
import os
import re
import uuid
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


USER_AGENT = "SNAP-SLAPPER/0.7"


class PublishError(RuntimeError):
    pass


class _SameHostRedirect(HTTPRedirectHandler):
    """Never let a site redirect its narrow publishing credential elsewhere."""
    def redirect_request(self, request, fp, code, message, headers, new_url):
        old = urlsplit(request.full_url)
        new = urlsplit(new_url)
        if new.scheme != "https" or new.hostname != old.hostname:
            raise PublishError("The site redirected publishing to another host; the key was not sent.")
        return super().redirect_request(request, fp, code, message, headers, new_url)


_open = build_opener(_SameHostRedirect()).open


def _endpoint(site_url: str, resource: str) -> str:
    base = site_url.strip().rstrip("/") + "/"
    if not base.lower().startswith("https://"):
        raise PublishError("SMACKTHEMUP publishing requires an HTTPS site address.")
    return urljoin(base, "api.php?") + urlencode({"route": f"smackthemup/{resource}"})


def _request(site_url, resource, api_key, *, method="GET", body=None,
             content_type="application/json", timeout=45):
    if not api_key or len(api_key.strip()) != 64:
        raise PublishError("A 64-character SNAP SLAPPER publishing key is required.")
    headers = {
        "Authorization": f"Bearer {api_key.strip()}",
        "Accept": "application/json",
        "User-Agent": USER_AGENT,
    }
    if body is not None:
        headers["Content-Type"] = content_type
    request = Request(_endpoint(site_url, resource), data=body, headers=headers,
                      method=method)
    try:
        with _open(request, timeout=timeout) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except HTTPError as error:
        try:
            payload = json.loads(error.read().decode("utf-8"))
            message = payload.get("error") or str(error)
        except Exception:
            message = str(error)
        raise PublishError(message) from error
    except (URLError, OSError, ValueError) as error:
        raise PublishError(f"The site could not be reached: {error}") from error
    if not isinstance(payload, dict) or not payload.get("ok"):
        raise PublishError(str(payload.get("error") if isinstance(payload, dict)
                               else "The site returned an invalid reply."))
    return payload


def capabilities(site_url, api_key):
    result = _request(site_url, "capabilities", api_key)
    if result.get("site_mode") != "smackthemup" or result.get("federation") is not False:
        raise PublishError("This profile is not a non-federated SMACKTHEMUP site.")
    return result


def upload_jpeg(site_url, api_key, path):
    boundary = "----SnapSlapper" + uuid.uuid4().hex
    filename = re.sub(r"[^A-Za-z0-9_.-]", "_", os.path.basename(path)) or "photo.jpg"
    with open(path, "rb") as handle:
        image = handle.read()
    mime = mimetypes.guess_type(filename)[0] or "image/jpeg"
    prefix = (
        f"--{boundary}\r\n"
        f'Content-Disposition: form-data; name="image"; filename="{filename}"\r\n'
        f"Content-Type: {mime}\r\n\r\n"
    ).encode("utf-8")
    body = prefix + image + f"\r\n--{boundary}--\r\n".encode("ascii")
    return _request(site_url, "upload", api_key, method="POST", body=body,
                    content_type=f"multipart/form-data; boundary={boundary}", timeout=120)


def publish(site_url, api_key, record):
    body = json.dumps(record, ensure_ascii=False).encode("utf-8")
    return _request(site_url, "publish", api_key, method="POST", body=body)


def publish_photo(site_url, api_key, jpeg_path, *, title, caption="", alt="",
                  tags=None, category_ids=None, album_ids=None, date="",
                  idempotency_key=""):
    caps = capabilities(site_url, api_key)
    uploaded = upload_jpeg(site_url, api_key, jpeg_path)
    record = {
        "path": uploaded["path"],
        "title": title,
        "caption": caption,
        "alt": alt,
        "tags": list(tags or []),
        "category_ids": list(category_ids or []),
        "album_ids": list(album_ids or []),
        "visibility": "public",
        "date": date,
        "idempotency_key": idempotency_key or uuid.uuid4().hex,
    }
    return publish(site_url, api_key, record), caps


# ===== SNAPSMACK EOF =====
