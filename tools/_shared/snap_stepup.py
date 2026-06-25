"""
SnapSmack companion tools — shared step-up authorization helper.

Per-user import keys are session continuity, NOT credentials: every import write
requires an ACTIVE, time-boxed authorization window on the server, opened ONLY by
password + TOTP via the tool's `<tool>/authorize` endpoint. This module provides
the reusable client + UI for that hand-off so every tool in the family (FLKR
FCKR, Unzucker, GYSS, SUYB, SYBU, oh-snap) behaves identically.

Two pieces:
  - request_authorization(...)  — stateless HTTP POST to the authorize route.
  - prompt_stepup_dialog(...) / authorize_interactive(...) — a Tkinter modal that
    collects username + password + TOTP and drives the request, re-prompting on
    failure until success or cancel.

Server contract (see core/flkrfckr-api.php flkrfckr/authorize):
  POST api.php?route=<route>   Bearer <api_key>
  body  {username, password, totp_code}
  200 {status:ok, authorized_until:<unix>, window_minutes:<int>}
  401/403 {status:error, message:<why>}
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
from dataclasses import dataclass

import requests


@dataclass
class AuthResult:
    ok:               bool
    message:          str
    authorized_until: int  = 0
    window_minutes:   int  = 0
    needs_enrollment: bool = False   # user has no 2FA enrolled — can't step up yet
    username:         str  = ''      # the username that was used (caller may persist it)


def request_authorization(base_url: str, route: str, api_key: str,
                          username: str, password: str, totp_code: str,
                          timeout: int = 20) -> AuthResult:
    """
    Open a leased import window. Stateless: one POST, Bearer-authenticated by the
    per-user key, body carries the user's password + TOTP. Returns an AuthResult.
    Never raises — network/parse failures come back as ok=False.
    """
    url = base_url.rstrip('/') + '/api.php'
    try:
        resp = requests.post(
            url,
            params={'route': route},
            headers={
                'Authorization': f'Bearer {api_key}',
                'Content-Type':  'application/json',
                'User-Agent':    'snapsmack-tool/1.0',
            },
            data=json.dumps({
                'username':  username,
                'password':  password,
                'totp_code': totp_code,
            }),
            timeout=timeout,
        )
    except requests.RequestException as e:
        return AuthResult(False, f'Connection failed: {e}', username=username)

    try:
        data = resp.json()
    except ValueError:
        return AuthResult(False, f'Unexpected server response ({resp.status_code}).',
                          username=username)

    if resp.status_code == 200 and data.get('status') == 'ok':
        return AuthResult(
            True,
            data.get('message', 'Import authorized.'),
            authorized_until=int(data.get('authorized_until', 0) or 0),
            window_minutes=int(data.get('window_minutes', 0) or 0),
            username=username,
        )

    msg = data.get('message', f'Authorization failed ({resp.status_code}).')
    low = msg.lower()
    needs_enrol = ('two-factor' in low or '2fa' in low or 'enrol' in low or 'enroll' in low)
    return AuthResult(False, msg, needs_enrollment=needs_enrol, username=username)


# ---------------------------------------------------------------------------
# Tkinter step-up dialog (reusable across the tool family)
# ---------------------------------------------------------------------------

# Neutral dark palette so the dialog looks at home in any of the tools.
_BG      = '#1e1e1e'
_CELL    = '#2a2a2a'
_TEXT    = '#e6e6e6'
_DIM     = '#9a9a9a'
_ERR     = '#ff6b6b'
_ACCENT  = '#4da3ff'


def prompt_stepup_dialog(parent, *, site_url: str = '', username_default: str = '',
                         error: str = '', title: str = 'Authorize Import'):
    """
    Modal dialog collecting username, password and authenticator (TOTP) code.
    Returns (username, password, totp_code) or None if cancelled/closed.
    Must be called on the Tk main thread.
    """
    import tkinter as tk

    top = tk.Toplevel(parent)
    top.title(title)
    top.configure(bg=_BG)
    top.resizable(False, False)
    top.transient(parent)
    top.grab_set()

    result = {'val': None}

    def _lbl(text, fg=_TEXT, pad=(12, 2)):
        tk.Label(top, text=text, bg=_BG, fg=fg, anchor='w',
                 font=('Segoe UI', 9)).pack(fill='x', padx=16, pady=pad)

    _lbl('Imports need a fresh password + 2FA check', fg=_TEXT, pad=(14, 0))
    _lbl('Your key keeps you connected, but writing requires step-up auth.',
         fg=_DIM, pad=(0, 6))
    if site_url:
        _lbl(site_url, fg=_ACCENT, pad=(0, 8))

    _lbl('Username')
    v_user = tk.StringVar(value=username_default)
    e_user = tk.Entry(top, textvariable=v_user, bg=_CELL, fg=_TEXT,
                      insertbackground=_TEXT, relief='flat', width=34)
    e_user.pack(padx=16, pady=(0, 6), ipady=3)

    _lbl('Password')
    v_pass = tk.StringVar()
    e_pass = tk.Entry(top, textvariable=v_pass, show='•', bg=_CELL, fg=_TEXT,
                      insertbackground=_TEXT, relief='flat', width=34)
    e_pass.pack(padx=16, pady=(0, 6), ipady=3)

    _lbl('Authenticator code (6 digits)')
    v_totp = tk.StringVar()
    e_totp = tk.Entry(top, textvariable=v_totp, bg=_CELL, fg=_TEXT,
                      insertbackground=_TEXT, relief='flat', width=34)
    e_totp.pack(padx=16, pady=(0, 8), ipady=3)

    lbl_err = tk.Label(top, text=error, bg=_BG, fg=_ERR, anchor='w',
                       wraplength=300, justify='left', font=('Segoe UI', 8))
    lbl_err.pack(fill='x', padx=16, pady=(0, 4))

    def _submit(*_):
        u, p, t = v_user.get().strip(), v_pass.get(), v_totp.get().strip()
        if not u or not p or not t:
            lbl_err.config(text='Username, password and code are all required.')
            return
        result['val'] = (u, p, t)
        top.destroy()

    def _cancel(*_):
        result['val'] = None
        top.destroy()

    btns = tk.Frame(top, bg=_BG)
    btns.pack(fill='x', padx=16, pady=(4, 14))
    tk.Button(btns, text='Cancel', bg=_CELL, fg=_TEXT, relief='flat', bd=0,
              padx=12, pady=4, cursor='hand2', command=_cancel).pack(side='right')
    tk.Button(btns, text='Authorize', bg=_ACCENT, fg='#000000', relief='flat', bd=0,
              padx=14, pady=4, font=('Segoe UI', 9, 'bold'), cursor='hand2',
              command=_submit).pack(side='right', padx=(0, 8))

    top.bind('<Return>', _submit)
    top.bind('<Escape>', _cancel)
    (e_pass if username_default else e_user).focus_set()

    top.update_idletasks()
    try:
        px, py = parent.winfo_rootx(), parent.winfo_rooty()
        pw, ph = parent.winfo_width(), parent.winfo_height()
        w, h = top.winfo_width(), top.winfo_height()
        top.geometry(f'+{px + (pw - w) // 2}+{py + (ph - h) // 3}')
    except Exception:
        pass

    parent.wait_window(top)
    return result['val']


def authorize_interactive(parent, base_url: str, route: str, api_key: str, *,
                          username_default: str = '', title: str = 'Authorize Import',
                          on_status=None) -> AuthResult:
    """
    Loop: prompt for credentials, attempt authorization, re-prompt on failure
    (showing the server's reason) until success or the user cancels. Returns the
    final AuthResult (ok=False with message 'Authorization cancelled.' if the user
    backs out). `on_status(text)` is an optional progress callback. Main thread only.
    """
    error = ''
    user_default = username_default
    while True:
        creds = prompt_stepup_dialog(parent, site_url=base_url,
                                     username_default=user_default,
                                     error=error, title=title)
        if creds is None:
            return AuthResult(False, 'Authorization cancelled.', username=user_default)
        username, password, totp = creds
        user_default = username  # keep what they typed for the retry / for the caller
        if on_status:
            on_status('Authorizing…')
        res = request_authorization(base_url, route, api_key, username, password, totp)
        if res.ok:
            return res
        if res.needs_enrollment:
            # Can't step up without 2FA — no point re-prompting for a code.
            return res
        error = res.message  # re-prompt with the reason shown
# ===== SNAPSMACK EOF =====
