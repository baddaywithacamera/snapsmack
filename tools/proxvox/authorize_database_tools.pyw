"""Authorize the reviewed SS-1615 PROX VOX host upgrade and a read-only rehearsal.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""
import argparse
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
from proxvox import fleet_file_repair
from proxvox.fleet_release import VERSION as FLEET_VERSION, run_fleet_release
from proxvox.host_guard import machine_guid
from proxvox.launcher_signals import ACTIONS, LauncherSignals
from proxvox.root_read import RootReadBroker
from proxvox.write_client import WriteClient

ROOT = Path(os.environ.get("PROXVOX_STAGE_DIR", str(Path(__file__).resolve().parent)))
PVE_HOST = "192.168.1.10"
PVE_CERT = "debec00ae597023b601cca7e7316ba1ff0dca8cfe6446a478b47d34a9ba4a925"
HOST_PATH = "/usr/local/sbin/"
WINDOW_TITLE = "Authorize PROX VOX"
OLD_WRITER = "aedb64d94a21caaa09668685d639c87db3200d9d18e0778cbd53d09f1d485f1d"
FIVE_HOUR_WRITER = "18aa8c284eb9bbf5e64c6fa59323929f00bd3f7248ef107949e967e319e0712b"
FLEET_WRITER = "a0b05f03a0b71afc2dd97341dd86ab93ec708e069d2d77837dd7f8847b533ae4"
PACKAGE = (
    ("proxvox-pymysql.whl", "proxvox-pymysql.whl",
     "e169bed906168fffd0088925f45b3df024c2775c6fd128da0df773d47c458840", "-"),
    ("proxvox_database.py", "proxvox_database.py",
     "e04c639235c578cdd3767d21bb20b4208b865bc7f062fd438c84f204fd8702ff", "-"),
    ("proxvox_writer.py", "proxvox-writer",
     FIVE_HOUR_WRITER, OLD_WRITER),
)
SITE_ROOT = "/var/www/sites/baddaywithacamera.ca"
PUBLICATION_ROOT = "/var/www/snapsmack.ca/"
PUBLICATION_PAGES = ("wotcha.php", "tool-snap-slapper.php", "faq-tools.php",
                     "includes/header.php", "index.php")


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
        if remote == "proxvox-writer" and live == FLEET_WRITER:
            continue
        if live not in (old, wanted):
            raise RuntimeError("The installed host files differ from the reviewed pins.")
        if live != wanted:
            pending.append((path, wanted, live, data))
    return pending


def fleet_writer_package():
    path = ROOT / "proxvox-fleet-writer.py"
    if not path.is_file():
        return None
    content = path.read_bytes()
    if digest(content) != FLEET_WRITER:
        raise RuntimeError("The PROX VOX fleet update files do not match the reviewed package.")
    return content


def fleet_sites(reader, writer, client):
    content = fleet_writer_package()
    if content is None:
        raise RuntimeError("The reviewed fleet update files are unavailable.")
    path = HOST_PATH + "proxvox-writer"
    live = current_hash(reader, path)
    if live == FIVE_HOUR_WRITER:
        grant = client.mint_file_grant(target="host", path=path, sha256=FLEET_WRITER)
        try:
            writer.put_file("host", path, content, grant, live)
        except Exception:
            if current_hash(reader, path) != FLEET_WRITER:
                raise
    if current_hash(reader, path) != FLEET_WRITER:
        raise RuntimeError("The host could not verify its fleet update support.")
    scope = writer.scope().get("signed_release", {})
    sites = scope.get("sites")
    if (scope.get("version") != FLEET_VERSION or not isinstance(sites, list)
            or len(sites) != 23 or any(not isinstance(site, str) for site in sites)
            or len(set(sites)) != len(sites)):
        raise RuntimeError("The host could not verify the approved 23-site update scope.")
    return sites


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


def preview_artist(client):
    audit = client.audit
    target = ContainerTarget("ct102", "192.168.1.12", "proxvox-ro", "ct102_ssh", (SITE_ROOT,))
    site = ContainerReadBroker([target], DatabaseTarget("192.168.1.20", "proxvox_ro"), audit)
    page = site.select("ct102", "bad", "SELECT id, content FROM snap_pages WHERE slug=%s", ("the-artist",))
    if len(page["rows"]) != 1 or page["rows"][0][0] != 3:
        raise RuntimeError("Unexpected live page identity.")
    key, value = page["rows"][0]
    plan = {"target": "102", "config_path": SITE_ROOT + "/core/db.php", "database": "bad",
            "table": "snap_pages", "key_column": "id", "key": key, "column": "content", "value": value}
    preview = client.preview_db_update(plan)
    if not re.fullmatch(r"[0-9a-f]{64}", preview.get("expected", "")):
        raise RuntimeError("The database preview did not confirm the expected row.")


def authorize(user, password, otp, publish, retain):
    audit = AuditBuffer(str(ROOT.parent / "audit-buffer.jsonl"))
    reader = RootReadBroker(PVE_HOST, "proxvox-root-ro", audit)
    writer = ScopedFileWriter(PVE_HOST, audit, str(Path.home() / ".ssh" / "snapsmack-proxvox-writer-ed25519"))
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
        retain(client, reader, writer)
        retained = True
        publish("Session authorized. Choose an action below.")
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


def activate_existing_form():
    from ctypes import wintypes

    user32 = ctypes.WinDLL("user32", use_last_error=True)
    find_window = user32.FindWindowW
    find_window.argtypes = (wintypes.LPCWSTR, wintypes.LPCWSTR)
    find_window.restype = wintypes.HWND
    window = find_window("TkTopLevel", WINDOW_TITLE)
    if window:
        show_window = user32.ShowWindow
        show_window.argtypes = (wintypes.HWND, ctypes.c_int)
        show_window.restype = wintypes.BOOL
        show_window(window, 9)  # SW_RESTORE
        foreground = user32.SetForegroundWindow
        foreground.argtypes = (wintypes.HWND,)
        foreground.restype = wintypes.BOOL
        foreground(window)


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("--select", choices=ACTIONS)
    action = parser.parse_args(argv).select
    signals = LauncherSignals()
    try:
        if action:
            signals.select(action)
        if signals.already_open:
            activate_existing_form()
            return
        run_form(signals)
    finally:
        signals.close()


def run_form(signals):
    window = tk.Tk()
    publication_sha = os.environ.get("PROXVOX_PUBLICATION_SHA256")
    window.title(WINDOW_TITLE)
    window.geometry("645x470")
    events = queue.SimpleQueue()
    panel = ttk.Frame(window, padding=24)
    panel.pack(fill="both", expand=True)
    ttk.Label(panel, text="PROX VOX", font=("Segoe UI", 15, "bold")).pack(anchor="w", pady=(0, 10))
    ttk.Label(panel, text="Authorize once, then choose the actions you want to run.",
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
    host_ready = False
    publication_ready = False
    fleet_ready = False
    selected_action = None
    running = False
    publication_complete = False
    fleet_complete = False
    repair_complete = False
    writer_complete = False
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
                authorize(account, secret, code, events.put,
                          retain=lambda *context: events.put(("session", context)))
            finally:
                events.put(None)

        threading.Thread(target=work, daemon=True).start()

    def poll():
        nonlocal host_ready, publication_ready, fleet_ready, running
        nonlocal publication_complete, fleet_complete, repair_complete, writer_complete, selected_action, session
        while not events.empty():
            item = events.get()
            if isinstance(item, tuple) and item[0] == "ready":
                host_ready = True
                publication_ready = item[1]
                fleet_ready = item[2]
                status.set("Ready for the owner to authorize.")
                entries[0].state(["!disabled"])
                entries[0].focus_set()
            elif isinstance(item, tuple) and item[0] == "session":
                session = item[1]
            elif item == "publication complete":
                publication_complete = True
            elif item == "fleet complete":
                fleet_complete = True
            elif item == "repair complete":
                repair_complete = True
            elif item == "writer complete":
                writer_complete = True
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
            else:
                status.set(item)
        seconds = session[0].seconds_remaining() if session else 0
        if session and seconds == 0:
            session = None
            remaining.set("The PROX VOX session expired.")
        elif seconds:
            hours, remainder = divmod(seconds, 3600)
            minutes, seconds = divmod(remainder, 60)
            remaining.set(f"Session remaining {hours:02}:{minutes:02}:{seconds:02}")
        authorization_state = ["!disabled"] if host_ready and not running and not session else ["disabled"]
        button.state(authorization_state)
        for entry in entries:
            entry.state(authorization_state)
        state = ["!disabled"] if session and not running else ["disabled"]
        preview_action.state(state)
        publish_action.state(state if publication_ready and not publication_complete else ["disabled"])
        fleet_action.state(state if fleet_ready and not fleet_complete else ["disabled"])
        repair_action.state(state if fleet_ready and not repair_complete else ["disabled"])
        writer_action.state(state if fleet_ready and not writer_complete else ["disabled"])
        end_action.state(state)
        for action in signals.selections():
            selected_action = action
        if selected_action == "publish" and host_ready and not publication_ready:
            status.set("There is no verified publication available in this PROX VOX window.")
            selected_action = None
        if selected_action in ("signed-release", "restore-updater") and host_ready and not fleet_ready:
            status.set("There is no verified fleet update available in this PROX VOX window.")
            selected_action = None
        if selected_action and session and not running:
            action_button = {"publish": publish_action, "preview": preview_action,
                             "signed-release": fleet_action, "restore-updater": repair_action}[selected_action]
            if not action_button.instate(["disabled"]):
                action_button.focus_set()
            selected_action = None
        window.after(150, poll)

    def use_session(end=False, close=False, publish_site=False, update_fleet=False,
                    repair_spokes=False, install_writer=False):
        nonlocal running
        if running:
            return
        if session is None:
            if close:
                window.destroy()
            return
        if publish_site and (not publication_ready or publication_complete):
            return
        if update_fleet and (not fleet_ready or fleet_complete):
            return
        if repair_spokes and (not fleet_ready or repair_complete):
            return
        if install_writer and (not fleet_ready or writer_complete):
            return
        client, reader, writer = session
        running = True

        def work():
            try:
                if end:
                    client.revoke("Owner ended PROX VOX session")
                    events.put(("end", True, close))
                elif publish_site:
                    plan = publication_plan(publication_sha, reader)
                    count = publish_pages(reader, writer, client, plan, events.put)
                    events.put(f"Published and verified {count} files.")
                    events.put("publication complete")
                elif update_fleet:
                    events.put("Checking host update support…")
                    sites = fleet_sites(reader, writer, client)
                    events.put("writer complete")
                    count = run_fleet_release(client, sites, events.put)
                    events.put(f"Verified {count} sites on {FLEET_VERSION}. The session is still open.")
                    events.put("fleet complete")
                elif repair_spokes or install_writer:
                    events.put("Checking the 24-hour host writer…")
                    fleet_sites(reader, writer, client)
                    events.put("writer complete")
                    if repair_spokes:
                        events.put("Checking the 23 installed updater files…")
                        plan = fleet_file_repair.preflight(reader, fleet_file_repair.package())
                        count = fleet_file_repair.restore(client, reader, writer, plan, events.put)
                        events.put(f"Verified {count} files. Run Update from the hub.")
                        events.put("repair complete")
                    else:
                        events.put("24-hour sessions are enabled for future authorizations.")
                else:
                    preview_artist(client)
                    events.put("The Artist database preview passed. No page content was written.")
            except Exception as error:
                if end:
                    events.put(("end", False, close))
                elif publish_site:
                    events.put("Publication stopped. Check the reviewed manifest and live file hashes.")
                elif update_fleet:
                    events.put(f"Fleet update stopped. {error}")
                elif repair_spokes or install_writer:
                    events.put(f"PROX VOX stopped. {error}")
                else:
                    events.put("The Artist preview did not complete.")
            finally:
                if not end:
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
        except Exception:
            events.put("Could not verify the installed host files. Wait for confirmation.")
            return
        publish_ready = False
        if publication_sha:
            try:
                publication_plan(publication_sha, reader)
            except Exception:
                pass
            else:
                publish_ready = True
        try:
            update_ready = fleet_writer_package() is not None
        except RuntimeError:
            update_ready = False
        events.put(("ready", publish_ready, update_ready))

    actions = ttk.Frame(panel)
    actions.pack(anchor="w")
    button = ttk.Button(actions, text="Authorize", command=start, state="disabled")
    button.pack(side="left")
    preview_action = ttk.Button(actions, text="Preview The Artist", command=use_session, state="disabled")
    preview_action.pack(side="left", padx=8)
    publish_action = ttk.Button(actions, text="Publish SnapSmack.ca",
                                command=lambda: use_session(publish_site=True), state="disabled")
    if publication_sha:
        publish_action.pack(side="left", padx=(0, 8))
    end_action = ttk.Button(actions, text="End session", command=lambda: use_session(end=True), state="disabled")
    end_action.pack(side="left")
    fleet_action = ttk.Button(panel, text=f"Resume 23 site updates to {FLEET_VERSION}",
                              command=lambda: use_session(update_fleet=True), state="disabled")
    repair_action = ttk.Button(panel, text="Restore updater on 23 sites",
                               command=lambda: use_session(repair_spokes=True), state="disabled")
    writer_action = ttk.Button(panel, text="Enable 24-hour sessions",
                               command=lambda: use_session(install_writer=True), state="disabled")
    if (ROOT / "proxvox-fleet-writer.py").is_file():
        fleet_action.pack(anchor="w", pady=(8, 0))
        repair_action.pack(anchor="w", pady=(5, 0))
        writer_action.pack(anchor="w", pady=(5, 0))
    ttk.Label(panel, textvariable=remaining).pack(anchor="w", pady=(10, 0))
    ttk.Label(panel, textvariable=status, wraplength=585).pack(anchor="w", pady=12)
    window.protocol("WM_DELETE_WINDOW", lambda: status.set("Please wait for the authorized operation to finish.")
                    if running else use_session(end=True, close=True))
    threading.Thread(target=check_host, daemon=True).start()
    poll()
    window.mainloop()


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
