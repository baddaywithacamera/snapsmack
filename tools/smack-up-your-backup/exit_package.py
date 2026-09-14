"""
Smack Up Your Backup — exit_package.py

Writes an EXIT PACKAGE inside a backup: TAKE YOUR SHIT WITH YOU's canonical
portable archive plus its WordPress and Ghost courtesy packages, into
<backup_dir>/exit/. Optional per profile ("exit_package"), OFF by default —
a full exit package includes every original once more and can double a
backup on a large archive, and some people have limited space.

Why this lives here and not in a web request: the in-site export was removed
in 0.7.5xx because building a whole site's export inside one PHP request was a
memory-limit fatal on a real archive. This runs on the owner's computer, paced
through the same read-only API TYSWY uses (core/tyswy-api.php), and resumes.

The engine is imported from tools/take-your-shit-with-you — one implementation,
not a copy. From source that folder is found relative to this file; in a frozen
build the SUYB spec must bundle those modules (see the note in README.md).

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
"""
from __future__ import annotations

import os
import sys
import threading
from typing import Callable, Optional

EXIT_SUBDIR = "exit"


class ExitPackageUnavailable(RuntimeError):
    """The TYSWY engine could not be imported — the package is skipped, the
    backup is not failed, and the reason is written to the log."""


def _tyswy_dir() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    frozen = getattr(sys, "_MEIPASS", "")
    for candidate in (os.path.join(frozen, "tyswy") if frozen else "",
                      os.path.normpath(os.path.join(here, "..", "take-your-shit-with-you"))):
        if candidate and os.path.isdir(candidate):
            return candidate
    raise ExitPackageUnavailable(
        "TAKE YOUR SHIT WITH YOU's engine is not beside this build, so the exit "
        "package was skipped. The recovery backup above is complete.")


def _load_engine():
    d = _tyswy_dir()
    # APPEND, never prepend: TYSWY ships its own config.py / main.py and so does
    # SUYB. Putting TYSWY first would shadow SUYB's for any later import.
    if d not in sys.path:
        sys.path.append(d)
    shared = os.path.normpath(os.path.join(d, "..", "_shared"))
    if os.path.isdir(shared) and shared not in sys.path:
        sys.path.append(shared)
    import export_engine   # noqa: E402
    import tyswy_client    # noqa: E402
    return export_engine, tyswy_client


def write_exit_package(
    site_url: str,
    api_key: str,
    backup_dir: str,
    *,
    on_log: Optional[Callable[[str], None]] = None,
    on_progress: Optional[Callable[[str, str, Optional[float]], None]] = None,
    cancel_event: Optional[threading.Event] = None,
    allow_http: bool = False,
    app_version: str = "",
) -> dict:
    """
    Run TYSWY's export into <backup_dir>/exit/ using the backup's own 'suyb'
    key (accepted by the export API since 0.7.711D). Returns a summary dict;
    raises ExitPackageUnavailable only when the engine cannot be found. Any
    other failure is returned in the summary as an error, never raised — an
    exit package must never fail the recovery backup it rides on.
    """
    log = on_log or (lambda m: None)
    export_engine, tyswy_client = _load_engine()

    dest = os.path.join(backup_dir, EXIT_SUBDIR)
    os.makedirs(dest, exist_ok=True)
    summary = {"path": dest, "ok": False, "errors": [], "warnings": [], "adapters": {}}

    try:
        client = tyswy_client.TyswyClient(site_url, api_key, app_version=app_version or "suyb",
                                          allow_http=allow_http)
        options = export_engine.ExportOptions(courtesy_wordpress=True, courtesy_ghost=True,
                                              media_concurrency=2)
        engine = export_engine.ExportEngine(
            client, dest, options=options, app_version=app_version or "suyb",
            on_log=lambda m: log(f"exit package: {m}"),
            on_progress=on_progress, cancel_event=cancel_event,
            client_factory=lambda: tyswy_client.TyswyClient(
                site_url, api_key, app_version=app_version or "suyb", allow_http=allow_http))
        report = engine.run()
    except export_engine.Cancelled:
        summary["errors"].append("exit package cancelled")
        return summary
    except Exception as e:                                   # noqa: BLE001 — reported, never raised
        text = str(e)
        if "403" in text or "401" in text or "key" in text.lower():
            text += (" — the site must be on 0.7.711D or newer for a backup key to read "
                     "the export API; older sites need a TYSWY key in TAKE YOUR SHIT WITH YOU.")
        summary["errors"].append(f"exit package failed: {text}")
        return summary

    summary["ok"] = True
    summary["root"] = getattr(report, "root", dest)
    summary["warnings"] = list(getattr(report, "warnings", []) or [])
    try:
        import json
        with open(os.path.join(summary["root"], "courtesy", "adapters.json"), encoding="utf-8") as f:
            summary["adapters"] = {k: {"items": v.get("items"), "losses": v.get("losses_count")}
                                   for k, v in (json.load(f).get("adapters") or {}).items()}
    except Exception:
        pass
    return summary

# ===== SNAPSMACK EOF =====
