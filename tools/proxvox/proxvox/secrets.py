"""PROX VOX — the one place secrets are named. (SS-1660, SS-1661)

Two of Sean's rulings live here, and they are here TOGETHER because both are
lists of "this is a secret" and a list kept in two places drifts:

  2026-11-28 14:56  "blank" — a command written to the audit log must have its
                    secret VALUES blanked before anything is written. The
                    sha256 of the ORIGINAL command is kept, so the entry can
                    still be proved untampered even though the text shown has
                    been redacted.

  2026-11-28 15:02  "i agree" — the COMPLETE read blocklist, four entries, and
                    nothing else is ever added to it. The rule behind it, in
                    his words via the PM: block only what would let an AI
                    authorize itself without Sean's TOTP, or impersonate him
                    to Proxmox. Everything else stays readable inside the
                    window, INCLUDING password hashes and private keys, which
                    is his 23 November ruling and is deliberate.

WHY REDACTION IS NOT THE SAME AS BLOCKING. A blocked read never happens. A
redacted command DOES happen -- Sean typed it and meant it -- and what is
withheld is only the copy kept in the log. The command still runs in full.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import re

#: What replaces a secret value in a logged command. Deliberately not empty:
#: an entry reading "password=" is ambiguous about whether there WAS one.
REDACTED = "[REDACTED]"

# ── the command-log redaction patterns (Sean, 14:56) ─────────────────────────
#
# Each pattern captures the KEY in group 1 and the VALUE in group 2, so the
# key survives into the log and only the value goes. A reader can still see
# that a password was supplied, which is part of what makes the log useful.
#
# ORDER MATTERS: the long forms come before the short ones, so --password=x is
# not first mangled by the -p rule.
_PATTERNS = [
    # mysql --password=secret / --password secret
    (re.compile(r'(--password[=\s]+)(\S+)', re.I), 2),
    # mysql -psecret  (no space, which is the form that actually gets typed)
    (re.compile(r'(\s-p)(?!\s)(\S+)'), 2),
    # password=secret, passwd=secret, pwd=secret, also with : or spaces
    (re.compile(r'\b(pass(?:word|wd)?|pwd)(\s*[=:]\s*)(\S+)', re.I), 3),
    # SQL: IDENTIFIED BY 'secret'
    (re.compile(r"(IDENTIFIED\s+BY\s+)('[^']*'|\"[^\"]*\"|\S+)", re.I), 2),
    # An Authorization header, scheme kept and credential blanked.
    #
    # ONE PATTERN, NOT TWO, and the first version had two: a Bearer rule and a
    # separate Authorization rule. The Authorization rule then matched the
    # ALREADY-REDACTED text and swallowed the closing quote of the shell
    # argument, so the logged command came out malformed. Measured on
    #   curl -H "Authorization: Bearer abc" https://x/y
    # which redacted correctly and then lost its quote.
    #
    # A single-token Authorization rule is worse, not better: on
    #   Authorization: Basic dXNlcjpwYXNz
    # it would blank the word "Basic" and leave the credential in the log. So
    # the scheme is an optional group that is KEPT, and the credential after
    # it is what goes.
    (re.compile(r"""(Authorization\s*:\s*)(Bearer|Basic|Token|PVEAPIToken)?(\s*)([^\s"']+)""", re.I), 4),
    # A bare Bearer token with no header name in front of it.
    (re.compile(r"""(Bearer\s+)([^\s"']+)""", re.I), 2),
    # Proxmox API tokens
    (re.compile(r'(PVEAPIToken\s*[=:]\s*)(\S+)', re.I), 2),
    # The Proxmox auth cookie, which IS a usable credential
    (re.compile(r'(PVEAuthCookie\s*[=:]\s*)(\S+)', re.I), 2),
]


def redact_command(text: str) -> str:
    """Blank the secret VALUES in a command, keeping the keys readable.

    Sean's 14:56 ruling. The command itself is unaffected -- this is only what
    gets written to the audit buffer.

    NOT A GUARANTEE, AND SAYING SO IS PART OF THE JOB. This blanks the forms
    that get typed, which is what the ruling asked for. It cannot know that
    `mysql -e "$(cat /tmp/x)"` carries a password, or that a positional
    argument to somebody's own script is one. The sha256 of the ORIGINAL is
    kept beside the redacted text, so the record is still provable; it is not
    a claim that no secret can ever reach the log.
    """
    out = str(text or "")
    for pattern, value_group in _PATTERNS:
        def _blank(match, _g=value_group):
            prefix = "".join(match.group(i) or "" for i in range(1, _g))
            return prefix + REDACTED
        out = pattern.sub(_blank, out)
    return out


# ── the read blocklist (Sean, 15:02, COMPLETE) ───────────────────────────────
#
# FOUR ENTRIES. Sean said "it is the COMPLETE list. Nothing else gets added."
# If something later looks like it belongs here, that is a conversation with
# him, not an edit to this tuple.
#
# 1-3 are what let somebody authorize as him or forge a ticket.
# 4 is PROX VOX's own state: without it the tool could read, and then extend
#   or forge, its own window -- which is the one thing the TOTP exists to stop.
BLOCKED_READ_PATHS = (
    "/etc/pve/priv/tfa.cfg",
    "/etc/pve/priv/authkey.key",
    "/etc/pve/priv/token.cfg",
)

#: Where PROX VOX keeps its own session and grant state. Entry 4 of the list.
#: A prefix rather than a filename, because the state is several files and a
#: new one must be covered the day it is added.
BLOCKED_READ_PREFIXES = (
    "/var/lib/proxvox/",
    "/run/proxvox/",
)


def blocked_read_reason(path: str) -> str:
    """Why this path may not be read, or '' if it may.

    Matched on the NORMALISED path, so ../ and // and a trailing slash cannot
    walk around the list. Checked as a path, not as a substring of a command:
    the caller decides what it is about to read and asks about that.
    """
    raw = str(path or "").strip()
    if raw == "":
        return ""
    # Normalise separators and resolve . and .. textually. Not realpath():
    # this has to answer for a path on the SERVER, which is not this machine's
    # filesystem, so asking the local OS would be the wrong question.
    unified = raw.replace("\\", "/")
    while "//" in unified:
        unified = unified.replace("//", "/")
    parts = []
    for segment in unified.split("/"):
        if segment in ("", "."):
            continue
        if segment == "..":
            if parts:
                parts.pop()
            continue
        parts.append(segment)
    normalised = "/" + "/".join(parts)

    for blocked in BLOCKED_READ_PATHS:
        if normalised == blocked:
            return ("%s holds what would let something authorize as Sean or "
                    "forge a Proxmox ticket. Blocked by his 2026-11-28 15:02 "
                    "ruling." % blocked)
    for prefix in BLOCKED_READ_PREFIXES:
        if normalised == prefix.rstrip("/") or normalised.startswith(prefix):
            return ("%s is PROX VOX's own session and grant state. Reading it "
                    "would let the tool extend or forge its own window, which "
                    "is the one thing the TOTP exists to prevent. Blocked by "
                    "Sean's 2026-11-28 15:02 ruling." % prefix)
    return ""

# ===== SNAPSMACK EOF =====
