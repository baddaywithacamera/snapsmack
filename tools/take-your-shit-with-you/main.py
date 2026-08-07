"""
TAKE YOUR SHIT WITH YOU — desktop application.

Every image. Every scrap of data. Pack it up and leave.

Spec: _spec/take-your-shit-with-you-spec-v0_1.md section 15.

The tone of the product is loud. The interface is not. Somebody using this is
often leaving something they built, and the least a departure tool can do is be
legible while they do it: plain labels, one screen at a time, and errors that
say what happened, whether the data is safe, and what to do next.

The five named states from spec 15 are the header of one window rather than five
separate panes — PACKING LIST, PACK YOUR SHIT, COURTESY COUNTER, EVERYTHING
ACCOUNTED FOR, YOUR SHIT IS PACKED — because during a long export the thing a
person wants is continuity, not navigation.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

BUILD_VERSION = "0.1.0"   # auto-incremented by bump_version.py on each build.bat run

import os
import queue
import shutil
import subprocess
import sys
import threading
import traceback
import webbrowser

import tkinter as tk
from tkinter import filedialog, messagebox, ttk

import config
from export_engine import (Cancelled, ExportEngine, ExportOptions)
from tyswy_client import TyswyClient, TyswyError

# ---------------------------------------------------------------------------
# Palette — SnapSmack house dark + lime, same family as FLKR FCKR and SUYB.
# ---------------------------------------------------------------------------
BG_DEEP   = '#14140f'
BG_CARD   = '#1c1c16'
BG_FIELD  = '#111110'
BG_HOVER  = '#252520'
ACCENT    = '#b6ff1a'
ACCENT_DK = '#8fd400'
BORDER    = '#383830'
TEXT_PRI  = '#e8e8e0'
TEXT_DIM  = '#9a9a90'
TEXT_OK   = '#4ec994'
TEXT_ERR  = '#ff5b5b'
TEXT_WARN = '#e0a53a'

FONT_H1    = ('Segoe UI', 18, 'bold')
FONT_H2    = ('Segoe UI', 12, 'bold')
FONT_BODY  = ('Segoe UI', 10)
FONT_SMALL = ('Segoe UI', 9)
FONT_MONO  = ('Consolas', 9)

STAGE_TITLES = {
    'connect':  'PACKING LIST',
    'preflight': 'PACKING LIST',
    'collect':  'PACK YOUR SHIT',
    'assemble': 'PACK YOUR SHIT',
    'media':    'PACK YOUR SHIT',
    'indexes':  'PACK YOUR SHIT',
    'adapters': 'COURTESY COUNTER',
    'verify':   'EVERYTHING ACCOUNTED FOR',
    'finish':   'YOUR SHIT IS PACKED',
}

TAGLINE = "Every image. Every scrap of data. Pack it up and leave."
FAREWELL = "No hard feelings. Here's your hat—what's your hurry?"


def human_bytes(n):
    n = float(n or 0)
    for unit in ('B', 'KB', 'MB', 'GB', 'TB'):
        if n < 1024 or unit == 'TB':
            return f'{n:,.1f} {unit}' if unit != 'B' else f'{int(n)} B'
        n /= 1024
    return f'{n:.1f} TB'


def open_in_file_manager(path):
    try:
        if sys.platform.startswith('win'):
            os.startfile(path)                       # noqa: S606 - a folder we made
        elif sys.platform == 'darwin':
            subprocess.Popen(['open', path])
        else:
            subprocess.Popen(['xdg-open', path])
        return True
    except Exception:
        return False


# ---------------------------------------------------------------------------
# Vault dialog
# ---------------------------------------------------------------------------

class KeySecurityDialog(tk.Toplevel):
    """Turn encryption on/off for the stored export key, or change the
    passphrase. Mirrors the FLKR FCKR dialog so the two tools behave the same."""

    def __init__(self, parent):
        super().__init__(parent)
        self.parent = parent
        self.title('Key security')
        self.configure(bg=BG_DEEP)
        self.resizable(False, False)
        self.transient(parent)
        self.grab_set()

        body = tk.Frame(self, bg=BG_DEEP, padx=18, pady=16)
        body.pack(fill='both', expand=True)

        self.status = tk.Label(body, text='', bg=BG_DEEP, fg=TEXT_PRI,
                               font=FONT_H2, anchor='w', justify='left')
        self.status.pack(fill='x')
        self.detail = tk.Label(body, text='', bg=BG_DEEP, fg=TEXT_DIM,
                               font=FONT_SMALL, anchor='w', justify='left',
                               wraplength=430)
        self.detail.pack(fill='x', pady=(6, 14))

        self.btns = tk.Frame(body, bg=BG_DEEP)
        self.btns.pack(fill='x')
        tk.Button(body, text='Close', command=self.destroy, bg=BG_CARD,
                  fg=TEXT_PRI, relief='flat', bd=0, padx=14, pady=5,
                  activebackground=BG_HOVER, font=FONT_BODY).pack(anchor='e', pady=(16, 0))
        self._refresh()

    def _refresh(self):
        for w in self.btns.winfo_children():
            w.destroy()
        if not config.vault_available():
            self.status.config(text='Encryption unavailable', fg=TEXT_WARN)
            self.detail.config(
                text='The `cryptography` package is not in this build, so your '
                     'export key is stored as base64 — an encoding, not '
                     'encryption. Anyone with this folder can read it. The key '
                     'is read-only and expires, but treat it as a secret anyway.')
            return

        on = config.vault_enabled()
        if on:
            unlocked = config.vault_unlocked()
            self.status.config(text='Encryption is ON' + ('' if unlocked else ' (locked)'),
                               fg=ACCENT if unlocked else TEXT_WARN)
            self.detail.config(
                text='Your export key is sealed with a key derived from your '
                     'passphrase. The passphrase is not stored anywhere, so a '
                     'copy of this folder is not enough to read the key.'
                     + ('' if unlocked else '\n\nThe vault is locked. Unlock to '
                        'use the saved key.'))
            self._btn('Change passphrase', self._change)
            self._btn('Turn encryption off', self._off)
        else:
            self.status.config(text='Encryption is OFF', fg=TEXT_WARN)
            self.detail.config(
                text='Your export key is stored as base64. That is an ENCODING, '
                     'not encryption — it reverses with one function call. Turn '
                     'encryption on and it is sealed with your passphrase '
                     'instead.')
            self._btn('Turn encryption on', self._on, primary=True)

    def _btn(self, text, cmd, primary=False):
        tk.Button(self.btns, text=text, command=cmd,
                  bg=ACCENT if primary else BG_CARD,
                  fg='#000000' if primary else TEXT_PRI,
                  relief='flat', bd=0, padx=14, pady=6, font=FONT_BODY,
                  activebackground=ACCENT_DK if primary else BG_HOVER
                  ).pack(side='left', padx=(0, 8))

    def _ask(self, prompt, confirm=False):
        win = tk.Toplevel(self)
        win.title('Passphrase')
        win.configure(bg=BG_DEEP)
        win.transient(self)
        win.grab_set()
        result = {}
        tk.Label(win, text=prompt, bg=BG_DEEP, fg=TEXT_PRI, font=FONT_BODY,
                 wraplength=380, justify='left').pack(padx=18, pady=(16, 8), anchor='w')
        e1 = tk.Entry(win, show='•', bg=BG_FIELD, fg=TEXT_PRI, width=36,
                      insertbackground=ACCENT, relief='flat', font=FONT_BODY)
        e1.pack(padx=18, pady=4)
        e1.focus_set()
        e2 = None
        if confirm:
            tk.Label(win, text='Again, to be sure:', bg=BG_DEEP, fg=TEXT_DIM,
                     font=FONT_SMALL).pack(padx=18, anchor='w', pady=(8, 0))
            e2 = tk.Entry(win, show='•', bg=BG_FIELD, fg=TEXT_PRI, width=36,
                          insertbackground=ACCENT, relief='flat', font=FONT_BODY)
            e2.pack(padx=18, pady=4)

        def ok():
            if e2 is not None and e1.get() != e2.get():
                messagebox.showerror('Passphrase', 'Those do not match.', parent=win)
                return
            result['value'] = e1.get()
            win.destroy()

        tk.Button(win, text='OK', command=ok, bg=ACCENT, fg='#000000',
                  relief='flat', bd=0, padx=18, pady=5, font=FONT_BODY
                  ).pack(pady=(14, 16))
        win.bind('<Return>', lambda _e: ok())
        self.wait_window(win)
        return result.get('value')

    def _on(self):
        p = self._ask('Choose a passphrase. If you lose it, nothing is destroyed '
                      '— you paste the key in again or mint a new one from '
                      'your site. But we cannot recover it for you.', confirm=True)
        if not p:
            return
        remember = messagebox.askyesno(
            'This machine',
            'Let THIS computer remember the unlock key, so you are not typing a '
            'passphrase every launch?\n\nThe remembered key is machine-bound and '
            'never travels with the folder.', parent=self)
        try:
            config.enable_encryption(p, remember_on_this_machine=remember)
        except Exception as e:
            messagebox.showerror('Encryption', str(e), parent=self)
            return
        self.parent.log('Export key encryption turned ON.', ACCENT)
        self._refresh()

    def _off(self):
        if not config.vault_unlocked():
            messagebox.showerror(
                'Locked', 'Unlock the vault first. Turning encryption off while '
                'locked would strand your key.', parent=self)
            return
        if not messagebox.askyesno(
                'Turn encryption off',
                'The export key will be rewritten as base64 — readable by anyone '
                'with this folder. Continue?', parent=self):
            return
        try:
            config.disable_encryption()
        except Exception as e:
            messagebox.showerror('Encryption', str(e), parent=self)
            return
        self.parent.log('Export key encryption turned OFF.', TEXT_WARN)
        self._refresh()

    def _change(self):
        old = self._ask('Current passphrase:')
        if not old:
            return
        new = self._ask('New passphrase:', confirm=True)
        if not new:
            return
        if not config.change_passphrase(old, new):
            messagebox.showerror('Passphrase', 'That current passphrase is wrong.',
                                 parent=self)
            return
        self.parent.log('Encryption passphrase changed.', ACCENT)
        self._refresh()


# ---------------------------------------------------------------------------
# Main window
# ---------------------------------------------------------------------------

class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f'TAKE YOUR SHIT WITH YOU  v{BUILD_VERSION}')
        self.geometry('980x720')
        self.minsize(880, 620)
        self.configure(bg=BG_DEEP)

        config.init_vault()
        if config.vault_enabled() and not config.vault_unlocked():
            config.unlock_with_machine_key()

        self.cfg        = config.load()
        self.preflight  = None
        self.client     = None
        self.report     = None
        self.worker     = None
        self.cancel_evt = threading.Event()
        self.events     = queue.Queue()

        self._style()
        self._build_header()
        self._build_body()
        self._poll()

        self.protocol('WM_DELETE_WINDOW', self._on_close)
        self._show('connect')

    # -- chrome ---------------------------------------------------------
    def _style(self):
        s = ttk.Style(self)
        try:
            s.theme_use('clam')
        except tk.TclError:
            pass
        s.configure('TYSWY.Horizontal.TProgressbar', troughcolor=BG_FIELD,
                    background=ACCENT, bordercolor=BORDER, lightcolor=ACCENT,
                    darkcolor=ACCENT_DK)
        s.configure('TYSWY.TCombobox', fieldbackground=BG_FIELD, background=BG_CARD)

    def _build_header(self):
        head = tk.Frame(self, bg=BG_DEEP, padx=22, pady=16)
        head.pack(fill='x')
        self.h_title = tk.Label(head, text='PACKING LIST', bg=BG_DEEP, fg=ACCENT,
                                font=FONT_H1, anchor='w')
        self.h_title.pack(fill='x')
        tk.Label(head, text=TAGLINE, bg=BG_DEEP, fg=TEXT_DIM, font=FONT_BODY,
                 anchor='w').pack(fill='x', pady=(2, 0))
        tk.Frame(self, bg=BORDER, height=1).pack(fill='x')

    def _build_body(self):
        self.body = tk.Frame(self, bg=BG_DEEP)
        self.body.pack(fill='both', expand=True)
        self.screens = {}
        for name, builder in (('connect', self._screen_connect),
                              ('progress', self._screen_progress),
                              ('done', self._screen_done)):
            frame = tk.Frame(self.body, bg=BG_DEEP)
            builder(frame)
            self.screens[name] = frame

    def _show(self, screen_or_stage):
        name = {'connect': 'connect'}.get(screen_or_stage, screen_or_stage)
        if name not in self.screens:
            name = 'connect' if screen_or_stage in ('connect', 'preflight') else name
        for f in self.screens.values():
            f.pack_forget()
        self.screens[name].pack(fill='both', expand=True)

    def _set_title(self, stage):
        self.h_title.config(text=STAGE_TITLES.get(stage, 'PACKING LIST'))

    # =====================================================================
    # SCREEN 1 — PACKING LIST
    # =====================================================================
    def _screen_connect(self, root):
        pad = tk.Frame(root, bg=BG_DEEP, padx=22, pady=18)
        pad.pack(fill='both', expand=True)

        # -- site + key
        card = self._card(pad, 'YOUR SITE')
        row = tk.Frame(card, bg=BG_CARD)
        row.pack(fill='x', pady=(4, 0))
        tk.Label(row, text='Site address', bg=BG_CARD, fg=TEXT_DIM,
                 font=FONT_SMALL, width=14, anchor='w').pack(side='left')
        self.v_url = tk.StringVar(value=self.cfg.get('site_url', ''))
        self._entry(row, self.v_url, width=52).pack(side='left', fill='x', expand=True)

        row = tk.Frame(card, bg=BG_CARD)
        row.pack(fill='x', pady=(8, 0))
        tk.Label(row, text='Export key', bg=BG_CARD, fg=TEXT_DIM,
                 font=FONT_SMALL, width=14, anchor='w').pack(side='left')
        self.v_key = tk.StringVar(value=self.cfg.get('api_key', ''))
        self._entry(row, self.v_key, width=52, show='•').pack(side='left', fill='x', expand=True)
        tk.Button(row, text='Key security', command=self._key_dialog, bg=BG_FIELD,
                  fg=TEXT_DIM, relief='flat', bd=0, padx=10, pady=3,
                  activebackground=BG_HOVER, font=FONT_SMALL).pack(side='left', padx=(8, 0))

        tk.Label(card,
                 text='Make the key on your site: Boring Ass Stuff → API Keys '
                      '→ TYSWY (read-only export). It is shown once, it cannot '
                      'write anything, and it expires in three months.',
                 bg=BG_CARD, fg=TEXT_DIM, font=FONT_SMALL, wraplength=800,
                 justify='left', anchor='w').pack(fill='x', pady=(10, 0))

        btn_row = tk.Frame(card, bg=BG_CARD)
        btn_row.pack(fill='x', pady=(12, 2))
        self.b_connect = self._button(btn_row, 'CONNECT', self._on_connect, primary=True)
        self.b_connect.pack(side='left')
        self.l_conn = tk.Label(btn_row, text='', bg=BG_CARD, fg=TEXT_DIM,
                               font=FONT_SMALL, anchor='w')
        self.l_conn.pack(side='left', padx=(14, 0))

        # -- what is there
        self.card_manifest = self._card(pad, 'WHAT IS THERE')
        self.l_manifest = tk.Label(self.card_manifest,
                                   text='Connect and this fills in.',
                                   bg=BG_CARD, fg=TEXT_DIM, font=FONT_MONO,
                                   justify='left', anchor='w')
        self.l_manifest.pack(fill='x')

        # -- destination + options
        card = self._card(pad, 'WHERE IT GOES')
        row = tk.Frame(card, bg=BG_CARD)
        row.pack(fill='x')
        tk.Label(row, text='Folder', bg=BG_CARD, fg=TEXT_DIM, font=FONT_SMALL,
                 width=14, anchor='w').pack(side='left')
        self.v_dest = tk.StringVar(value=self.cfg.get('destination', ''))
        self._entry(row, self.v_dest, width=52).pack(side='left', fill='x', expand=True)
        tk.Button(row, text='Browse…', command=self._pick_dest, bg=BG_FIELD,
                  fg=TEXT_PRI, relief='flat', bd=0, padx=12, pady=3,
                  activebackground=BG_HOVER, font=FONT_SMALL).pack(side='left', padx=(8, 0))
        self.l_space = tk.Label(card, text='', bg=BG_CARD, fg=TEXT_DIM,
                                font=FONT_SMALL, anchor='w')
        self.l_space.pack(fill='x', pady=(6, 0))
        self.v_dest.trace_add('write', lambda *_a: self._update_space())

        opts = tk.Frame(card, bg=BG_CARD)
        opts.pack(fill='x', pady=(12, 0))
        self.v_wp = tk.BooleanVar(value=self.cfg.get('courtesy_wordpress', True))
        self.v_thumbs = tk.BooleanVar(value=self.cfg.get('include_thumbnails', False))
        self.v_zip = tk.BooleanVar(value=self.cfg.get('compress', False))
        self._check(opts, 'Build a WordPress courtesy package', self.v_wp).pack(anchor='w')
        self._check(opts, 'Include generated thumbnails (derived files, usually not needed)',
                    self.v_thumbs).pack(anchor='w')
        self._check(opts, 'Also make a .zip when it is finished', self.v_zip).pack(anchor='w')

        row = tk.Frame(opts, bg=BG_CARD)
        row.pack(fill='x', pady=(8, 0))
        tk.Label(row, text='Downloads at once', bg=BG_CARD, fg=TEXT_DIM,
                 font=FONT_SMALL).pack(side='left')
        self.v_conc = tk.IntVar(value=int(self.cfg.get('media_concurrency', 2)))
        cc = ttk.Combobox(row, textvariable=self.v_conc, values=[1, 2, 3, 4],
                          width=4, state='readonly')
        cc.pack(side='left', padx=(8, 0))
        tk.Label(row, text='— 2 is kind to shared hosting. 4 is the most this '
                           'tool will ever do.',
                 bg=BG_CARD, fg=TEXT_DIM, font=FONT_SMALL).pack(side='left', padx=(8, 0))

        go = tk.Frame(pad, bg=BG_DEEP)
        go.pack(fill='x', pady=(16, 0))
        self.b_go = self._button(go, 'PACK MY SHIT', self._on_start, primary=True)
        self.b_go.pack(side='left')
        self.b_go.config(state='disabled')
        tk.Label(go, text=FAREWELL, bg=BG_DEEP, fg=TEXT_DIM,
                 font=FONT_SMALL).pack(side='left', padx=(16, 0))

    # =====================================================================
    # SCREEN 2 — PACK YOUR SHIT
    # =====================================================================
    def _screen_progress(self, root):
        pad = tk.Frame(root, bg=BG_DEEP, padx=22, pady=18)
        pad.pack(fill='both', expand=True)

        self.l_stage = tk.Label(pad, text='Starting…', bg=BG_DEEP, fg=TEXT_PRI,
                                font=FONT_H2, anchor='w')
        self.l_stage.pack(fill='x')
        self.l_detail = tk.Label(pad, text='', bg=BG_DEEP, fg=TEXT_DIM,
                                 font=FONT_BODY, anchor='w')
        self.l_detail.pack(fill='x', pady=(2, 10))

        self.bar = ttk.Progressbar(pad, style='TYSWY.Horizontal.TProgressbar',
                                   mode='determinate', maximum=1000)
        self.bar.pack(fill='x')

        tk.Label(pad, text='LOG', bg=BG_DEEP, fg=TEXT_DIM, font=FONT_SMALL,
                 anchor='w').pack(fill='x', pady=(16, 4))
        wrap = tk.Frame(pad, bg=BORDER, padx=1, pady=1)
        wrap.pack(fill='both', expand=True)
        self.log_box = tk.Text(wrap, bg=BG_FIELD, fg=TEXT_PRI, font=FONT_MONO,
                               relief='flat', bd=0, wrap='word', height=18,
                               insertbackground=ACCENT)
        self.log_box.pack(side='left', fill='both', expand=True)
        sb = tk.Scrollbar(wrap, command=self.log_box.yview, bg=BG_CARD,
                          troughcolor=BG_FIELD, relief='flat', bd=0)
        sb.pack(side='right', fill='y')
        self.log_box.config(yscrollcommand=sb.set, state='disabled')
        for tag, colour in (('ok', TEXT_OK), ('warn', TEXT_WARN),
                            ('err', TEXT_ERR), ('accent', ACCENT)):
            self.log_box.tag_config(tag, foreground=colour)

        row = tk.Frame(pad, bg=BG_DEEP)
        row.pack(fill='x', pady=(12, 0))
        self.b_cancel = self._button(row, 'STOP', self._on_cancel)
        self.b_cancel.pack(side='left')
        tk.Label(row, text='Stopping is safe. Everything already downloaded and '
                           'verified stays where it is, and this folder will '
                           'carry on from there.',
                 bg=BG_DEEP, fg=TEXT_DIM, font=FONT_SMALL,
                 wraplength=700, justify='left').pack(side='left', padx=(14, 0))

    # =====================================================================
    # SCREEN 3 — YOUR SHIT IS PACKED
    # =====================================================================
    def _screen_done(self, root):
        pad = tk.Frame(root, bg=BG_DEEP, padx=22, pady=18)
        pad.pack(fill='both', expand=True)

        self.l_done = tk.Label(pad, text='', bg=BG_DEEP, fg=ACCENT, font=FONT_H2,
                               anchor='w', justify='left')
        self.l_done.pack(fill='x')
        self.l_done_sub = tk.Label(pad, text='', bg=BG_DEEP, fg=TEXT_DIM,
                                   font=FONT_BODY, anchor='w', justify='left',
                                   wraplength=880)
        self.l_done_sub.pack(fill='x', pady=(4, 12))

        wrap = tk.Frame(pad, bg=BORDER, padx=1, pady=1)
        wrap.pack(fill='both', expand=True)
        self.done_box = tk.Text(wrap, bg=BG_FIELD, fg=TEXT_PRI, font=FONT_MONO,
                                relief='flat', bd=0, wrap='word', height=22)
        self.done_box.pack(side='left', fill='both', expand=True)
        sb = tk.Scrollbar(wrap, command=self.done_box.yview, bg=BG_CARD,
                          troughcolor=BG_FIELD, relief='flat', bd=0)
        sb.pack(side='right', fill='y')
        self.done_box.config(yscrollcommand=sb.set, state='disabled')
        for tag, colour in (('ok', TEXT_OK), ('warn', TEXT_WARN),
                            ('err', TEXT_ERR), ('accent', ACCENT)):
            self.done_box.tag_config(tag, foreground=colour)

        row = tk.Frame(pad, bg=BG_DEEP)
        row.pack(fill='x', pady=(14, 0))
        self._button(row, 'OPEN FOLDER', self._open_folder, primary=True).pack(side='left')
        self._button(row, 'VIEW REPORT', self._view_report).pack(side='left', padx=(8, 0))
        self.b_zip = self._button(row, 'COMPRESS LOCALLY', self._compress)
        self.b_zip.pack(side='left', padx=(8, 0))
        self._button(row, 'START ANOTHER', self._reset).pack(side='right')

        # Spec 13: deleting an incomplete export is a SEPARATE, explicitly
        # confirmed action. It never appears for a finished one, and cancelling
        # an export never triggers it — the whole point of the resume machinery
        # is that stopping costs you nothing.
        self.f_delete = tk.Frame(pad, bg=BG_DEEP)
        self.b_delete = tk.Button(
            self.f_delete, text='Delete this incomplete export',
            command=self._delete_incomplete, bg=BG_CARD, fg=TEXT_ERR,
            activebackground=BG_HOVER, relief='flat', bd=0, padx=12, pady=4,
            font=FONT_SMALL)
        self.b_delete.pack(side='left')
        tk.Label(self.f_delete,
                 text='Throws away everything downloaded so far. Only do this if '
                      'you want to start over from nothing.',
                 bg=BG_DEEP, fg=TEXT_DIM, font=FONT_SMALL).pack(side='left', padx=(10, 0))

    # -- widget helpers ---------------------------------------------------
    def _card(self, parent, title):
        outer = tk.Frame(parent, bg=BG_CARD, padx=16, pady=14)
        outer.pack(fill='x', pady=(0, 14))
        tk.Label(outer, text=title, bg=BG_CARD, fg=ACCENT, font=FONT_SMALL,
                 anchor='w').pack(fill='x', pady=(0, 8))
        return outer

    def _entry(self, parent, var, width=40, show=None):
        return tk.Entry(parent, textvariable=var, width=width, bg=BG_FIELD,
                        fg=TEXT_PRI, insertbackground=ACCENT, relief='flat',
                        font=FONT_BODY, show=show, highlightthickness=1,
                        highlightbackground=BORDER, highlightcolor=ACCENT)

    def _button(self, parent, text, cmd, primary=False):
        return tk.Button(parent, text=text, command=cmd,
                         bg=ACCENT if primary else BG_CARD,
                         fg='#000000' if primary else TEXT_PRI,
                         activebackground=ACCENT_DK if primary else BG_HOVER,
                         relief='flat', bd=0, padx=18, pady=7, font=FONT_H2)

    def _check(self, parent, text, var):
        return tk.Checkbutton(parent, text=text, variable=var, bg=BG_CARD,
                              fg=TEXT_PRI, selectcolor=BG_FIELD, relief='flat',
                              activebackground=BG_CARD, activeforeground=ACCENT,
                              font=FONT_SMALL, anchor='w', highlightthickness=0)

    # -- logging ----------------------------------------------------------
    def log(self, message, colour=None):
        tag = {ACCENT: 'accent', TEXT_OK: 'ok', TEXT_WARN: 'warn',
               TEXT_ERR: 'err'}.get(colour)
        for box in (self.log_box,):
            box.config(state='normal')
            box.insert('end', message + '\n', tag or ())
            box.see('end')
            box.config(state='disabled')

    def _write(self, box, message, tag=None):
        box.config(state='normal')
        box.insert('end', message + '\n', tag or ())
        box.config(state='disabled')

    # =====================================================================
    # Actions
    # =====================================================================
    def _key_dialog(self):
        KeySecurityDialog(self)

    def _pick_dest(self):
        d = filedialog.askdirectory(title='Where should your archive go?',
                                    initialdir=self.v_dest.get() or os.path.expanduser('~'))
        if d:
            self.v_dest.set(d)

    def _update_space(self):
        d = self.v_dest.get().strip()
        probe = d
        while probe and not os.path.isdir(probe):
            parent = os.path.dirname(probe)
            if parent == probe:
                break
            probe = parent
        if not probe or not os.path.isdir(probe):
            self.l_space.config(text='', fg=TEXT_DIM)
            return
        try:
            free = shutil.disk_usage(probe).free
        except OSError:
            self.l_space.config(text='', fg=TEXT_DIM)
            return
        msg = f'{human_bytes(free)} free on that drive.'
        colour = TEXT_DIM
        if free < 2 * 1024 ** 3:
            msg += '  That is not much room for a photo archive.'
            colour = TEXT_WARN
        self.l_space.config(text=msg, fg=colour)

    def _on_connect(self):
        url = self.v_url.get().strip()
        key = self.v_key.get().strip()
        if not url or not key:
            messagebox.showerror('Connect', 'A site address and an export key are '
                                            'both needed.', parent=self)
            return
        self.b_connect.config(state='disabled')
        self.l_conn.config(text='Asking the site…', fg=TEXT_DIM)

        def work():
            try:
                client = TyswyClient(url, key, app_version=BUILD_VERSION,
                                     allow_http=bool(self.cfg.get('allow_http')))
                pre = client.preflight()
                self.events.put(('connected', (client, pre)))
            except TyswyError as e:
                self.events.put(('connect_failed', e))
            except Exception as e:                      # noqa: BLE001 - reported
                self.events.put(('connect_failed', e))

        threading.Thread(target=work, daemon=True).start()

    def _on_connected(self, client, pre):
        self.client    = client
        self.preflight = pre
        name = pre.get('site_name') or pre.get('site_url') or 'that site'
        mode = {'photoblog': 'SMACKONEOUT (photoblog)',
                'carousel':  'GRAMOFSMACK (grams)',
                'longform':  'SMACKTALK (longform)'}.get(pre.get('site_mode'),
                                                         pre.get('site_mode') or 'unknown')
        self.l_conn.config(text=f'Connected to {name}.', fg=TEXT_OK)
        self.b_connect.config(state='normal')

        types = pre.get('types') or {}
        interesting = ['images', 'posts', 'pages', 'comments', 'image_comments',
                       'categories', 'albums', 'tags', 'collections',
                       'assets', 'mosaics', 'trigrams', 'blogroll', 'follows']
        lines = [f'{name}   —   {mode}   —   SnapSmack {pre.get("site_version")}', '']
        for t in interesting:
            info = types.get(t) or {}
            if not info.get('supported'):
                continue
            n = int(info.get('count') or 0)
            if n:
                lines.append(f'  {t:<28} {n:>9,}')
        other = sum(int((types.get(t) or {}).get('count') or 0)
                    for t in types if t not in interesting)
        if other:
            lines.append(f'  {"everything else":<28} {other:>9,}')
        lines.append('')
        lines.append('  Media size is not shown because the site would have to walk')
        lines.append('  its own disk to find out, and that is exactly the kind of')
        lines.append('  thing this tool exists to keep off your server.')
        self.l_manifest.config(text='\n'.join(lines), fg=TEXT_PRI)

        self.b_go.config(state='normal')
        self._update_space()
        self._save_config()

    def _save_config(self):
        data = dict(self.cfg)
        data.update({
            'site_url':    self.v_url.get().strip(),
            'api_key':     self.v_key.get().strip(),
            'destination': self.v_dest.get().strip(),
            'include_thumbnails': bool(self.v_thumbs.get()),
            'media_concurrency':  int(self.v_conc.get() or 2),
            'courtesy_wordpress': bool(self.v_wp.get()),
            'compress':           bool(self.v_zip.get()),
        })
        try:
            config.save(data)
            self.cfg = data
        except RuntimeError as e:
            # Vault locked: keep the session going, do not silently downgrade.
            messagebox.showwarning('Settings not saved', str(e), parent=self)

    def _on_start(self):
        dest = self.v_dest.get().strip()
        if not dest:
            messagebox.showerror('Where?', 'Choose a folder for the archive first.',
                                 parent=self)
            return
        if not os.path.isdir(dest):
            try:
                os.makedirs(dest, exist_ok=True)
            except OSError as e:
                messagebox.showerror('Where?', f'That folder cannot be created:\n{e}',
                                     parent=self)
                return
        self._save_config()

        self.cancel_evt = threading.Event()
        self.report = None
        self.log_box.config(state='normal')
        self.log_box.delete('1.0', 'end')
        self.log_box.config(state='disabled')
        self.b_cancel.config(state='normal', text='STOP')
        self._show('progress')
        self._set_title('collect')
        self.log(f'TAKE YOUR SHIT WITH YOU v{BUILD_VERSION}', ACCENT)
        self.log(f'Destination: {dest}')

        options = ExportOptions(
            include_thumbnails=bool(self.v_thumbs.get()),
            media_concurrency=int(self.v_conc.get() or 2),
            courtesy_wordpress=bool(self.v_wp.get()),
            compress=bool(self.v_zip.get()))

        url, key = self.v_url.get().strip(), self.v_key.get().strip()
        allow_http = bool(self.cfg.get('allow_http'))

        def work():
            try:
                engine = ExportEngine(
                    self.client, dest, options=options, app_version=BUILD_VERSION,
                    on_progress=lambda s, m, f: self.events.put(('progress', (s, m, f))),
                    on_log=lambda m: self.events.put(('log', m)),
                    cancel_event=self.cancel_evt,
                    client_factory=lambda: TyswyClient(
                        url, key, app_version=BUILD_VERSION, allow_http=allow_http))
                report = engine.run()
                self.events.put(('finished', report))
            except Cancelled:
                self.events.put(('cancelled', None))
            except Exception as e:                       # noqa: BLE001 - reported
                self.events.put(('failed', (e, traceback.format_exc())))

        self.worker = threading.Thread(target=work, daemon=True)
        self.worker.start()

    def _on_cancel(self):
        if not messagebox.askyesno(
                'Stop the export?',
                'Everything already downloaded and verified is kept. Point the '
                'tool at the same folder later and it carries on from where it '
                'stopped.\n\nStop now?', parent=self):
            return
        self.cancel_evt.set()
        self.b_cancel.config(state='disabled', text='STOPPING…')
        self.log('Stopping after the current file…', TEXT_WARN)

    # -- results ----------------------------------------------------------
    def _on_finished(self, report):
        self.report = report
        self._set_title('finish')
        self._show('done')
        box = self.done_box
        box.config(state='normal')
        box.delete('1.0', 'end')
        box.config(state='disabled')

        if report.complete and not report.warnings:
            self.l_done.config(text='YOUR SHIT IS PACKED.', fg=ACCENT)
            sub = ('Every image. Every scrap of portable data. JSON sidecars '
                   'included. Courtesy import files are in the box.\n\n' + FAREWELL)
        elif report.complete:
            self.l_done.config(text='COMPLETE, WITH WARNINGS.', fg=TEXT_WARN)
            sub = ('Everything expected arrived, and the things worth knowing '
                   'about are listed below. Nothing is missing without being '
                   'named.')
        else:
            self.l_done.config(text='NOT COMPLETE.', fg=TEXT_ERR)
            sub = ('Something expected did not arrive. Nothing has been deleted '
                   '— run the export again against the same folder and it '
                   'will fetch what is missing.')
        self.l_done_sub.config(text=sub)

        w = lambda t, tag=None: self._write(box, t, tag)      # noqa: E731
        w(f'Folder:  {report.root}')
        w(f'Export:  {report.export_uuid}')
        w('')
        w('WHAT CAME ACROSS', 'accent')
        for key in sorted(report.counts):
            if key.startswith('records:'):
                continue
            w(f'  {key:<28} {report.counts[key]:>9,}')
        w('')
        w('PER TABLE — EXPECTED vs EXPORTED', 'accent')
        for t in sorted(report.expected):
            exp = report.expected[t]
            act = report.counts.get(f'records:{t}', 0)
            tag = 'ok' if act == exp else 'err'
            w(f'  {t:<28} {exp:>9,}  vs {act:>9,}', tag)
        w('')
        w('MEDIA', 'accent')
        w(f'  files downloaded             {report.media_files:>9,}')
        w(f'  bytes                        {human_bytes(report.media_bytes):>9}')
        if report.media_missing:
            w(f'  UNAVAILABLE                  {len(report.media_missing):>9,}', 'err')
            for m in report.media_missing[:20]:
                w(f'    {m["source"]} {m["id"]}: {m["reason"]}', 'err')
            if len(report.media_missing) > 20:
                w(f'    …and {len(report.media_missing) - 20} more, all listed '
                  'in verification.json', 'err')
        w('')
        if report.adapters:
            w('COURTESY COUNTER', 'accent')
            for name, a in report.adapters.items():
                items = a.get('items') or {}
                w(f'  {name} ({a.get("format")}): '
                  f'{items.get("posts", 0):,} posts, {items.get("pages", 0):,} pages, '
                  f'{items.get("attachments", 0):,} images, '
                  f'{items.get("comments", 0):,} comments')
                for loss in a.get('losses', []):
                    w(f'    — {loss}', 'warn')
            w('')
        if report.warnings:
            w('WARNINGS', 'accent')
            for x in report.warnings:
                w(f'  — {x}', 'warn')
            w('')
        w('DELIBERATELY NOT EXPORTED', 'accent')
        for x in report.exclusions:
            w(f'  — {x}')

        self.b_zip.config(state='disabled' if report.zip_path else 'normal')
        if report.zip_path:
            self.log(f'Zip: {report.zip_path}', ACCENT)

        self.f_delete.pack_forget()
        if not report.complete:
            self.f_delete.pack(fill='x', pady=(10, 0))

    def _on_failed(self, err, tb):
        self.b_cancel.config(state='disabled')
        self.log('', None)
        self.log('EXPORT STOPPED: ' + str(err), TEXT_ERR)
        safe = ('Nothing has been deleted. Everything already downloaded and '
                'verified is still in the destination folder, and running the '
                'export again against that same folder carries on from there.')
        self.log(safe, TEXT_DIM)
        detail = str(err)
        if isinstance(err, TyswyError) and err.request_id:
            detail += f'\n\nRequest id (for your site log): {err.request_id}'
        messagebox.showerror('The export stopped', f'{detail}\n\n{safe}', parent=self)
        self.log(tb, TEXT_DIM)

    def _open_folder(self):
        if self.report and self.report.root:
            if not open_in_file_manager(self.report.root):
                messagebox.showinfo('Folder', self.report.root, parent=self)

    def _view_report(self):
        if not self.report:
            return
        candidates = [
            os.path.join(self.report.root, 'courtesy', 'wordpress', 'conversion-report.html'),
            os.path.join(self.report.root, 'verification.json'),
            os.path.join(self.report.root, 'logs', 'export.log'),
        ]
        for c in candidates:
            if os.path.exists(c):
                try:
                    webbrowser.open('file:///' + c.replace('\\', '/'))
                    return
                except Exception:
                    pass
        messagebox.showinfo('Report', 'No report file was written.', parent=self)

    def _compress(self):
        if not self.report or not self.report.root:
            return
        self.b_zip.config(state='disabled')

        def work():
            try:
                path = shutil.make_archive(self.report.root.rstrip('\\/'), 'zip',
                                           self.report.root)
                self.events.put(('zipped', path))
            except Exception as e:                       # noqa: BLE001 - reported
                self.events.put(('zip_failed', e))

        threading.Thread(target=work, daemon=True).start()

    def _delete_incomplete(self):
        """Spec 13. Deliberately awkward: two confirmations, and it refuses
        outright on anything that does not carry this tool's own state file, so
        a mistyped destination cannot take a folder of holiday photos with it."""
        if not self.report or not self.report.root:
            return
        root = self.report.root
        if self.report.complete:
            messagebox.showinfo(
                'Nothing to delete',
                'This export is complete. Completed exports are never deleted '
                'from here — move or remove the folder yourself.', parent=self)
            return
        if not os.path.isfile(os.path.join(root, '.tyswy', 'state.json')):
            messagebox.showerror(
                'Refused',
                f'{root} does not look like an export this tool made. Refusing '
                'to delete it.', parent=self)
            return
        if not messagebox.askyesno(
                'Delete incomplete export?',
                f'This permanently deletes:\n\n{root}\n\nincluding every '
                'photograph downloaded so far. You would be starting the export '
                'again from nothing.\n\nYou do NOT need to do this to resume — '
                'just run the export again against the same folder.\n\n'
                'Delete it?', icon='warning', parent=self):
            return
        if not messagebox.askyesno('Really?', 'Last chance. Delete it?',
                                   icon='warning', parent=self):
            return
        try:
            shutil.rmtree(root)
        except OSError as e:
            messagebox.showerror('Delete', f'Could not delete it:\n{e}', parent=self)
            return
        messagebox.showinfo('Deleted', 'The incomplete export has been deleted.',
                            parent=self)
        self._reset()

    def _reset(self):
        self.report = None
        self.f_delete.pack_forget()
        self._set_title('connect')
        self._show('connect')

    # =====================================================================
    # Event pump — the worker never touches a widget.
    # =====================================================================
    def _poll(self):
        try:
            while True:
                kind, payload = self.events.get_nowait()
                if kind == 'log':
                    self.log(payload)
                elif kind == 'progress':
                    stage, message, frac = payload
                    self._set_title(stage)
                    self.l_stage.config(text=STAGE_TITLES.get(stage, stage).title())
                    self.l_detail.config(text=message)
                    if frac is not None:
                        self.bar.config(value=max(0, min(1000, int(frac * 1000))))
                elif kind == 'connected':
                    self._on_connected(*payload)
                elif kind == 'connect_failed':
                    self.b_connect.config(state='normal')
                    self.l_conn.config(text='Could not connect.', fg=TEXT_ERR)
                    messagebox.showerror('Connect', str(payload), parent=self)
                elif kind == 'finished':
                    self._on_finished(payload)
                elif kind == 'cancelled':
                    self.b_cancel.config(state='disabled', text='STOPPED')
                    self.log('Stopped. Your completed downloads are safe. '
                             'Reconnect to resume.', TEXT_WARN)
                    messagebox.showinfo(
                        'Stopped',
                        'Stopped. Your completed downloads are safe.\n\nPoint the '
                        'tool at the same folder to carry on.', parent=self)
                elif kind == 'failed':
                    self._on_failed(*payload)
                elif kind == 'zipped':
                    self.report.zip_path = payload
                    self.log(f'Zip written: {payload}', ACCENT)
                    messagebox.showinfo('Compressed', payload, parent=self)
                elif kind == 'zip_failed':
                    self.b_zip.config(state='normal')
                    messagebox.showerror('Compress', str(payload), parent=self)
        except queue.Empty:
            pass
        self.after(120, self._poll)

    def _on_close(self):
        if self.worker and self.worker.is_alive():
            if not messagebox.askyesno(
                    'Quit?',
                    'An export is running. Quitting stops it — completed '
                    'downloads are kept and the folder can be resumed later.\n\n'
                    'Quit anyway?', parent=self):
                return
            self.cancel_evt.set()
        self.destroy()


def main():
    app = App()
    app.mainloop()


if __name__ == '__main__':
    main()
# ===== SNAPSMACK EOF =====
