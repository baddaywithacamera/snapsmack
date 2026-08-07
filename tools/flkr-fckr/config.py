"""
FLKR FCKR — config.py
Reads and writes flkrfckr.ini next to the executable.
Forked from tools/unzucker/config.py.

API key at rest (SECAUDIT 040, finding A):

  - Encryption ON  — the key is sealed with the shared credential vault
    (tools/_shared/snap_vault.py: scrypt-derived master key + Fernet). The
    passphrase is never stored, so the folder alone is not enough to read it.
  - Encryption OFF — the key is base64, exactly as before. That is an ENCODING,
    not encryption: it reverses with one function call. Kept only so existing
    installs keep working and so the tool still runs where `cryptography` is
    unavailable.

Both forms are readable at load time, so turning encryption on or off never
strands a saved key. Nothing here ever writes the passphrase to disk.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import base64
import configparser
import os
import sys

# Shared credential vault (tools/_shared/snap_vault.py). Same bootstrap as
# image_prep's snap_thumbs: dev finds it one dir up in _shared/; the frozen exe
# bundles it (build.bat: --paths ..\_shared --hidden-import snap_vault).
_SHARED_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '_shared')
if os.path.isdir(_SHARED_DIR) and _SHARED_DIR not in sys.path:
    sys.path.insert(0, _SHARED_DIR)
try:
    import snap_vault
    _VAULT_OK = True
except Exception:
    snap_vault = None
    _VAULT_OK = False


def vault_available() -> bool:
    """True if the vault module imported AND its crypto backend is present."""
    return bool(_VAULT_OK and snap_vault.crypto_available())


def _vault_dir() -> str:
    """vault.meta lives beside flkrfckr.ini — i.e. beside the exe."""
    return os.path.dirname(_config_path())


def init_vault() -> None:
    """Bind the shared vault to this tool. Safe to call more than once."""
    if _VAULT_OK:
        snap_vault.init('FlkrFckr', _vault_dir())


def _config_path() -> str:
    """Return the path to flkrfckr.ini next to the exe (or script in dev)."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'flkrfckr.ini')


def _decode_pw(raw: str) -> str:
    """
    Read a stored secret in EITHER form — sealed (vault) or legacy base64.

    Returns '' when the value is sealed but the vault is locked. That is not an
    error: the GUI unlocks first and re-reads. Returning '' rather than raising
    keeps every other setting loadable while the vault is still locked.
    """
    if not raw:
        return ''
    if _VAULT_OK and snap_vault.is_encrypted(raw):
        if not snap_vault.is_unlocked():
            return ''
        try:
            return snap_vault.decrypt(raw)
        except Exception:
            return ''
    try:
        return base64.b64decode(raw.encode()).decode()
    except Exception:
        return ''


def _encode_pw(plain: str) -> str:
    """
    Seal a secret when the vault is on and open; otherwise fall back to base64.

    Fails CLOSED in one specific case: if a vault exists but is locked, we must
    not silently write the key back out in base64 — that would quietly downgrade
    an encrypted store to an obfuscated one behind the operator's back. Callers
    guard against this by not saving while locked; the exception is the backstop.
    """
    if not plain:
        return ''
    if _VAULT_OK and snap_vault.is_enabled():
        if not snap_vault.is_unlocked():
            raise RuntimeError(
                'The credential vault is locked — refusing to re-save the API key '
                'in the weaker base64 form. Unlock first.')
        return snap_vault.encrypt(plain)
    return base64.b64encode(plain.encode()).decode()


def is_key_sealed() -> bool:
    """True if the stored API key is currently sealed by the vault."""
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())
    raw = cfg.get('site', 'api_key', fallback='')
    return bool(_VAULT_OK and snap_vault.is_encrypted(raw))


def load() -> dict:
    """Load config from disk. Returns a dict with all settings."""
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())

    return {
        # SnapSmack site
        'site_url':         cfg.get('site', 'url',          fallback=''),
        'api_key':          _decode_pw(cfg.get('site', 'api_key', fallback='')),
        'auth_username':    cfg.get('site', 'auth_username', fallback=''),
        # Username prefilled in the step-up dialog. Convenience only — the
        # password and TOTP code are NEVER stored.

        # Import
        'export_folder':    cfg.get('import', 'export_folder', fallback=''),
        'throttle_delay':   cfg.getfloat('import', 'throttle_delay', fallback=1.0),
        'offpeak_only':     cfg.getboolean('import', 'offpeak_only', fallback=False),
        'peak_start':       cfg.getint('import', 'peak_start', fallback=9),
        'peak_end':         cfg.getint('import', 'peak_end',   fallback=23),

        # Defaults
        'private_status':   cfg.get('defaults', 'private_status', fallback='draft'),
        # 'published' or 'draft' — what to do with photos marked private on Flickr
        'unalbumed_action': cfg.get('defaults', 'unalbumed_action', fallback='feed'),
        # 'feed' (import unalbumed to main feed only) or 'default_album'
        'default_album':    cfg.get('defaults', 'default_album', fallback=''),
        # Name of album to assign unalbumed photos when unalbumed_action='default_album'
    }


def save(data: dict) -> None:
    """Write config to disk."""
    cfg = configparser.ConfigParser()

    cfg['site'] = {
        'url':           data.get('site_url', ''),
        'api_key':       _encode_pw(data.get('api_key', '')),
        'auth_username': data.get('auth_username', ''),
    }

    cfg['import'] = {
        'export_folder': data.get('export_folder', ''),
        'throttle_delay': str(data.get('throttle_delay', 1.0)),
        'offpeak_only':   str(data.get('offpeak_only', False)),
        'peak_start':     str(data.get('peak_start', 9)),
        'peak_end':       str(data.get('peak_end', 23)),
    }

    cfg['defaults'] = {
        'private_status':   data.get('private_status', 'draft'),
        'unalbumed_action': data.get('unalbumed_action', 'feed'),
        'default_album':    data.get('default_album', ''),
    }

    path = _config_path()
    with open(path, 'w') as f:
        cfg.write(f)

    # SECAUDIT 040 finding A (floor) — this file holds the scoped API key. Make it
    # owner-only so it is not readable by other accounts on a shared machine.
    # Best-effort and deliberately swallowed: chmod is a no-op on FAT and only
    # partially meaningful on Windows/NTFS, and a permissions hiccup must never
    # cost the operator their settings. Same treatment SUYB 0.7.18 applied.
    #
    # This does NOT make the key confidential at rest — it is still base64, which
    # is an encoding, not encryption. The real fix is the vault port; this is the
    # floor, not the ceiling.
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass


# ---------------------------------------------------------------------------
# Encryption lifecycle
#
# Ordering is the whole game here: the plaintext key must be in hand BEFORE the
# vault state changes, so a failure part-way through can never leave a key that
# nothing can read. Both directions are therefore read → switch → write.
# ---------------------------------------------------------------------------

def enable_encryption(passphrase: str, remember_on_this_machine: bool = False) -> None:
    """
    Turn encryption on and re-seal the stored API key.

    Raises RuntimeError if the vault is unavailable or already enabled, and
    ValueError on an empty passphrase (both surfaced by the caller).
    """
    if not vault_available():
        raise RuntimeError(
            'Encryption is unavailable — the "cryptography" package is not installed '
            'in this build.')
    init_vault()
    if snap_vault.is_enabled():
        raise RuntimeError('Encryption is already on.')

    data = load()                       # read the key while it is still legacy
    snap_vault.enable(passphrase, store_machine_key=remember_on_this_machine)
    save(data)                          # rewrites it sealed


def disable_encryption() -> None:
    """
    Turn encryption off and rewrite the API key in legacy base64 form.

    Requires the vault unlocked — otherwise the key could not be read to be
    rewritten, and disabling would strand it.
    """
    if not (_VAULT_OK and snap_vault.is_enabled()):
        return
    if not snap_vault.is_unlocked():
        raise RuntimeError('Unlock first — the key has to be readable to be rewritten.')

    data = load()                       # decrypt while still unlocked
    snap_vault.disable()                # removes vault.meta + any machine key
    save(data)                          # rewrites it base64


def change_passphrase(old_passphrase: str, new_passphrase: str) -> bool:
    """
    Re-key the vault and re-seal the API key under the new passphrase. Returns
    False if the old passphrase is wrong.
    """
    if not (_VAULT_OK and snap_vault.is_enabled()):
        raise RuntimeError('Encryption is not on.')
    if not snap_vault.unlock(old_passphrase):
        return False

    data = load()                       # read under the OLD key
    if not snap_vault.change_passphrase(old_passphrase, new_passphrase):
        return False
    save(data)                          # re-seal under the NEW key
    return True
# ===== SNAPSMACK EOF =====
