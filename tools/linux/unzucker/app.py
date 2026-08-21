#!/usr/bin/env python3
"""
UNZUCKER — Linux Chrome/Blink port.

The window is HTML/CSS/JS drawn by Chromium; the WORK is the original Unzucker
Python (parse an Instagram export, connect to the SnapSmack API, upload images,
create posts, manage trigram groups, resumable jobs). All of that logic lives in
../unzucker_core.py (factored out of the Windows tkinter main.py with no tkinter
left in it) and in the tool's existing modules (config, ig_parser, job_state,
poster, exif_writer).

This file only bridges the two: it registers each Session method as an API the
page can call with blink.call('<name>', ...args), then opens the window.
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                       # tools/unzucker/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")       # tools/_shared/
for p in (SHARED, TOOL_ROOT):
    sys.path.insert(0, os.path.abspath(p))

import snap_blink
import unzucker_core

app = snap_blink.App(
    tool="unzucker",
    title="UNZUCKER",
    web_dir=os.path.join(HERE, "web"),
)

# One session for the life of the window, exactly like the single tkinter App.
SESSION = unzucker_core.Session()

# Expose every Session handler. Positional args arrive in the order the page
# passed them; returning a dict sends JSON; raising sends an error the page logs.
for _name in (
    "load_state", "save_config", "connect",
    "parse_export", "begin_job", "detail", "validate",
    "toggle_exclude", "trigram_select", "lock_trigram", "remove_trigram",
    "migration_preview", "start_migration", "poll", "unload_job",
    "thumb", "preview",
):
    app.register(_name, getattr(SESSION, _name))


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
