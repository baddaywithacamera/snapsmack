"""
snap_errors — one friendly error dialog for the SnapSmack desktop tools.

Instead of dumping a raw Python exception ("HTTPSConnectionPool ... Max retries
exceeded") into a messagebox — a dead end for a non-technical user — map the
common failures to a plain "here's what to do" line and tuck the technical
detail underneath a divider. tkinter only; safe to import from any tool that
already puts tools/_shared on sys.path. (ARCH-04b.)

USAGE
    import snap_errors
    try:
        ...
    except Exception as e:
        snap_errors.show_error("Import failed", e, parent=self)
"""
from __future__ import annotations

from tkinter import messagebox


def friendly_hint(exc) -> str:
    """A plain 'what to do' line for a common failure, or '' if none fits."""
    s = f"{type(exc).__name__}: {exc}".lower()
    if any(k in s for k in (
            "max retries", "connectionerror", "failed to establish",
            "name or service not known", "getaddrinfo", "timed out", "timeout",
            "connection refused", "connection aborted", "newconnectionerror",
            "remotedisconnected", "ssl")):
        return ("Couldn't reach the site. Check that you're online and that the "
                "Site URL is correct (it should start with https://).")
    if any(k in s for k in (
            "401", "unauthorized", "403", "forbidden", "invalid api key",
            "invalid key", "authentication", "not authorised", "not authorized")):
        return ("The site rejected your API key — it may be wrong or expired. "
                "Regenerate it in SnapSmack Admin → Settings → API Access "
                "and paste the new one.")
    if any(k in s for k in (
            "no such file", "filenotfounderror", "notadirectoryerror",
            "permissionerror", "permission denied", "errno 2", "errno 13")):
        return ("A file or folder couldn't be opened. Check the path exists and "
                "that you have permission to read it.")
    if "404" in s or "not found" in s:
        return ("The site didn't have what was requested (404). Check the Site "
                "URL and that it is a current SnapSmack install.")
    return ""


def show_error(title, exc, *, parent=None, hint=None):
    """Friendly error dialog: a plain remedy on top, technical detail below a
    divider. `hint` overrides the auto-detected remedy (pass '' to suppress)."""
    h = friendly_hint(exc) if hint is None else hint
    detail = (str(exc) or type(exc).__name__).strip()
    body = f"{h}\n\n———\nDetails: {detail}" if h else detail
    opts = {"parent": parent} if parent is not None else {}
    messagebox.showerror(title, body, **opts)
# ===== SNAPSMACK EOF =====
