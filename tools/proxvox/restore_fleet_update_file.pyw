"""Restore the 1007D updater helper on the 23 remaining spokes.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python SNAPSMACK EOF marker.
"""

from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
from pathlib import Path
from queue import SimpleQueue
import re
import runpy
import subprocess
from threading import Thread
import tkinter as tk
from tkinter import ttk

from server.proxvox_writer import FLEET_SITES


OWNER = "proxvox@pve"
BASELINE = "521cf6ecab3aafb960e2c1d763531d780c7c31c63977cdec691061211a1759a9"
VERSION = re.compile(rb"(?m)^define\([ \t]*'SNAPSMACK_VERSION_SHORT'[ \t]*,[ \t]*'0\.7\.1007D'[ \t]*\);[^\r\n]*\r?\n")
HELPER = b"function snap_version_with_epoch"
TARGET = "core/constants.php"
SOURCE = Path(__file__).resolve().parent


def digest(data):
    return hashlib.sha256(data).hexdigest()


def package():
    candidate = subprocess.check_output(
        ["git", "-C", str(SOURCE), "show", "v0.7.1007D:" + TARGET],
        creationflags=subprocess.CREATE_NO_WINDOW,
    )
    if digest(candidate) != BASELINE or not VERSION.search(candidate):
        raise RuntimeError("The replacement file does not match the 0.7.1007D release.")
    match = re.search(rb"(?m)^function snap_version_with_epoch\([^\n]+\n(?:    [^\n]+\n)+\}", candidate)
    if not match:
        raise RuntimeError("The release helper is missing.")
    return match[0]


def preflight(reader, helper):
    def inspect(site):
        directory = f"/var/www/sites/{site}/"
        path = directory + TARGET
        current = reader.read_file(path, vmid=102)
        manifest = json.loads(reader.read_file(directory + "smackback-manifest.json", vmid=102))
        baseline = manifest.get("files", {}).get(TARGET, {}).get("hash")
        version = VERSION.search(current)
        if baseline != BASELINE or not version or b"function snap_version_compare" not in current:
            raise RuntimeError(f"{site} is outside the approved 0.7.1007D restore.")
        before = digest(current)
        if helper in current:
            return site, path, before, current
        if HELPER in current:
            raise RuntimeError(f"{site} has a different updater repair. Inspect it first.")
        end = version.end()
        candidate = current[:end] + b"\n" + helper + b"\n\n" + current[end:]
        check = subprocess.run([r"C:\php\php.exe", "-l"], input=candidate, capture_output=True,
                               creationflags=subprocess.CREATE_NO_WINDOW)
        if check.returncode:
            raise RuntimeError(f"{site} did not pass the PHP syntax check.")
        return site, path, before, candidate

    with ThreadPoolExecutor(max_workers=4) as pool:
        return list(pool.map(inspect, FLEET_SITES))


def restore(client, reader, writer, plan, status):
    verified = 0
    for site, path, before, candidate in plan:
        status(f"Checking {site} ({verified + 1} of {len(plan)})…")
        after = digest(candidate)
        if before != after:
            grant = client.mint_file_grant(target="102", path=path, sha256=after)
            receipt = writer.put_file("102", path, candidate, grant, before)
            if not receipt.get("verified") or not receipt.get("backup_path"):
                raise RuntimeError(f"{site} did not return a verified write and backup.")
        if digest(reader.read_file(path, vmid=102)) != after:
            raise RuntimeError(f"{site} did not match the release file after verification.")
        client.audit.record("fleet_updater_file_verified", {"site": site, "sha256": after})
        verified += 1
        status(f"Verified {verified} of {len(plan)}. Last site {site}.")
    return verified


def run_form():
    base = runpy.run_path(str(SOURCE / "authorize_database_tools.pyw"))
    audit = base["AuditBuffer"](str(base["ROOT"].parent / "audit-buffer.jsonl"))
    reader = base["RootReadBroker"](base["PVE_HOST"], "proxvox-root-ro", audit)
    events = SimpleQueue()
    ready = None
    active = False
    window = tk.Tk()
    window.title("PROX VOX — Restore 23 updater files")
    body = ttk.Frame(window, padding=20)
    body.pack(fill="both", expand=True)
    ttk.Label(body, text="Restore the updater on 23 sites", font=("Segoe UI", 15, "bold")).pack(anchor="w")
    ttk.Label(body, text="Restore the missing updater function on each spoke, then run the updates from the hub.",
              wraplength=510).pack(anchor="w", pady=8)
    fields = ttk.Frame(body)
    fields.pack(fill="x")
    password, otp = tk.StringVar(), tk.StringVar()
    entries = []
    for row, (label, value) in enumerate((("proxvox@pve password", password), ("Current six-digit code", otp))):
        ttk.Label(fields, text=label).grid(row=row, column=0, sticky="w", padx=(0, 12), pady=4)
        entry = ttk.Entry(fields, textvariable=value, show="•", state="disabled")
        entry.grid(row=row, column=1, sticky="ew", pady=4)
        entries.append(entry)
    fields.columnconfigure(1, weight=1)
    message = tk.StringVar(value="Checking the installed release files…")

    def apply():
        nonlocal active, ready
        if active or ready is None:
            return
        secret, code = password.get(), otp.get().strip()
        if not secret or len(code) != 6 or not code.isascii() or not code.isdigit():
            message.set("Enter the account password and a current six-digit code.")
            return
        plan = ready
        ready = None
        active = True
        password.set("")
        otp.set("")
        button.state(["disabled"])
        for entry in entries:
            entry.state(["disabled"])

        def work():
            context = []
            try:
                base["authorize"](OWNER, secret, code, events.put,
                                  retain=lambda *session: context.extend(session))
                if context:
                    count = restore(*context, plan, events.put)
                    events.put(f"Verified all {count} files. Run Update from the hub.")
                else:
                    events.put(("ready", plan))
            except Exception as error:
                events.put(f"Stopped. {error}")
            finally:
                if context:
                    try:
                        context[0].revoke("23-site updater file restore finished")
                    except Exception:
                        events.put("PROX VOX could not confirm session closure.")
                events.put(None)

        Thread(target=work, daemon=True).start()

    button = ttk.Button(body, text="Authorize and restore", command=apply, state="disabled")
    button.pack(anchor="w", pady=8)
    ttk.Label(body, textvariable=message, wraplength=510).pack(anchor="w", pady=8)

    def verify():
        try:
            plan = preflight(reader, package())
            audit.record("fleet_updater_file_preflight", {"sites": len(plan), "ok": True})
            events.put(("ready", plan))
        except Exception as error:
            audit.record("fleet_updater_file_preflight", {"sites": len(FLEET_SITES), "ok": False})
            events.put(f"Preflight stopped. {error}")

    def poll():
        nonlocal ready, active
        while not events.empty():
            event = events.get()
            if isinstance(event, tuple):
                ready = event[1]
                if not active:
                    message.set("All 23 spokes verified. Ready for one authorization.")
                for entry in entries:
                    entry.state(["!disabled"])
                button.state(["!disabled"])
                entries[0].focus_set()
            elif event is None:
                active = False
            else:
                message.set(event)
        window.after(150, poll)

    window.protocol("WM_DELETE_WINDOW", lambda: None if active else window.destroy())
    Thread(target=verify, daemon=True).start()
    poll()
    window.mainloop()


if __name__ == "__main__":
    run_form()


# ===== SNAPSMACK EOF =====
