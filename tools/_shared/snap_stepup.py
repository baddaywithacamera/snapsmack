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
from urllib.parse import urlsplit

import requests


# Hosts where plaintext HTTP carries no network risk — there is no path between
# the tool and the server for anyone to sit on.
_LOCAL_HOSTS = {'localhost', '127.0.0.1', '::1', '[::1]'}


def _insecure_reason(base_url: str) -> str:
    """
    Return '' if base_url is safe to send credentials to, otherwise the reason
    it is not.

    This endpoint carries the operator's ACCOUNT PASSWORD and a live TOTP code —
    not an API key. Over plaintext http:// both cross the wire in the clear, and
    a code is replayable for its remaining window. So this is a hard refusal
    rather than the warn-and-confirm that SECAUDIT 039 gave GYSS, which only
    exposes a scoped key that cannot write on its own. See SECAUDIT 040 finding B.
    """
    parts = urlsplit(base_url.strip())
    scheme = parts.scheme.lower()

    if scheme == 'https':
        return ''

    host = (parts.hostname or '').lower()
    if scheme == 'http':
        if host in _LOCAL_HOSTS or host.startswith('127.'):
            return ''      # loopback — nothing to intercept
        return (
            'Refusing to send your password and 2FA code over an unencrypted '
            'http:// connection to ' + (host or 'that address') + '.\n\n'
            'Anyone between you and your server would be able to read both. '
            'Change the site URL to https:// and try again.'
        )

    if scheme == '':
        return ('The site URL needs to start with https:// — for example '
                'https://' + base_url.strip().lstrip('/') + '\n\n'
                'Without it, your password and 2FA code could be sent in the clear.')

    return f'Unsupported site URL scheme "{scheme}://". Use https://.'


def insecure_transport_reason(base_url: str) -> str:
    """
    Public, GUI-free form of the scheme check: '' when the URL is safe to send
    credentials to, otherwise a sentence explaining why it is not.

    Exists for non-GUI callers — a transport layer inside a library module
    cannot pop a dialog, and it should not have to import tkinter to find out
    whether a URL is safe. Those callers REFUSE on a non-empty reason.

    Added by SECAUDIT 042: SUYB posts an account password from
    `backup_engine.SnapSmackSession.login()` and `hub_discovery`, neither of
    which is GUI code, and both of which had no scheme check at all.
    """
    return _insecure_reason(base_url)


@dataclass
class AuthResult:
    ok:               bool
    message:          str
    authorized_until: int  = 0
    window_minutes:   int  = 0
    needs_enrollment: bool = False   # user has no 2FA enrolled — can't step up yet
    username:         str  = ''      # the username that was used (caller may persist it)


def confirm_insecure_transport(parent, base_url: str, *, what: str = 'your API key') -> bool:
    """
    Gate for the tool's NON-credential calls (ping, listing, uploads, record
    writes). Returns True if it is safe to proceed, or if the operator explicitly
    accepted the risk.

    Deliberately weaker than the hard refusal `request_authorization()` applies:
    these calls carry a scoped API key, which cannot write on its own without an
    open step-up window, whereas the authorize endpoint carries the account
    password and a live TOTP code. Same reasoning SECAUDIT 039 used for GYSS —
    warn and confirm for a key, refuse outright for a password.

    Returns True when the URL is https or loopback, without prompting.
    """
    if not _insecure_reason(base_url):
        return True

    from tkinter import messagebox
    return bool(messagebox.askokcancel(
        'Unencrypted connection',
        f'This site URL is not https://, so {what} will be sent across the '
        f'network in the clear:\n\n{base_url.strip()}\n\n'
        'Anyone between you and your server could read it. If this is a live '
        'site, cancel and switch the URL to https://.\n\n'
        'Continue anyway?',
        icon='warning',
        default='cancel',
        parent=parent,
    ))


def request_authorization(base_url: str, route: str, api_key: str,
                          username: str, password: str, totp_code: str,
                          timeout: int = 20) -> AuthResult:
    """
    Open a leased import window. Stateless: one POST, Bearer-authenticated by the
    per-user key, body carries the user's password + TOTP. Returns an AuthResult.
    Never raises — network/parse failures come back as ok=False.

    Refuses outright if the URL would put those credentials on the wire in clear.
    """
    insecure = _insecure_reason(base_url)
    if insecure:
        return AuthResult(False, insecure, username=username)

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
    # Check the destination BEFORE prompting. Refusing after the dialog would
    # mean the operator has already typed their password for a destination we
    # were never going to send it to, and the retry loop below would re-prompt
    # forever against an error no amount of retyping can fix.
    insecure = _insecure_reason(base_url)
    if insecure:
        return AuthResult(False, insecure, username=username_default)

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
