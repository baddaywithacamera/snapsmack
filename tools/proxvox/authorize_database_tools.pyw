"""Authorize the reviewed SS-1615 PROX VOX host upgrade and a read-only rehearsal.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""
import ctypes
import hashlib
import json
import os
from pathlib import Path
import queue
import re
import threading
import tkinter as tk
import urllib.error
from tkinter import ttk

from proxvox.audit import AuditBuffer
from proxvox.container_read import ContainerReadBroker, ContainerTarget, DatabaseTarget
from proxvox.file_write_client import ScopedFileWriter
from proxvox.host_guard import machine_guid
from proxvox.root_read import RootReadBroker
from proxvox.write_client import WriteClient

ROOT = Path(os.environ.get("PROXVOX_STAGE_DIR", str(Path(__file__).resolve().parent)))
PVE_HOST = "192.168.1.10"
PVE_CERT = "debec00ae597023b601cca7e7316ba1ff0dca8cfe6446a478b47d34a9ba4a925"
HOST_PATH = "/usr/local/sbin/"
OLD_WRITER = "aedb64d94a21caaa09668685d639c87db3200d9d18e0778cbd53d09f1d485f1d"
PACKAGE = (
    ("proxvox-pymysql.whl", "proxvox-pymysql.whl",
     "e169bed906168fffd0088925f45b3df024c2775c6fd128da0df773d47c458840", "-"),
    ("proxvox_database.py", "proxvox_database.py",
     "e04c639235c578cdd3767d21bb20b4208b865bc7f062fd438c84f204fd8702ff", "-"),
    ("proxvox_writer.py", "proxvox-writer",
     "18aa8c284eb9bbf5e64c6fa59323929f00bd3f7248ef107949e967e319e0712b", OLD_WRITER),
)
SITE_ROOT = "/var/www/sites/baddaywithacamera.ca"
PUBLICATION_ROOT = "/var/www/snapsmack.ca/"
PUBLICATION_PAGES = ("wotcha.php", "tool-snap-slapper.php", "faq-tools.php",
                     "includes/header.php", "index.php")


class UpgradeWriter(ScopedFileWriter):
    owner = None

    def authorize_session(self, user, challenge, code):
        result = super().authorize_session(user, challenge, code)
        self.owner = user, result["ticket"]
        return result


def digest(data):
    return hashlib.sha256(data).hexdigest()


def current_hash(reader, path):
    try:
        value = reader.read_file(path)
    except Exception as error:
        if "No such file or directory" in str(error):
            return "-"
        raise
    if isinstance(value, dict):
        return value.get("sha256")
    return digest(value)


def prepare(reader):
    pending = []
    for local, remote, wanted, old in PACKAGE:
        data = (ROOT / local).read_bytes()
        if digest(data) != wanted:
            raise RuntimeError("The staged package does not match the reviewed hashes.")
        path = HOST_PATH + remote
        live = current_hash(reader, path)
        if live not in (old, wanted):
            raise RuntimeError("The installed host files differ from the reviewed pins.")
        if live != wanted:
            pending.append((path, wanted, live, data))
    return pending


def publication_plan(manifest_sha256, reader):
    manifest = (ROOT / "publications-homepage-preflight.json").read_bytes()
    if digest(manifest) != manifest_sha256:
        raise RuntimeError("Publication manifest changed after review.")
    canonical = Path(os.environ["PROXVOX_SITE_CHECKOUT"]).resolve(strict=True)
    planned = []
    seen = set()
    for item in json.loads(manifest):
        path = item["path"]
        name = path.removeprefix(PUBLICATION_ROOT)
        if (path != PUBLICATION_ROOT + name or name in seen
                or (name not in PUBLICATION_PAGES
                    and not re.fullmatch(r"img/[\w-]+(?:\.[\w-]+)+", name))):
            raise RuntimeError("Unexpected publication path.")
        seen.add(name)
        local = (canonical / name).resolve(strict=True)
        if not local.is_relative_to(canonical):
            raise RuntimeError("Local publication file is outside the checkout.")
        content = local.read_bytes()
        wanted = item["new_sha256"]
        expected = item["expected_sha256"]
        if digest(content) != wanted or len(content) > 8 * 1024 * 1024:
            raise RuntimeError(f"Canonical file changed or exceeds the writer limit: {name}")
        try:
            live = digest(reader.read_file(path, vmid=101))
        except Exception as error:
            if "No such file or directory" not in str(error):
                raise
            live = "-"
        if live != expected:
            raise RuntimeError(f"The live file changed since review: {name}")
        planned.append((path, content, expected, wanted))
    page_order = tuple(path.removeprefix(PUBLICATION_ROOT)
                       for path, *_ in planned if not path.startswith(PUBLICATION_ROOT + "img/"))
    if not page_order or page_order != tuple(name for name in PUBLICATION_PAGES if name in seen):
        raise RuntimeError("Publication manifest is empty or out of order.")
    if any(path.startswith(PUBLICATION_ROOT + "img/") for path, *_ in planned[-len(page_order):]):
        raise RuntimeError("Publish image dependencies before their pages.")
    return planned


def publish_pages(reader, writer, client, planned, publish):
    completed = 0
    for path, content, expected, wanted in planned:
        name = path.removeprefix(PUBLICATION_ROOT)
        publish(f"Publishing {name} ({completed + 1} of {len(planned)})…")
        grant = client.mint_file_grant(target="101", path=path, sha256=wanted)
        result = writer.put_file("101", path, content, grant, expected)
        if (result.get("verified") is not True or result.get("sha256_after") != wanted
                or digest(reader.read_file(path, vmid=101)) != wanted):
            raise RuntimeError(f"Readback failed for {name}.")
        completed += 1
        publish(f"Verified {name} ({completed} of {len(planned)}).")
    return completed


def preview_artist(audit, writer):
    target = ContainerTarget("ct102", "192.168.1.12", "proxvox-ro", "ct102_ssh", (SITE_ROOT,))
    site = ContainerReadBroker([target], DatabaseTarget("192.168.1.20", "proxvox_ro"), audit)
    page = site.select("ct102", "bad", "SELECT id, content FROM snap_pages WHERE slug=%s", ("the-artist",))
    if len(page["rows"]) != 1 or page["rows"][0][0] != 3:
        raise RuntimeError("Unexpected live page identity.")
    key, value = page["rows"][0]
    plan = {"target": "102", "config_path": SITE_ROOT + "/core/db.php", "database": "bad",
            "table": "snap_pages", "key_column": "id", "key": key, "column": "content", "value": value}
    user, ticket = writer.owner
    preview = writer.preview_db_update(user, ticket, plan)
    if not re.fullmatch(r"[0-9a-f]{64}", preview.get("expected", "")):
        raise RuntimeError("The database preview did not confirm the expected row.")


def upgrade(user, password, otp, publish, planned=None, retain=None):
    audit = AuditBuffer(str(ROOT.parent / "audit-buffer.jsonl"))
    reader = RootReadBroker(PVE_HOST, "proxvox-root-ro", audit)
    writer = UpgradeWriter(PVE_HOST, audit, str(Path.home() / ".ssh" / "snapsmack-proxvox-writer-ed25519"))
    client = None
    retained = False
    phase = "preflight"
    try:
        if prepare(reader):
            publish("The five-hour host update must be installed before this form can authorize a session.")
            return
        if user not in writer.scope().get("owner_users", []):
            publish("The installed writer does not accept this account. Wait for confirmation.")
            return
        phase = "owner authorization"
        publish("Verifying the account password and authenticator code…")
        guid = machine_guid()
        client = WriteClient(f"https://{PVE_HOST}:8006", audit, guid, actual_guid=guid,
                             certificate_sha256=PVE_CERT, host_writer=writer)
        client.authorize(user, password, otp, timeout=25)
        password = otp = ""
        if retain is not None:
            retain(client, writer)
            retained = True
        if planned is not None:
            phase = "SnapSmack.ca publishing"
            count = publish_pages(reader, writer, client, planned, publish)
            publish(f"Published and verified {count} files.")
            return
        phase = "read-only database rehearsal"
        publish("Verifying the database connection from the site container…")
        preview_artist(audit, writer)
        publish("The Artist database preview passed. No page content was written.")
    except Exception as error:
        reason = str(error)
        if phase == "owner authorization" and reason == "the host could not complete Proxmox TOTP verification":
            publish("The host could not reach Proxmox securely. Your code was not checked. Do not retry.")
        elif phase == "owner authorization" and "TFA challenge" in reason:
            publish("Proxmox did not return an authenticator challenge for this account.")
        elif phase == "owner authorization" and isinstance(error, urllib.error.HTTPError) and error.code == 401:
            publish("Proxmox rejected the account login before checking the code.")
        elif phase == "owner authorization" and reason == "Proxmox rejected the TOTP challenge":
            publish("Proxmox rejected the authenticator challenge. Check the host result before retrying.")
        else:
            publish(f"Stopped during {phase} ({type(error).__name__}). Check the host result before retrying.")
    finally:
        password = otp = ""
        if client is not None and not retained:
            try:
                client.revoke("PROX VOX form finished")
            except Exception:
                publish("Could not confirm session revocation. Check the host audit.")
        if not retained:
            writer.owner = None


def main():
    from ctypes import wintypes
    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    create_mutex = kernel.CreateMutexW
    create_mutex.argtypes = (wintypes.LPVOID, wintypes.BOOL, wintypes.LPCWSTR)
    create_mutex.restype = wintypes.HANDLE
    handle = create_mutex(None, False, "Local\\SnapSmackProxVoxDatabasePreview")
    if not handle:
        raise ctypes.WinError(ctypes.get_last_error())
    already_open = ctypes.get_last_error() == 183
    close_handle = kernel.CloseHandle
    close_handle.argtypes = (wintypes.HANDLE,)
    if already_open:
        close_handle(handle)
        return
    window = tk.Tk()
    publication_sha = os.environ.get("PROXVOX_PUBLICATION_SHA256")
    window.title("Publish SnapSmack.ca through PROX VOX" if publication_sha
                 else "Authorize PROX VOX database tools")
    window.geometry("645x405")
    events = queue.SimpleQueue()
    panel = ttk.Frame(window, padding=24)
    panel.pack(fill="both", expand=True)
    purpose = "Publish SnapSmack.ca" if publication_sha else "PROX VOX database preview"
    ttk.Label(panel, text=purpose, font=("Segoe UI", 15, "bold")).pack(anchor="w", pady=(0, 10))
    description = ("This publishes the reviewed SnapSmack.ca pages and image dependencies."
                   if publication_sha else
                   "This verifies the installed host files and checks the connection to The Artist.")
    ttk.Label(panel, text=description,
              wraplength=585).pack(anchor="w")
    form = ttk.Frame(panel)
    form.pack(fill="x", pady=12)
    account = "proxvox@pve"
    ttk.Label(form, text="Proxmox account").grid(row=0, column=0, sticky="w", pady=5, padx=(0, 12))
    ttk.Label(form, text=account).grid(row=0, column=1, sticky="w")
    password = tk.StringVar()
    otp = tk.StringVar()
    entries = []
    for row, (label, variable) in enumerate((
        ("Password", password),
        ("Current authenticator code", otp),
    ), start=1):
        ttk.Label(form, text=label).grid(row=row, column=0, sticky="w", pady=5, padx=(0, 12))
        entry = ttk.Entry(form, textvariable=variable, show="*", state="disabled")
        entry.grid(row=row, column=1, sticky="ew")
        entries.append(entry)
    form.columnconfigure(1, weight=1)
    status = tk.StringVar(value="Checking the installed host files…")
    remaining = tk.StringVar(value="No active session")
    running = False
    planned = None
    session = None

    def start():
        nonlocal running
        if running or button.instate(["disabled"]):
            return
        code = otp.get().strip()
        secret = password.get()
        if not secret or len(code) != 6 or not code.isascii() or not code.isdigit():
            status.set("Enter the account password and a current six-digit code.")
            return
        password.set("")
        otp.set("")
        button.state(["disabled"])
        for entry in entries:
            entry.state(["disabled"])
        running = True

        def work():
            try:
                upgrade(account, secret, code, events.put, planned,
                        retain=lambda client, writer: events.put(("session", (client, writer))))
            finally:
                events.put(None)

        threading.Thread(target=work, daemon=True).start()

    def poll():
        nonlocal running, planned, session
        while not events.empty():
            item = events.get()
            if isinstance(item, tuple) and item[0] == "ready":
                planned = item[1]
                status.set("Ready for the owner to authorize.")
                button.state(["!disabled"])
                for entry in entries:
                    entry.state(["!disabled"])
                entries[0].focus_set()
            elif isinstance(item, tuple) and item[0] == "session":
                session = item[1]
            elif isinstance(item, tuple) and item[0] == "end":
                session = None
                remaining.set("No active session")
                status.set("PROX VOX session ended." if item[1] else "Could not confirm host revocation.")
                running = False
                if item[1] and item[2]:
                    window.destroy()
                    return
            elif item is None:
                running = False
                if session is None:
                    button.state(["!disabled"])
                    for entry in entries:
                        entry.state(["!disabled"])
            else:
                status.set(item)
        seconds = session[0].seconds_remaining() if session else 0
        if session and seconds == 0:
            session[1].owner = None
            session = None
            remaining.set("The PROX VOX session expired.")
        elif seconds:
            hours, remainder = divmod(seconds, 3600)
            minutes, seconds = divmod(remainder, 60)
            remaining.set(f"Session remaining {hours:02}:{minutes:02}:{seconds:02}")
        state = ["!disabled"] if session and not running else ["disabled"]
        preview_action.state(state)
        end_action.state(state)
        window.after(150, poll)

    def use_session(end=False, close=False):
        nonlocal running
        if running:
            return
        if session is None:
            if close:
                window.destroy()
            return
        client, writer = session
        running = True

        def work():
            try:
                if end:
                    client.revoke("Owner ended PROX VOX session")
                    events.put(("end", True, close))
                else:
                    preview_artist(client.audit, writer)
                    events.put("The Artist database preview passed. No page content was written.")
            except Exception:
                if end:
                    events.put(("end", False, close))
                else:
                    events.put("The Artist preview did not complete.")
            finally:
                if end:
                    writer.owner = None
                else:
                    events.put(None)

        threading.Thread(target=work, daemon=True).start()

    def check_host():
        audit = AuditBuffer(str(ROOT.parent / "audit-buffer.jsonl"))
        reader = RootReadBroker(PVE_HOST, "proxvox-root-ro", audit)
        try:
            pending = prepare(reader)
            if pending:
                events.put("The five-hour host update must be installed before you enter credentials.")
                return
            plan = publication_plan(publication_sha, reader) if publication_sha else None
        except Exception:
            events.put("Could not verify the installed host files. Wait for confirmation.")
            return
        events.put(("ready", plan))

    actions = ttk.Frame(panel)
    actions.pack(anchor="w")
    button = ttk.Button(actions, text="Authorize and publish" if publication_sha else "Authorize and preview",
                        command=start, state="disabled")
    button.pack(side="left")
    preview_action = ttk.Button(actions, text="Preview The Artist", command=use_session, state="disabled")
    preview_action.pack(side="left", padx=8)
    end_action = ttk.Button(actions, text="End session", command=lambda: use_session(end=True), state="disabled")
    end_action.pack(side="left")
    ttk.Label(panel, textvariable=remaining).pack(anchor="w", pady=(10, 0))
    ttk.Label(panel, textvariable=status, wraplength=585).pack(anchor="w", pady=12)
    window.protocol("WM_DELETE_WINDOW", lambda: status.set("Please wait for the authorized operation to finish.")
                    if running else use_session(end=True, close=True))
    threading.Thread(target=check_host, daemon=True).start()
    poll()
    try:
        window.mainloop()
    finally:
        close_handle(handle)


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
