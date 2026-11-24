"""PROX VOX credential setup window.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

from __future__ import annotations

import tkinter as tk
from tkinter import messagebox

from .credentials import CONTAINER_SECRET_KINDS, NoToken, load_container_secret, save_container_secret


ROWS = (
    ("ct102_ssh", "CT102 SSH password"),
    ("ct106_ssh", "CT106 SSH password"),
    ("mysql_ro", "CT110 database password"),
    ("pve_host_ssh", "Proxmox host password"),
)


def credential_presence(reader=None) -> dict[str, bool]:
    """Return presence only; never return or display a credential value."""
    result = {}
    for kind, _label in ROWS:
        try:
            load_container_secret(kind, reader=reader)
            result[kind] = True
        except NoToken:
            result[kind] = False
    return result


def save_setup_secret(kind: str, secret: str, writer=None) -> None:
    """Validate one known slot and store its owner-supplied value."""
    if kind not in CONTAINER_SECRET_KINDS:
        raise NoToken("Unknown PROX VOX credential slot.")
    save_container_secret(kind, secret, writer=writer)


class SetupWindow:
    def __init__(self, root: tk.Tk):
        self.root = root
        self.root.title("PROX VOX credential setup")
        self.root.resizable(False, False)
        self.status = {kind: tk.StringVar(value="CHECKING") for kind, _ in ROWS}

        frame = tk.Frame(root, bg="#17191d", padx=24, pady=22)
        frame.grid(sticky="nsew")
        tk.Label(frame, text="PROX VOX", bg="#17191d", fg="#f4b942",
                 font=("Segoe UI", 18, "bold")).grid(row=0, column=0, columnspan=3, sticky="w")
        tk.Label(frame, text="Copy each password from Bitwarden, then click its PASTE & SAVE button.",
                 bg="#17191d", fg="#f2f2f2", font=("Segoe UI", 10),
                 pady=8).grid(row=1, column=0, columnspan=3, sticky="w")
        tk.Label(frame, text="Passwords are written directly to Windows Credential Manager and never displayed.",
                 bg="#17191d", fg="#b7bcc5", font=("Segoe UI", 9),
                 pady=2).grid(row=2, column=0, columnspan=3, sticky="w")

        for offset, (kind, label) in enumerate(ROWS, start=3):
            tk.Label(frame, text=label, width=28, anchor="w", bg="#17191d", fg="#f2f2f2",
                     font=("Segoe UI", 10)).grid(row=offset, column=0, sticky="w", pady=7)
            tk.Label(frame, textvariable=self.status[kind], width=10, anchor="center",
                     bg="#272b31", fg="#d7dbe2", font=("Consolas", 10, "bold"),
                     padx=8, pady=6).grid(row=offset, column=1, padx=(10, 12), pady=7)
            tk.Button(frame, text="PASTE & SAVE", width=16,
                      command=lambda key=kind: self.paste_and_save(key),
                      bg="#f4b942", fg="#111111", activebackground="#ffd36a",
                      font=("Segoe UI", 9, "bold"), relief="flat", padx=8, pady=5
                      ).grid(row=offset, column=2, pady=7)

        tk.Button(frame, text="REFRESH STATUS", command=self.refresh,
                  bg="#343941", fg="#f2f2f2", activebackground="#4b525d",
                  activeforeground="#ffffff", relief="flat", padx=12, pady=6
                  ).grid(row=8, column=0, sticky="w", pady=(14, 0))
        tk.Button(frame, text="CLOSE", command=root.destroy,
                  bg="#343941", fg="#f2f2f2", activebackground="#4b525d",
                  activeforeground="#ffffff", relief="flat", padx=18, pady=6
                  ).grid(row=8, column=2, sticky="e", pady=(14, 0))
        self.refresh()

    def refresh(self) -> None:
        for kind, present in credential_presence().items():
            self.status[kind].set("PRESENT" if present else "MISSING")

    def paste_and_save(self, kind: str) -> None:
        try:
            secret = self.root.clipboard_get()
        except tk.TclError:
            messagebox.showerror("Nothing to paste", "Copy the matching password from Bitwarden first.")
            return
        try:
            save_setup_secret(kind, secret)
        except NoToken as exc:
            messagebox.showerror("Credential not saved", str(exc))
            return
        finally:
            secret = ""
        self.root.clipboard_clear()
        self.status[kind].set("PRESENT")


def main() -> int:
    root = tk.Tk()
    SetupWindow(root)
    root.mainloop()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
