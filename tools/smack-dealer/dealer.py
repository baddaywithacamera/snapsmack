# SNAPSMACK_EOF_HEADER
"""SMACK DEALER administrative application launcher."""

from __future__ import annotations

import subprocess
import sys
import tkinter as tk
from dataclasses import dataclass
from pathlib import Path
from tkinter import messagebox, ttk


@dataclass(frozen=True)
class App:
    name: str
    description: str
    candidates: tuple[Path, ...]

    def installed_path(self) -> Path | None:
        return next((path for path in self.candidates if path.is_file()), None)


def catalog(tools_root: Path | None = None) -> tuple[App, ...]:
    tools = (tools_root or Path(__file__).resolve().parents[1]).resolve()
    installed = Path("C:/snapsmack")
    return (
        App("TROLL BOOTH", "Secure local agent capability broker", (
            installed / "troll-booth" / "TROLL BOOTH.exe",
            tools / "troll-booth" / "booth.py")),
        App("CRONOMETER", "Schedules and watches local work", (
            installed / "cronometer" / "CRONOMETER.exe",
            tools / "cronometer" / "cronometer.py")),
        App("LOG FLOGGER", "Collects and analyses application logs", (
            installed / "log-flogger" / "LOG FLOGGER.exe",
            tools / "log-flogger" / "app.py")),
        App("TICKET WICKET", "Support ticket operations", (
            installed / "ticket-wicket" / "TICKET WICKET.exe",
            tools / "ticket-wicket" / "app.py")),
        App("SMACK UP YOUR BACKUP", "Backups, verification, and recovery", (
            installed / "smack-up-your-backup" / "SMACK UP YOUR BACKUP.exe",
            tools / "smack-up-your-backup" / "suyb_launcher.py")),
    )


def launch(path: Path) -> None:
    command = [str(path)] if path.suffix.lower() == ".exe" else [sys.executable, str(path)]
    subprocess.Popen(command, cwd=str(path.parent), shell=False)


class DealerWindow:
    def __init__(self, root: tk.Tk):
        self.root = root
        root.title("SMACK DEALER — Admin apps")
        root.geometry("760x520")
        root.minsize(620, 420)
        frame = ttk.Frame(root, padding=24)
        frame.pack(fill="both", expand=True)
        ttk.Label(frame, text="SMACK DEALER", font=("Segoe UI", 24, "bold")).pack(anchor="w")
        ttk.Label(frame, text="Administration and operations").pack(anchor="w", pady=(0, 22))
        self.apps = ttk.Frame(frame)
        self.apps.pack(fill="both", expand=True)
        self.render()

    def render(self) -> None:
        for child in self.apps.winfo_children():
            child.destroy()
        for index, app in enumerate(catalog()):
            path = app.installed_path()
            card = ttk.Frame(self.apps, padding=(12, 10))
            card.grid(row=index, column=0, sticky="ew", pady=4)
            card.columnconfigure(0, weight=1)
            ttk.Label(card, text=app.name, font=("Segoe UI", 12, "bold")).grid(row=0, column=0, sticky="w")
            ttk.Label(card, text=app.description).grid(row=1, column=0, sticky="w")
            button = ttk.Button(card, text="OPEN" if path else "NOT INSTALLED",
                                command=lambda selected=path: self.open(selected))
            button.grid(row=0, column=1, rowspan=2, padx=(16, 0))
            if path is None:
                button.state(["disabled"])
        self.apps.columnconfigure(0, weight=1)

    def open(self, path: Path | None) -> None:
        if path is None:
            return
        try:
            launch(path)
        except OSError as exc:
            messagebox.showerror("SMACK DEALER", f"Could not open the application.\n\n{exc}")


def main() -> None:
    root = tk.Tk()
    DealerWindow(root)
    root.mainloop()


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
