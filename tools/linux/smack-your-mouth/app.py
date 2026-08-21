#!/usr/bin/env python3
"""
SMACK YOUR MOUTH — Linux Chrome/Blink port.

The window is HTML drawn by Chromium (via snap_blink); the WORK is the original
Python — moderation_offline (engine), moderation_api (transport), fleet (shared
store), config (shared home), all reached through the GUI-free MouthCore that was
factored out of the tkinter main.py. No tkinter is imported here.

Run on Linux with ./run.sh (sets PYTHONPATH to _shared + the tool root).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                       # tools/smack-your-mouth/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")       # tools/_shared/
for _p in (SHARED, TOOL_ROOT):
    _abs = os.path.abspath(_p)
    if _abs not in sys.path:
        sys.path.insert(0, _abs)

import snap_blink
from smackmouth_core import MouthCore, BUILD_VERSION

app = snap_blink.App(
    tool="smackmouth",
    title=f"SMACK YOUR MOUTH  —  build {BUILD_VERSION}",
    web_dir=os.path.join(HERE, "web"),
)

# One controller for the life of the window (mirrors the single tk.Tk App).
core = MouthCore()


# ── whole-window state ───────────────────────────────────────────────────────
@app.api
def load_state():
    """Everything the page needs on open: engine status, config, sessions,
    fleet, and the current queue. Mirrors App.__init__ + _build_ui's first paint."""
    return core.state_payload()


# ── session lifecycle (SESSION combobox / NEW SESSION) ───────────────────────
@app.api
def select_session(session_id):
    return core.select_session(session_id)


@app.api
def new_session():
    return core.new_session()


# ── reply author (REPLY AS field) ────────────────────────────────────────────
@app.api
def set_author(author):
    return core.set_author(author)


# ── fleet (REFRESH FLEET / PROBE (LIVE)) ─────────────────────────────────────
@app.api
def refresh_fleet():
    return core.refresh_fleet()


@app.api
def probe_fleet():
    return core.probe_fleet()


# ── pull (PULL ALL PENDING / PULL THIS SITE) ─────────────────────────────────
@app.api
def pull_all():
    return core.pull_all()


@app.api
def pull_one(url, key):
    return core.pull_one(url, key)


# ── queue refresh (re-render the comment queue) ──────────────────────────────
@app.api
def load_queue():
    return core.queue_payload()


# ── moderation controls (APPROVE / DELETE / SPAM / CLEAR) ────────────────────
@app.api
def set_decision(item_id, action, reply_text=None):
    return core.set_decision(item_id, action, reply_text)


# ── reply (SAVE REPLY) ───────────────────────────────────────────────────────
@app.api
def save_reply(item_id, reply_text, author=None):
    return core.save_reply(item_id, reply_text, author)


@app.api
def flush_replies(edits):
    """Save a batch of [item_id, text] reply edits (used just before a sync so a
    typed-but-unsaved reply is never lost). Mirrors _flush_unsaved_replies."""
    return core.flush_replies(edits)


# ── sync (SYNC DECISIONS + REPLIES) ──────────────────────────────────────────
@app.api
def sync_preview():
    """Count + destination sites + destructive-delete count, so the page can show
    the Parkinson's-forgiving confirmation before pushing anything."""
    return core.sync_preview()


@app.api
def sync_run():
    """Apply + positively verify every queued decision/reply. The page confirms
    first (via sync_preview); this performs the push."""
    return core.sync_run()


# ── export / import (EXPORT… / IMPORT…) ──────────────────────────────────────
# TODO(port): tkinter used filedialog.askdirectory for a native folder picker.
# snap_blink is stdlib-only with no native picker bridge, so the web UI collects
# the folder PATH in a text field and passes it here. Same action, typed target.
@app.api
def export_session(dest_dir):
    return core.export_session(dest_dir)


@app.api
def import_session(src_dir):
    return core.import_session(src_dir)


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
