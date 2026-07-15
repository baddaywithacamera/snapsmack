#!/usr/bin/env python3
"""
blink-suyb — launch.py
Cross-platform entry point. Starts the localhost server and opens the UI in
Chrome (preferring app-window mode so it feels like a native app, not a tab).

Runs on Linux, macOS and Windows with only a Python 3 runtime. This is the file
a user double-clicks (or a packaged binary wraps).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import shutil
import subprocess
import sys
import threading
import time
import webbrowser

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "server"))

import blink_server  # noqa: E402


# ----------------------------------------------------------------------------
# Chrome / Chromium discovery, per OS. Falls back to the default browser.
# ----------------------------------------------------------------------------

def _chrome_candidates():
    if sys.platform.startswith("darwin"):
        return [
            "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
            "/Applications/Chromium.app/Contents/MacOS/Chromium",
            "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
        ]
    if os.name == "nt":
        pf = os.environ.get("ProgramFiles", r"C:\Program Files")
        pfx86 = os.environ.get("ProgramFiles(x86)", r"C:\Program Files (x86)")
        local = os.environ.get("LOCALAPPDATA", "")
        return [
            os.path.join(pf, "Google", "Chrome", "Application", "chrome.exe"),
            os.path.join(pfx86, "Google", "Chrome", "Application", "chrome.exe"),
            os.path.join(local, "Google", "Chrome", "Application", "chrome.exe"),
            os.path.join(pf, "Microsoft", "Edge", "Application", "msedge.exe"),
        ]
    # Linux
    names = ["google-chrome", "google-chrome-stable", "chromium", "chromium-browser", "microsoft-edge"]
    found = [shutil.which(n) for n in names]
    return [p for p in found if p]


def find_chrome():
    for c in _chrome_candidates():
        if c and os.path.exists(c):
            return c
    # which() already resolved Linux names; try them directly too
    for n in ("google-chrome", "chromium", "chromium-browser"):
        p = shutil.which(n)
        if p:
            return p
    return None


def open_ui(url):
    chrome = find_chrome()
    if chrome:
        # --app gives a clean chromeless window; separate profile dir keeps it tidy.
        data_dir = os.path.join(os.path.expanduser("~"), ".blink-suyb", "chrome-profile")
        os.makedirs(data_dir, exist_ok=True)
        try:
            subprocess.Popen([
                chrome,
                f"--app={url}",
                f"--user-data-dir={data_dir}",
                "--no-first-run",
                "--no-default-browser-check",
            ])
            return "chrome-app"
        except OSError:
            pass
    # Fallback: whatever the OS default browser is.
    webbrowser.open(url)
    return "default-browser"


def main():
    port = int(os.environ.get("BLINK_PORT", "0"))  # 0 = OS picks a free port
    done = threading.Event()
    holder = {}

    def ready(actual_port, token):
        holder["port"] = actual_port
        holder["token"] = token
        done.set()

    t = threading.Thread(
        target=blink_server.serve,
        kwargs={"port": port, "ready": ready},
        daemon=True,
    )
    t.start()
    done.wait(timeout=10)

    if "port" not in holder:
        print("blink-suyb: server failed to start", file=sys.stderr)
        sys.exit(1)

    url = f"http://127.0.0.1:{holder['port']}/token#{holder['token']}"
    mode = open_ui(url)
    print(f"blink-suyb running at http://127.0.0.1:{holder['port']}/  (UI: {mode})")
    print("Close this window (or Ctrl-C) to stop the server.")
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print("\nblink-suyb stopped.")


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
