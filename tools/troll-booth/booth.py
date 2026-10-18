# SNAPSMACK_EOF_HEADER
"""Native TROLL BOOTH lock and service window."""

from __future__ import annotations

import threading
import tkinter as tk
from pathlib import Path
from tkinter import messagebox, ttk

from troll_booth.auth import AuthError, generate_totp_secret, totp
from troll_booth.relay_client import RelayClient, RelayError
from troll_booth.service import BoothService


class BoothWindow:
    def __init__(self, root: tk.Tk):
        self.root = root
        self.tool_root = Path(__file__).resolve().parent
        self.service = BoothService(self.tool_root)
        self.thread = threading.Thread(target=self.service.serve, daemon=True)
        self.thread.start()
        self.pending_secret = generate_totp_secret()
        self.token = ""
        self.pair_actor = tk.StringVar(value="codex")
        self.pair_code = tk.StringVar(value="")
        self.relay_url = tk.StringVar(value="https://trollbooth.photoblogs.fyi")
        self.device_id = tk.StringVar(value="sean-workstation")
        self.device_token = tk.StringVar(value="")
        self.relay_status = tk.StringVar(value="Relay disconnected")
        self.relay_client = None
        self.relay_thread = None

        root.title("TROLL BOOTH — Nobody crosses without papers")
        root.geometry("700x720")
        root.minsize(620, 600)
        root.protocol("WM_DELETE_WINDOW", self.close)
        self.main = ttk.Frame(root, padding=24)
        self.main.pack(fill="both", expand=True)
        self.render()

    def clear(self) -> None:
        for child in self.main.winfo_children():
            child.destroy()

    def render(self) -> None:
        self.clear()
        ttk.Label(self.main, text="TROLL BOOTH",
                  font=("Segoe UI", 22, "bold")).pack(anchor="w")
        ttk.Label(self.main, text="Nobody crosses without papers.").pack(anchor="w", pady=(0, 20))
        host, port = self.service.address
        ttk.Label(self.main, text=f"Local service: http://{host}:{port}").pack(anchor="w")
        if not self.service.auth.configured:
            self.render_setup()
        elif self.token:
            self.render_unlocked()
        else:
            self.render_unlock()

    def field(self, label: str, show: str = "") -> ttk.Entry:
        ttk.Label(self.main, text=label).pack(anchor="w", pady=(12, 2))
        entry = ttk.Entry(self.main, show=show)
        entry.pack(fill="x")
        return entry

    def render_setup(self) -> None:
        ttk.Label(self.main, text="First-run setup", font=("Segoe UI", 13, "bold")).pack(anchor="w", pady=(20, 0))
        ttk.Label(self.main, text="Add this manual key to your authenticator, then enter its current code.", wraplength=540).pack(anchor="w")
        key = ttk.Entry(self.main)
        key.insert(0, self.pending_secret)
        key.configure(state="readonly")
        key.pack(fill="x", pady=(8, 0))
        self.password = self.field("Password (12 characters minimum)", "•")
        self.confirm = self.field("Confirm password", "•")
        self.code = self.field("Six-digit TOTP")
        ttk.Button(self.main, text="CREATE LOCK", command=self.setup).pack(anchor="e", pady=18)

    def render_unlock(self) -> None:
        ttk.Label(self.main, text="Locked", font=("Segoe UI", 13, "bold")).pack(anchor="w", pady=(20, 0))
        self.actor = tk.StringVar(value="sean")
        ttk.Label(self.main, text="Actor").pack(anchor="w", pady=(12, 2))
        ttk.Combobox(self.main, textvariable=self.actor, state="readonly",
                     values=("sean", "codex", "claude", "assistant")).pack(fill="x")
        self.password = self.field("Password", "•")
        self.code = self.field("Six-digit TOTP")
        ttk.Button(self.main, text="UNLOCK FOR 10 MINUTES", command=self.unlock).pack(anchor="e", pady=18)

    def render_unlocked(self) -> None:
        ttk.Label(self.main, text="Unlocked", foreground="#188a2e",
                  font=("Segoe UI", 13, "bold")).pack(anchor="w", pady=(20, 0))
        ttk.Label(self.main, text="The local broker is accepting authenticated, policy-approved requests only.", wraplength=540).pack(anchor="w", pady=(4, 16))
        ttk.Button(self.main, text="EMERGENCY LOCK", command=self.lock).pack(anchor="w")
        ttk.Button(self.main, text="OPEN COLLABORATION ROOM",
                   command=self.open_room).pack(anchor="w", pady=(6, 0))
        pair = ttk.Frame(self.main)
        pair.pack(fill="x", pady=(16, 6))
        ttk.Label(pair, text="Browser actor").pack(side="left")
        ttk.Combobox(pair, textvariable=self.pair_actor, state="readonly", width=12,
                     values=("codex", "claude", "assistant", "sean")).pack(side="left", padx=8)
        ttk.Button(pair, text="CREATE ONE-TIME PAIRING CODE",
                   command=self.create_pairing).pack(side="left")
        if self.pair_code.get():
            code = ttk.Entry(self.main, textvariable=self.pair_code, state="readonly")
            code.pack(fill="x", pady=(0, 8))
            ttk.Button(self.main, text="COPY PAIRING CODE",
                       command=self.copy_pairing).pack(anchor="e")
        ttk.Label(self.main, text="Claude Web relay",
                  font=("Segoe UI", 11, "bold")).pack(anchor="w", pady=(16, 4))
        relay = ttk.Frame(self.main)
        relay.pack(fill="x")
        ttk.Entry(relay, textvariable=self.relay_url).grid(
            row=0, column=0, columnspan=2, sticky="ew")
        ttk.Entry(relay, textvariable=self.device_id, width=24).grid(
            row=1, column=0, sticky="ew", pady=4)
        ttk.Entry(relay, textvariable=self.device_token, show="•").grid(
            row=1, column=1, sticky="ew", padx=(6, 0), pady=4)
        relay.columnconfigure(0, weight=1)
        relay.columnconfigure(1, weight=2)
        buttons = ttk.Frame(self.main)
        buttons.pack(fill="x")
        ttk.Button(buttons, text="CONNECT RELAY",
                   command=self.connect_relay).pack(side="left")
        ttk.Button(buttons, text="DISCONNECT",
                   command=self.disconnect_relay).pack(side="left", padx=6)
        ttk.Label(buttons, textvariable=self.relay_status).pack(side="left", padx=8)
        ttk.Label(self.main, text="Approved applications",
                  font=("Segoe UI", 11, "bold")).pack(anchor="w", pady=(20, 4))
        for app in self.service.broker.list_apps():
            row = ttk.Frame(self.main)
            row.pack(fill="x", pady=2)
            ttk.Label(row, text=app["name"]).pack(side="left")
            if not app.get("healthy"):
                ttk.Label(row, text="UNAVAILABLE").pack(side="right")
            elif app.get("trusted"):
                ttk.Label(row, text="APPROVED").pack(side="right")
            else:
                ttk.Button(row, text="APPROVE THIS BUILD",
                           command=lambda app_id=app["id"]: self.approve(app_id)).pack(side="right")

    def approve(self, app_id: str) -> None:
        try:
            app = self.service.broker.discover(app_id)
            assert self.service.broker.trust_store is not None
            self.service.broker.trust_store.approve(app_id, app.executable_hash, "sean")
        except Exception as exc:
            messagebox.showerror("TROLL BOOTH", str(exc))
            return
        self.render()

    def create_pairing(self) -> None:
        try:
            code, _ = self.service.auth.create_pairing(self.token, self.pair_actor.get())
        except AuthError as exc:
            messagebox.showerror("TROLL BOOTH", str(exc))
            return
        self.pair_code.set(code)
        self.render()

    def copy_pairing(self) -> None:
        self.root.clipboard_clear()
        self.root.clipboard_append(self.pair_code.get())

    def open_room(self) -> None:
        RoomWindow(self.root, self.service)

    def relay_message(self, message: str) -> None:
        self.root.after(0, lambda: self.relay_status.set(message))

    def relay_authorized(self) -> bool:
        try:
            self.service.auth.authenticate(self.token)
            return True
        except AuthError:
            return False

    def connect_relay(self) -> None:
        self.disconnect_relay()
        try:
            client = RelayClient(
                self.service.broker, self.relay_url.get(), self.device_id.get(),
                self.device_token.get(), self.relay_message, self.relay_authorized)
        except RelayError as exc:
            messagebox.showerror("TROLL BOOTH", str(exc))
            return
        self.relay_client = client
        self.relay_thread = threading.Thread(target=client.run, daemon=True)
        self.relay_thread.start()
        self.relay_status.set("Connecting…")

    def disconnect_relay(self) -> None:
        if self.relay_client is not None:
            self.relay_client.stop()
        self.relay_client = None
        self.relay_thread = None
        self.relay_status.set("Relay disconnected")

    def setup(self) -> None:
        if self.password.get() != self.confirm.get():
            messagebox.showerror("TROLL BOOTH", "The passwords do not match.")
            return
        if self.code.get() != totp(self.pending_secret):
            messagebox.showerror("TROLL BOOTH", "The TOTP code was refused.")
            return
        try:
            self.service.auth.configure(self.password.get(), self.pending_secret)
            self.token, _ = self.service.auth.unlock("sean", self.password.get(), self.code.get())
        except AuthError as exc:
            messagebox.showerror("TROLL BOOTH", str(exc))
            return
        self.render()

    def unlock(self) -> None:
        try:
            self.token, _ = self.service.auth.unlock(
                self.actor.get(), self.password.get(), self.code.get())
        except AuthError as exc:
            messagebox.showerror("TROLL BOOTH", str(exc))
            return
        self.render()

    def lock(self) -> None:
        self.disconnect_relay()
        self.service.auth.lock()
        self.token = ""
        self.pair_code.set("")
        self.render()

    def close(self) -> None:
        self.disconnect_relay()
        self.service.auth.lock()
        self.service.server.shutdown()
        self.service.close()
        self.root.destroy()


def main() -> None:
    root = tk.Tk()
    BoothWindow(root)
    root.mainloop()


class RoomWindow:
    def __init__(self, parent: tk.Tk, service: BoothService):
        self.service = service
        self.window = tk.Toplevel(parent)
        self.window.title("TROLL BOOTH — Collaboration room")
        self.window.geometry("760x520")
        frame = ttk.Frame(self.window, padding=16)
        frame.pack(fill="both", expand=True)
        self.history = tk.Text(frame, state="disabled", wrap="word")
        self.history.pack(fill="both", expand=True)
        compose = ttk.Frame(frame)
        compose.pack(fill="x", pady=(10, 0))
        self.text = ttk.Entry(compose)
        self.text.pack(side="left", fill="x", expand=True)
        self.text.bind("<Return>", lambda _event: self.send())
        ttk.Button(compose, text="SEND AS SEAN", command=self.send).pack(side="left", padx=(8, 0))
        self.refresh()

    def refresh(self) -> None:
        if not self.window.winfo_exists():
            return
        messages = self.service.room.recent()
        lines = [f"{item.get('actor')} → {item.get('recipient')}: {item.get('text')}"
                 for item in messages]
        self.history.configure(state="normal")
        self.history.delete("1.0", "end")
        self.history.insert("end", "\n\n".join(lines))
        self.history.see("end")
        self.history.configure(state="disabled")
        self.window.after(2000, self.refresh)

    def send(self) -> None:
        try:
            self.service.room.add("sean", self.text.get())
        except Exception as exc:
            messagebox.showerror("TROLL BOOTH", str(exc))
            return
        self.text.delete(0, "end")
        self.refresh()


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
