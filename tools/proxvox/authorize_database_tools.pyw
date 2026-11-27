"""Authorize the reviewed SS-1615 PROX VOX host upgrade and a read-only rehearsal.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""
import ctypes
import hashlib
import os
from pathlib import Path
import queue
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
OLD_WRITER = "f93a0e380cd13f68d6b526274401a996b9938ad6345a298f2d0ea9488b9b718d"
PACKAGE = (
    ("proxvox-pymysql.whl", "proxvox-pymysql.whl",
     "e169bed906168fffd0088925f45b3df024c2775c6fd128da0df773d47c458840", "-"),
    ("proxvox_database.py", "proxvox_database.py",
     "e04c639235c578cdd3767d21bb20b4208b865bc7f062fd438c84f204fd8702ff", "-"),
    ("proxvox_writer.py", "proxvox-writer",
     "aedb64d94a21caaa09668685d639c87db3200d9d18e0778cbd53d09f1d485f1d", OLD_WRITER),
)
SITE_ROOT = "/var/www/sites/baddaywithacamera.ca"


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


def upgrade(user, password, otp, publish):
    audit = AuditBuffer(str(ROOT.parent / "audit-buffer.jsonl"))
    reader = RootReadBroker(PVE_HOST, "proxvox-root-ro", audit)
    writer = UpgradeWriter(PVE_HOST, audit, str(Path.home() / ".ssh" / "snapsmack-proxvox-writer-ed25519"))
    client = None
    phase = "preflight"
    try:
        if prepare(reader):
            publish("The host certificate update is ready for review. Wait for installation before entering credentials.")
            return
        if user not in writer.scope().get("owner_users", []):
            publish("The installed writer does not accept this account. Wait for confirmation.")
            return
        target = ContainerTarget("ct102", "192.168.1.12", "proxvox-ro", "ct102_ssh", (SITE_ROOT,))
        site = ContainerReadBroker([target], DatabaseTarget("192.168.1.20", "proxvox_ro"), audit)
        page = site.select("ct102", "bad", "SELECT id, content FROM snap_pages WHERE slug=%s", ("the-artist",))
        if len(page["rows"]) != 1 or page["rows"][0][0] != 3:
            raise RuntimeError("Unexpected live page identity.")
        key, value = page["rows"][0]
        plan = {"target": "102", "config_path": SITE_ROOT + "/core/db.php", "database": "bad",
                "table": "snap_pages", "key_column": "id", "key": key, "column": "content", "value": value}
        phase = "owner authorization"
        publish("Verifying the account password and authenticator code…")
        guid = machine_guid()
        client = WriteClient(f"https://{PVE_HOST}:8006", audit, guid, actual_guid=guid,
                             certificate_sha256=PVE_CERT, host_writer=writer)
        client.authorize(user, password, otp, timeout=25)
        password = otp = ""
        phase = "read-only database rehearsal"
        publish("Verifying the database connection from the site container…")
        session_user, ticket = writer.owner
        preview = writer.preview_db_update(session_user, ticket, plan)
        if len(preview.get("expected", "")) != 64:
            raise RuntimeError("The database preview did not confirm the expected row.")
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
        if client is not None:
            client.revoke("SS-1615 read-only preview complete")
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
    window.title("Authorize PROX VOX database tools")
    window.geometry("645x365")
    events = queue.SimpleQueue()
    panel = ttk.Frame(window, padding=24)
    panel.pack(fill="both", expand=True)
    ttk.Label(panel, text="PROX VOX database preview", font=("Segoe UI", 15, "bold")).pack(anchor="w", pady=(0, 10))
    ttk.Label(panel, text="This verifies the installed host files and checks the connection to The Artist.",
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
    running = False

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
                upgrade(account, secret, code, events.put)
            finally:
                events.put(None)

        threading.Thread(target=work, daemon=True).start()

    def poll():
        nonlocal running
        while not events.empty():
            item = events.get()
            if item == "ready":
                status.set("Ready for the owner to authorize.")
                button.state(["!disabled"])
                for entry in entries:
                    entry.state(["!disabled"])
                entries[0].focus_set()
            elif item is None:
                running = False
                button.state(["!disabled"])
                for entry in entries:
                    entry.state(["!disabled"])
            else:
                status.set(item)
        window.after(150, poll)

    def check_host():
        audit = AuditBuffer(str(ROOT.parent / "audit-buffer.jsonl"))
        reader = RootReadBroker(PVE_HOST, "proxvox-root-ro", audit)
        try:
            pending = prepare(reader)
        except Exception:
            events.put("Could not verify the installed host files. Wait for confirmation.")
            return
        events.put("The host certificate update must be installed before you enter credentials." if pending else "ready")

    button = ttk.Button(panel, text="Authorize and preview", command=start, state="disabled")
    button.pack(anchor="w")
    ttk.Label(panel, textvariable=status, wraplength=585).pack(anchor="w", pady=12)
    window.protocol("WM_DELETE_WINDOW", lambda: status.set("Please wait for the authorized operation to finish.")
                    if running else window.destroy())
    threading.Thread(target=check_host, daemon=True).start()
    poll()
    try:
        window.mainloop()
    finally:
        close_handle(handle)


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
