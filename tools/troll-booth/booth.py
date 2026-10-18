# SNAPSMACK_EOF_HEADER
"""Native TROLL BOOTH lock and service window."""

from __future__ import annotations

import threading
import tkinter as tk
from pathlib import Path
from tkinter import messagebox, ttk

from troll_booth.auth import AuthError, generate_totp_secret, totp
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

        root.title("TROLL BOOTH — Nobody crosses without papers")
        root.geometry("620x430")
        root.minsize(560, 390)
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
        self.service.auth.lock()
        self.token = ""
        self.render()

    def close(self) -> None:
        self.service.auth.lock()
        self.service.server.shutdown()
        self.service.close()
        self.root.destroy()


def main() -> None:
    root = tk.Tk()
    BoothWindow(root)
    root.mainloop()


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
