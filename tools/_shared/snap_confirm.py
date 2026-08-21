"""
SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
snap_confirm — one shared "are you sure, and to WHICH site?" dialog for the
SnapSmack desktop tools.

WHY THIS EXISTS
    Every action that commits over the network (post, publish, sync, delete)
    must name the destination SITE at the moment of the click — never a generic
    "SnapSmack". A dropped or mis-aimed click (Parkinson's-forgiving design) once
    posted to the wrong site; and two tools (COLD SNAP, SMACK YOUR MOUTH) used to
    fire live network writes with NO confirmation at all. This lifts SYBU's proven
    _on_post confirm (sybu/main.py — it names the real netloc) into one shared,
    Parkinson's-forgiving guard so every tool asks the same way instead of each
    rolling its own, or none. See ARCH-02 / ARCH-03 (2026-08-18 architecture audit).

USAGE
    import snap_confirm
    if not snap_confirm.confirm_post(url, len(ready), item="photo", action="Publish",
                                     parent=self):
        return                      # user said No — do nothing

DESIGN NOTES (Parkinson's-forgiving)
    * The destination site is ALWAYS named in the dialog body.
    * danger=True (irreversible/destructive actions) flips the default button to
      "No" and shows a warning icon, so a stray Enter/Space can't confirm a delete.
    * tkinter only; no other deps. Safe to import from any tool that already puts
      tools/_shared on sys.path.
"""
from __future__ import annotations

from urllib.parse import urlparse
from tkinter import messagebox


def site_netloc(base_url, fallback: str = "your site") -> str:
    """The bare host of a site URL, for naming a destination in a dialog.

    'https://foundtextures.ca/x' -> 'foundtextures.ca'; blank/garbage -> fallback.
    """
    try:
        b = str(base_url or "").strip()
        if not b:
            return fallback
        return urlparse(b if "://" in b else "https://" + b).netloc or b or fallback
    except Exception:
        return fallback


def _plural(n, one: str, many: str | None = None) -> str:
    many = many or (one + "s")
    return one if n == 1 else many


def confirm_post(base_url, count=None, *, item: str = "item", action: str = "Post",
                 parent=None, extra: str = "", danger: bool = False,
                 title: str = "Confirm") -> bool:
    """Yes/No confirm that NAMES the destination site. Returns True only on Yes.

    confirm_post(url, 4, item="photo", action="Publish")
        -> "Publish 4 photos to foundtextures.ca?"
    confirm_post(url, action="Send")
        -> "Send to foundtextures.ca?"      (count omitted)

    danger=True: default button No + warning icon (irreversible actions).
    """
    dest = site_netloc(base_url)
    if count is None:
        head = f"{action} to {dest}?"
    else:
        head = f"{action} {count} {_plural(count, item)} to {dest}?"
    body = head + (("\n\n" + extra) if extra else "")
    opts = {}
    if parent is not None:
        opts["parent"] = parent
    if danger:
        opts["icon"] = "warning"
        opts["default"] = "no"
    return bool(messagebox.askyesno(title, body, **opts))


def confirm_targets(urls, *, headline: str, parent=None, danger: str = "",
                    title: str = "Confirm") -> bool:
    """Multi-site confirm — names every destination site explicitly.

    urls:     iterable of site URLs the action will hit (deduped, order kept).
    headline: the summary line, e.g. "Apply 12 decisions (3 deletes)".
    danger:   optional warning line (e.g. "3 comments will be permanently
              DELETED."); when set, default button is No + warning icon.
    Returns True only on Yes.
    """
    dests = []
    for u in urls:
        d = site_netloc(u, fallback="")
        if d and d not in dests:
            dests.append(d)
    if not dests:
        where = "your site"
    elif len(dests) == 1:
        where = dests[0]
    else:
        where = f"{len(dests)} sites:\n  • " + "\n  • ".join(dests)
    body = f"{headline}\n\nDestination: {where}"
    if danger:
        body += f"\n\n⚠  {danger}"
    opts = {}
    if parent is not None:
        opts["parent"] = parent
    if danger:
        opts["icon"] = "warning"
        opts["default"] = "no"
    return bool(messagebox.askyesno(title, body, **opts))
# ===== SNAPSMACK EOF =====
