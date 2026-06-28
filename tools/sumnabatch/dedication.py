"""
SUMNABATCH — dedication.py
Phase-1 launch dedication modal for Richard Dimitri, the actor who gave us
Roman Troy Maronie. Shows on launch unless the user picked "Don't Show Again",
which is persisted to config.ini under [ui] dedication_dismissed (survives as
long as the .ini is not deleted). Plain styling — the Maronie voice is Phase 2;
the dedication text itself is the tribute as written.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import configparser
import tkinter as tk

import config as _config  # reuse the same config.ini path resolution

BG_DEEP = "#141414"
BG_CARD = "#1C1C1C"
ACCENT  = "#39FF14"
ACCENT2 = "#2ECC10"
FG_MAIN = "#EEEEEE"
FG_DIM  = "#777777"


def _is_dismissed() -> bool:
    cfg = configparser.ConfigParser()
    try:
        cfg.read(_config._config_path())
        return cfg.getboolean('ui', 'dedication_dismissed', fallback=False)
    except Exception:
        return False


def _set_dismissed() -> None:
    """Read-modify-write config.ini so other sections are preserved."""
    path = _config._config_path()
    cfg = configparser.ConfigParser()
    cfg.read(path)
    if not cfg.has_section('ui'):
        cfg.add_section('ui')
    cfg.set('ui', 'dedication_dismissed', 'true')
    with open(path, 'w') as f:
        cfg.write(f)


def maybe_show(parent) -> None:
    """Show the dedication modal once per launch unless dismissed forever."""
    if _is_dismissed():
        return

    win = tk.Toplevel(parent)
    win.title("SUMNABATCH")
    win.configure(bg=BG_DEEP)
    win.transient(parent)
    win.resizable(False, False)

    pad = tk.Frame(win, bg=BG_DEEP)
    pad.pack(padx=30, pady=26)

    tk.Label(pad, text="SUMNABATCH", bg=BG_DEEP, fg=ACCENT,
             font=("Segoe UI", 15, "bold")).pack()
    tk.Label(pad, text="The last fargin' batch poster you will ever use.",
             bg=BG_DEEP, fg=FG_DIM, font=("Segoe UI", 9, "italic")).pack(pady=(2, 20))

    tk.Label(pad, text="Dedicated to the memory of", bg=BG_DEEP, fg=FG_MAIN,
             font=("Segoe UI", 10)).pack()
    tk.Label(pad, text="RICHARD DIMITRI", bg=BG_DEEP, fg=ACCENT,
             font=("Segoe UI", 16, "bold")).pack(pady=(3, 0))
    tk.Label(pad, text="June 27, 1942  –  December 18, 2025", bg=BG_DEEP,
             fg=FG_DIM, font=("Segoe UI", 9)).pack(pady=(3, 18))

    body = (
        "One of the best fargin' actors ever, and one of the funniest.\n"
        "He gave us Roman Troy Maronie — the voice this tool will wear.\n\n"
        "Rest in peace, you sumnabatch. We heard he is buried in Sweden\n"
        "even though he said he is not from there."
    )
    tk.Label(pad, text=body, bg=BG_DEEP, fg=FG_MAIN, font=("Segoe UI", 10),
             justify="center").pack(pady=(0, 24))

    btns = tk.Frame(pad, bg=BG_DEEP)
    btns.pack()

    def _close():
        try:
            win.grab_release()
        except Exception:
            pass
        win.destroy()

    def _never():
        try:
            _set_dismissed()
        except Exception:
            pass
        _close()

    tk.Button(btns, text="Dismiss", command=_close, bg=BG_CARD, fg=FG_MAIN,
              activebackground=ACCENT2, activeforeground="#000000",
              font=("Segoe UI", 9), relief="flat", padx=20, pady=6,
              cursor="hand2").pack(side="left", padx=6)
    tk.Button(btns, text="Don't Show Again", command=_never, bg=BG_CARD,
              fg=FG_DIM, activebackground=ACCENT2, activeforeground="#000000",
              font=("Segoe UI", 9), relief="flat", padx=20, pady=6,
              cursor="hand2").pack(side="left", padx=6)

    # Center over the parent window.
    win.update_idletasks()
    try:
        px, py = parent.winfo_rootx(), parent.winfo_rooty()
        pw, ph = parent.winfo_width(), parent.winfo_height()
        ww, wh = win.winfo_width(), win.winfo_height()
        win.geometry(f"+{px + (pw - ww) // 2}+{py + (ph - wh) // 3}")
    except Exception:
        pass

    win.protocol("WM_DELETE_WINDOW", _close)
    win.grab_set()
# ===== SNAPSMACK EOF =====
