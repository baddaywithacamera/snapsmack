"""
Smack Up Your Backup — profile_manager.py
Blog profile CRUD. One JSON file per blog in profiles/.

SECURITY (SECAUDIT 037, Finding A): the `_enc` fields below are base64, which is
OBFUSCATION, NOT ENCRYPTION — it reverses with one function call. The scoped
`api_key` bearer token is stored verbatim. There is no confidentiality boundary
here: the profiles/ folder holds working FTP, admin, and API credentials and MUST
be treated as secret-equivalent to those passwords. On a portable thumb drive,
whoever holds the drive holds the credentials. Real at-rest protection needs a
passphrase-derived key (a separate, owner-approved build); it is deliberately not
faked here with an app-baked key, which would be theatre. save_profile() tightens
the file to owner-only where the OS supports it (no-op on FAT/Windows).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import base64
import json
import os
import sys
from typing import Dict, List, Optional

import secret_vault
import config as config_module
import sync_manager


# ── Secret sealing (SECAUDIT 037, Finding A) ───────────────────────────────
# When the vault is enabled and unlocked, secrets are encrypted (enc1: tag).
# Otherwise they fall back to the historical encoding so existing profiles keep
# loading unchanged: passwords stay base64, the api_key stays plaintext. The
# on-disk tag (enc1: vs not) is self-describing, so a store can hold a mix during
# migration and every value still decodes correctly.

def _seal_pw(plain: str) -> str:
    if secret_vault.is_enabled():
        if not secret_vault.is_unlocked():
            raise RuntimeError("Credential vault is locked; refusing legacy password write.")
        return secret_vault.encrypt(plain or "")
    return _obfuscate(plain or "")

def _open_pw(blob: str) -> str:
    if secret_vault.is_encrypted(blob):
        if not secret_vault.is_unlocked():
            return ""            # locked — cannot reveal
        try:
            return secret_vault.decrypt(blob)
        except Exception:
            return ""
    return _deobfuscate(blob)    # legacy base64

def _seal_key(plain: str) -> str:
    if secret_vault.is_enabled():
        if not secret_vault.is_unlocked():
            raise RuntimeError("Credential vault is locked; refusing plaintext API-key write.")
        return secret_vault.encrypt(plain or "")
    return plain or ""           # legacy: api_key stored verbatim

def _open_key(blob: str) -> str:
    if secret_vault.is_encrypted(blob):
        if not secret_vault.is_unlocked():
            return ""
        try:
            return secret_vault.decrypt(blob)
        except Exception:
            return ""
    return blob or ""            # legacy plaintext


def _app_dir() -> str:
    """Persistent app directory — next to the .exe when frozen, source dir otherwise.
    Portable app: profiles ride next to the executable, never in %APPDATA%."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


PROFILES_DIR = os.path.join(_app_dir(), "profiles")


def _obfuscate(plain: str) -> str:
    return base64.b64encode(plain.encode()).decode()


def _deobfuscate(blob: str) -> str:
    try:
        return base64.b64decode(blob.encode()).decode()
    except Exception:
        return ""


def _profile_path(name: str) -> str:
    safe = name.replace("/", "_").replace("\\", "_")
    return os.path.join(PROFILES_DIR, f"{safe}.json")


def list_profiles() -> List[str]:
    """Return sorted list of profile display names.

    Only JSONs that are actually profiles (a dict with a non-empty 'name') count.
    Stray JSONs that happen to live in this folder — e.g. a saved
    google-drive-auth.json — are skipped so they can't poison the profile list or
    crash the loader downstream.
    """
    os.makedirs(PROFILES_DIR, exist_ok=True)
    names = []
    for fname in os.listdir(PROFILES_DIR):
        if not fname.endswith(".json"):
            continue
        try:
            with open(os.path.join(PROFILES_DIR, fname)) as f:
                data = json.load(f)
        except Exception:
            continue
        if isinstance(data, dict) and str(data.get("name", "")).strip():
            names.append(data["name"])
    return sorted(names)


def load_profile(name: str) -> Optional[Dict]:
    """Load a profile by display name. Returns dict with plain-text passwords."""
    path = _profile_path(name)
    if not os.path.exists(path):
        # Try scanning all profiles for matching name
        for fname in os.listdir(PROFILES_DIR):
            if fname.endswith(".json"):
                candidate = os.path.join(PROFILES_DIR, fname)
                try:
                    with open(candidate) as f:
                        data = json.load(f)
                    if data.get("name") == name:
                        path = candidate
                        break
                except Exception:
                    pass
        else:
            return None

    with open(path) as f:
        data = json.load(f)

    # A JSON without a 'name' isn't a profile (e.g. a stray creds file dropped in
    # the profiles folder). Return None rather than a half-built dict the UI would
    # choke on (KeyError: 'name' on startup).
    if not (isinstance(data, dict) and str(data.get("name", "")).strip()):
        return None

    # Unseal secrets (vault-aware; falls back to legacy encoding — see _open_*).
    data["ftp_pass"]        = _open_pw(data.get("ftp_pass_enc", ""))
    data["snap_admin_pass"] = _open_pw(data.get("snap_admin_pass_enc", ""))
    data["api_key"]         = _open_key(data.get("api_key", ""))
    return data


def save_profile(profile: Dict) -> None:
    """Save a profile. Obfuscates passwords before writing."""
    os.makedirs(PROFILES_DIR, exist_ok=True)
    data = dict(profile)

    # Seal and remove plain-text secrets (vault-aware — see _seal_*).
    data["ftp_pass_enc"]        = _seal_pw(data.pop("ftp_pass", ""))
    data["snap_admin_pass_enc"] = _seal_pw(data.pop("snap_admin_pass", ""))
    if "api_key" in data:
        data["api_key"] = _seal_key(data.get("api_key", ""))

    path = _profile_path(data["name"])
    _atomic_write_bytes(path, json.dumps(data, indent=2).encode("utf-8"))
    # Owner-only where supported (SECAUDIT 037). No-op on FAT/Windows; the folder
    # itself remains secret-equivalent — see the module SECURITY note.
    try:
        os.chmod(path, 0o600)
    except Exception:
        pass


def delete_profile(name: str) -> None:
    path = _profile_path(name)
    if os.path.exists(path):
        os.remove(path)


def new_profile_template() -> Dict:
    """Return a blank profile with all required keys."""
    return {
        "name":                  "",
        "site_url":              "",
        "transport":             "ftp",            # "ftp" | "sftp" — transport.make_client() keys on this
        "ftp_host":              "",
        "ftp_port":              21,
        "ftp_user":              "",
        "ftp_pass":              "",
        "ftp_remote_dir":        "/",
        "ftp_ssl":               True,
        "ftp_verify_cert":       False,
        "snap_admin_user":       "",
        "snap_admin_pass":       "",
        "api_key":               "",             # scoped 'suyb' key (Bearer); preferred over admin login
        "login_slug":            "snap-in",       # admin-login path (CMS login slug); /login.php 403s on slug-login installs
        "backup_method":         "cloud",         # "ftp" | "cloud" | "local"
        "schedule_enabled":      False,
        "schedule_type":         "daily",        # "daily" | "weekly"
        "schedule_day":          "monday",       # weekday for weekly schedule
        "schedule_time":         "02:00",        # HH:MM 24-hour local time
        "last_scheduled_run":    "",
        "cloud_provider":        "google_drive", # "google_drive" | "none" — GDrive is the default cloud target
        "cloud_credentials_file":"",
        "cloud_folder_id":       "",
        "backup_dir":            "",
        "last_backup_date":      "",
        "pacing_delay":          0,              # Full Send — FTP download doesn't strain a server; throttle per-profile only for fragile shared hosts
        "batch_size":            0,
    }


def duplicate_profile(name: str, new_name: str) -> Optional[Dict]:
    """Duplicate an existing profile under a new name."""
    profile = load_profile(name)
    if profile is None:
        return None
    profile["name"] = new_name
    profile["last_backup_date"] = ""
    save_profile(profile)
    return profile


# ── Encryption lifecycle (SECAUDIT 037, Finding A) ─────────────────────────
# These re-seal EVERY profile so the whole store converges on the current vault
# state. Each loads all secrets into memory first, then flips the vault, then
# rewrites — so no profile is left sealed under a key that no longer exists.

_MIGRATION_JOURNAL = os.path.join(_app_dir(), "vault-migration.json")


def _atomic_write_bytes(path: str, content: bytes) -> None:
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "wb") as f:
        f.write(content)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)
    try:
        os.chmod(path, 0o600)
    except Exception:
        pass


def _sync_jobs() -> List[Dict]:
    return [job for job in (sync_manager.load_job(n) for n in sync_manager.list_jobs()) if job]


def _credential_refs(profiles: List[Dict], jobs: List[Dict]) -> List[str]:
    refs = {str(p.get("cloud_credentials_file", "") or "") for p in profiles}
    for job in jobs:
        refs.add(str(job.get("source_credentials_file", "") or ""))
        refs.add(str(job.get("dest_credentials_file", "") or ""))
    try:
        cfg = config_module.load()
        refs.add(cfg.get("cloud", "credentials_file", fallback=""))
    except Exception:
        pass
    return sorted(ref for ref in refs if ref)


def _token_paths(profiles: List[Dict], jobs: Optional[List[Dict]] = None) -> List[str]:
    found = set()
    for creds in _credential_refs(profiles, jobs or []):
        stem = creds[:-5] if creds.lower().endswith(".json") else creds
        found.update((stem + "_token.json", stem + "_readonly_token.json",
                      stem + "_box_token.json"))
    return sorted(path for path in found if os.path.isfile(path))


def _snapshot(paths: List[str]) -> dict:
    result = {}
    for path in paths:
        if path and os.path.exists(path):
            with open(path, "rb") as f:
                result[os.path.abspath(path)] = base64.b64encode(f.read()).decode("ascii")
    return result


def _write_journal(snapshot: dict, expected_paths: Optional[List[str]] = None) -> None:
    existing = set(snapshot)
    absent = [os.path.abspath(p) for p in (expected_paths or [])
              if p and os.path.abspath(p) not in existing]
    payload = {"version": 1, "state": "rollback-required", "files": snapshot,
               "absent": absent}
    _atomic_write_bytes(_MIGRATION_JOURNAL,
                        json.dumps(payload, indent=2).encode("utf-8"))


def recover_pending_migration() -> bool:
    """Restore pre-migration files before any profiles or tokens are loaded."""
    if not os.path.exists(_MIGRATION_JOURNAL):
        return False
    with open(_MIGRATION_JOURNAL, encoding="utf-8") as f:
        journal = json.load(f)
    if journal.get("state") != "rollback-required":
        raise RuntimeError("Unknown vault migration state; refusing startup.")
    for path, encoded in journal.get("files", {}).items():
        _atomic_write_bytes(path, base64.b64decode(encoded))
    for path in journal.get("absent", []):
        try:
            os.remove(path)
        except FileNotFoundError:
            pass
    os.remove(_MIGRATION_JOURNAL)
    secret_vault.lock()
    return True


def migration_pending() -> bool:
    return os.path.exists(_MIGRATION_JOURNAL)


def _plain_token(path: str) -> str:
    with open(path, encoding="utf-8") as f:
        raw = f.read()
    data = json.loads(raw)
    if isinstance(data, dict) and "suyb_sealed" in data:
        if not secret_vault.is_unlocked():
            raise RuntimeError("Vault is locked; cannot migrate cloud token cache.")
        return secret_vault.decrypt(data["suyb_sealed"])
    return raw


def _sealed_token(plain: str) -> bytes:
    if secret_vault.is_enabled():
        if not secret_vault.is_unlocked():
            raise RuntimeError("Vault is locked; refusing plaintext token migration.")
        return json.dumps({"suyb_sealed": secret_vault.encrypt(plain)}).encode("utf-8")
    return plain.encode("utf-8")


def _serialize_profile(profile: Dict) -> bytes:
    data = dict(profile)
    data["ftp_pass_enc"] = _seal_pw(data.pop("ftp_pass", ""))
    data["snap_admin_pass_enc"] = _seal_pw(data.pop("snap_admin_pass", ""))
    if "api_key" in data:
        data["api_key"] = _seal_key(data.get("api_key", ""))
    return json.dumps(data, indent=2).encode("utf-8")


def _serialize_legacy_job(job: Dict) -> bytes:
    return json.dumps(dict(job), indent=2).encode("utf-8")


def _commit_migration(outputs: dict, snapshot: dict) -> None:
    if not os.path.exists(_MIGRATION_JOURNAL):
        _write_journal(snapshot, list(outputs))
    try:
        for path, content in outputs.items():
            _atomic_write_bytes(path, content)
        os.remove(_MIGRATION_JOURNAL)
    except Exception:
        for path, encoded in snapshot.items():
            _atomic_write_bytes(path, base64.b64decode(encoded))
        try:
            os.remove(_MIGRATION_JOURNAL)
        except FileNotFoundError:
            pass
        raise


def _vault_state():
    return (getattr(secret_vault, "enabled", None),
            getattr(secret_vault, "unlocked", None),
            getattr(secret_vault, "key", None))


def _restore_in_memory_vault(state) -> None:
    # Test doubles expose their state; the production vault is restored from meta.
    if state[0] is not None:
        secret_vault.enabled, secret_vault.unlocked, secret_vault.key = state
    else:
        secret_vault.lock()


def enable_encryption(passphrase: str, store_machine_key: bool = False) -> None:
    """Turn on credential encryption and re-save every profile sealed.
    Raises on empty passphrase, missing crypto backend, or existing vault."""
    loaded = [p for p in (load_profile(n) for n in list_profiles()) if p]
    jobs = _sync_jobs()
    tokens = _token_paths(loaded, jobs)
    token_plain = {p: _plain_token(p) for p in tokens}
    job_paths = [sync_manager._job_path(j["name"]) for j in jobs]
    paths = [_profile_path(p["name"]) for p in loaded] + job_paths + tokens
    meta = getattr(secret_vault, "_meta_path", lambda: "")()
    snapshot = _snapshot(paths + ([meta] if meta else []))
    prior = _vault_state()
    _write_journal(snapshot, paths + ([meta] if meta else []))
    try:
        secret_vault.enable(passphrase, store_machine_key=False)
        outputs = {_profile_path(p["name"]): _serialize_profile(p) for p in loaded}
        outputs.update({sync_manager._job_path(j["name"]): sync_manager._serialize_job(j)
                        for j in jobs})
        outputs.update({p: _sealed_token(text) for p, text in token_plain.items()})
        _commit_migration(outputs, snapshot)
        if store_machine_key:
            secret_vault.store_machine_key_now()
    except Exception:
        if meta and meta not in snapshot and os.path.exists(meta):
            os.remove(meta)
        try:
            os.remove(_MIGRATION_JOURNAL)
        except FileNotFoundError:
            pass
        _restore_in_memory_vault(prior)
        raise

def change_encryption_passphrase(old_passphrase: str, new_passphrase: str) -> bool:
    """Re-key the vault and re-seal every profile under the new passphrase.
    Returns False if the old passphrase is wrong; raises on empty new passphrase."""
    if not new_passphrase:
        raise ValueError("New passphrase must not be empty.")
    if not secret_vault.unlock(old_passphrase):
        return False
    loaded = [p for p in (load_profile(n) for n in list_profiles()) if p]
    jobs = _sync_jobs()
    tokens = _token_paths(loaded, jobs)
    token_plain = {p: _plain_token(p) for p in tokens}
    job_paths = [sync_manager._job_path(j["name"]) for j in jobs]
    paths = [_profile_path(p["name"]) for p in loaded] + job_paths + tokens
    meta = getattr(secret_vault, "_meta_path", lambda: "")()
    snapshot = _snapshot(paths + ([meta] if meta else []))
    prior = _vault_state()
    had_machine_key = secret_vault.has_machine_key()
    _write_journal(snapshot, paths + ([meta] if meta else []))
    try:
        if not secret_vault.change_passphrase(old_passphrase, new_passphrase):
            raise RuntimeError("Vault changed state while preparing re-key.")
        outputs = {_profile_path(p["name"]): _serialize_profile(p) for p in loaded}
        outputs.update({sync_manager._job_path(j["name"]): sync_manager._serialize_job(j)
                        for j in jobs})
        outputs.update({p: _sealed_token(text) for p, text in token_plain.items()})
        _commit_migration(outputs, snapshot)
        if had_machine_key:
            secret_vault.store_machine_key_now()
    except Exception:
        if meta and os.path.abspath(meta) in snapshot:
            _atomic_write_bytes(meta, base64.b64decode(snapshot[os.path.abspath(meta)]))
        _restore_in_memory_vault(prior)
        if prior[0] is None:
            secret_vault.unlock(old_passphrase)
        raise
    return True

def disable_encryption() -> None:
    """Turn off encryption and rewrite every profile in the legacy encoding.
    Requires the vault unlocked (so secrets can be read before the key is dropped)."""
    loaded = [p for p in (load_profile(n) for n in list_profiles()) if p]
    jobs = _sync_jobs()
    tokens = _token_paths(loaded, jobs)
    token_plain = {p: _plain_token(p) for p in tokens}
    job_paths = [sync_manager._job_path(j["name"]) for j in jobs]
    paths = [_profile_path(p["name"]) for p in loaded] + job_paths + tokens
    meta = getattr(secret_vault, "_meta_path", lambda: "")()
    snapshot = _snapshot(paths + ([meta] if meta else []))
    prior = _vault_state()
    _write_journal(snapshot, paths + ([meta] if meta else []))
    outputs = {}
    for p in loaded:
        data = dict(p)
        data["ftp_pass_enc"] = _obfuscate(data.pop("ftp_pass", "") or "")
        data["snap_admin_pass_enc"] = _obfuscate(data.pop("snap_admin_pass", "") or "")
        data["api_key"] = data.get("api_key", "") or ""
        outputs[_profile_path(data["name"])] = json.dumps(data, indent=2).encode("utf-8")
    outputs.update({p: text.encode("utf-8") for p, text in token_plain.items()})
    outputs.update({sync_manager._job_path(j["name"]): _serialize_legacy_job(j)
                    for j in jobs})
    secret_vault.disable()
    try:
        _commit_migration(outputs, snapshot)
        secret_vault.clear_machine_key()
    except Exception:
        if meta and os.path.abspath(meta) in snapshot:
            _atomic_write_bytes(meta, base64.b64decode(snapshot[os.path.abspath(meta)]))
        _restore_in_memory_vault(prior)
        raise
# ===== SNAPSMACK EOF =====
