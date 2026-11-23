"""PROX VOX — where the token comes from, and where it must never come from.

Sean's ruling puts the broker on his desktop; the token it uses lives in
Windows Credential Manager. This module is the only way the broker gets it.

THE POINT IS THE ABSENCE OF FALLBACKS. A loader that tries the keystore and
then "helpfully" falls back to an environment variable or a file beside the
script has no keystore at all -- it has a keystore plus two easier ways in,
and an attacker uses the easiest. So there is exactly one source here, and
when it is empty the answer is an error, not a second attempt somewhere
softer.

This is the same objection I raised in review of the Desktop CLI step-up,
where the device id honoured a SNAPSMACK_DEVICE_ID environment override: a
secret or an identity that the caller can supply by another route is not
protected by the route you meant them to use.

The Credential Manager plumbing itself is NOT reimplemented. tools/_shared/
snap_native_creds.py already does CredReadW through ctypes and is used by the
other desktop tools; a second copy would be a second thing to get wrong.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

_SHARED = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "_shared")
if os.path.isdir(_SHARED) and _SHARED not in sys.path:
    sys.path.insert(0, os.path.abspath(_SHARED))

# The identity the token is filed under. "proxvox" is the site key and
# "api_token" the kind, so it sits beside the other desktop credentials
# instead of inventing a storage convention of its own.
CRED_SITE = "proxvox"
CRED_KIND = "api_token"
CONTAINER_SECRET_KINDS = frozenset({
    "ct102_ssh", "ct106_ssh", "mysql_ro", "pve_host_ssh",
})


class NoToken(Exception):
    """The token is not in the keystore. There is no second place to look."""


def load_token(reader=None) -> str:
    """Return the Proxmox API token secret from Windows Credential Manager.

    `reader` exists so the test can drive this without writing a credential
    to the machine running the suite. It is injected, never read from the
    environment: a test seam that an attacker can also use is not a seam, it
    is the fallback this module exists to refuse.
    """
    if reader is None:
        try:
            import snap_native_creds
        except ImportError as err:                        # pragma: no cover
            raise NoToken(
                "Cannot reach the shared credential helper (%s). PROX VOX will "
                "not read a token from anywhere else." % err
            )
        reader = snap_native_creds.get_site

    secret = reader(CRED_SITE, CRED_KIND) or ""
    secret = secret.strip()
    if "\x00" in secret:
        raise NoToken(
            "The PROX VOX token was saved with the Windows Credential Manager "
            "GUI and contains embedded NUL characters. Re-save it with "
            "snap_native_creds; PROX VOX will not use the damaged value."
        )
    if not secret:
        raise NoToken(
            "No PROX VOX token in Windows Credential Manager under "
            "'%s:%s'. Store it there; PROX VOX does not read tokens from "
            "files, arguments or the environment." % (CRED_SITE, CRED_KIND)
        )
    return secret


def load_container_secret(kind: str, reader=None) -> str:
    """Load one P1b secret from Credential Manager and nowhere else."""
    key = str(kind or "").strip()
    if key not in CONTAINER_SECRET_KINDS:
        raise NoToken("Unknown PROX VOX container credential kind %r." % key)
    if reader is None:
        try:
            import snap_native_creds
        except ImportError as err:                        # pragma: no cover
            raise NoToken("Cannot reach snap_native_creds (%s)." % err)
        reader = snap_native_creds.get_site
    secret = str(reader(CRED_SITE, key) or "").strip()
    if not secret or "\x00" in secret:
        raise NoToken(
            "No usable PROX VOX %s credential exists in Windows Credential "
            "Manager. There is no file, argument, or environment fallback." % key
        )
    return secret


def save_container_secret(kind: str, secret: str, writer=None) -> None:
    """Store an owner-entered P1b secret through snap_native_creds.set_site."""
    key = str(kind or "").strip()
    value = str(secret or "")
    if key not in CONTAINER_SECRET_KINDS or not value or "\x00" in value:
        raise NoToken("A known credential kind and non-empty secret are required.")
    if writer is None:
        try:
            import snap_native_creds
        except ImportError as err:                        # pragma: no cover
            raise NoToken("Cannot reach snap_native_creds (%s)." % err)
        writer = snap_native_creds.set_site
    if writer(CRED_SITE, key, value) is not True:
        raise NoToken("Windows Credential Manager refused the PROX VOX credential.")

# ===== SNAPSMACK EOF =====
