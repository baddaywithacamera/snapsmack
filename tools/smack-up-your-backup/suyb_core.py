"""
Smack Up Your Backup — suyb_core.py

Pure orchestration glue, factored out of the tkinter window (main.py) so the
Linux Chrome/Blink port (linux/app.py) can drive the SAME backup logic without
importing tkinter. Nothing here draws a window; every function returns plain
data or raises. The real work still lives in the tool's existing engine modules
(backup_engine, restore_engine, audit_engine, coverage_engine, cloud_sync_engine,
hub_discovery, cloud_client, profile_manager, sync_manager, os_schedule, ...).

WHY THIS EXISTS
    main.py's tab classes tangle the engine wiring (build a BackupEngine from a
    profile + global cloud config, run it on a thread, feed progress to widgets)
    with tkinter. The PORT-GUIDE rule is: split the logic into plain functions.
    This module is that split. The tkinter App and the Blink app can both call it.

LONG-RUNNING WORK
    Backups / restores / audits / syncs run on a background thread and report
    progress through a small in-process job registry. The web page starts a job
    (returns a job_id), then polls job_status(job_id, since) for new log lines,
    percent, and the final result — the same shape the tkinter message-pump used,
    just pulled instead of pushed.

SHARED-LIBRARY CONTRACT
    Credentials and the hub key come from The Hub's shared store via config.py
    (config.shared_cred → snap_creds). This module NEVER introduces a private
    cred store — it reuses config.resolve_file / credential_store / secret_vault
    exactly as the Windows build does, so all state lands under C:\\snapsmack
    (or ~/snapsmack on Linux, set by snap_blink.App).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys
import threading
import time
import uuid
from dataclasses import asdict, is_dataclass
from datetime import datetime, timezone

# Make the shared library importable (config.py also does this, but be explicit
# so a direct `import suyb_core` works from any launcher).
_HERE = os.path.dirname(os.path.abspath(__file__))
_SHARED = os.path.join(_HERE, "..", "_shared")
if os.path.isdir(_SHARED) and _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

# ── the tool's real work (no tkinter in any of these) ────────────────────────
import config as cfg_module
import profile_manager
import credential_store as cred_store
import secret_vault
import sync_manager
import os_schedule
import cloud_client
import cloud_manifest
import hub_discovery
import manifest_reader
import transport
from backup_engine import BackupEngine, SnapSmackSession
from restore_engine import RestoreEngine
from audit_engine import AuditEngine
from coverage_engine import CoverageEngine, DedupeEngine, OVER_BACKED


# ─────────────────────────────────────────────────────────────────────────────
# Job registry — one entry per long-running engine run.
# ─────────────────────────────────────────────────────────────────────────────
_jobs = {}
_jobs_lock = threading.Lock()

# Last coverage report per profile, kept in memory so a follow-up de-dupe run
# has the object to work from (mirrors the tkinter tab holding _coverage_report).
_last_coverage = {}


def _new_job():
    jid = uuid.uuid4().hex[:12]
    with _jobs_lock:
        _jobs[jid] = {
            "id": jid,
            "stage": "",
            "pct": 0.0,
            "log": [],
            "done": False,
            "result": None,
            "error": None,
            "ask": None,        # a pending abort/continue question for the page
            "stats": None,      # (files_done, files_total, files_failed, ...)
            "engine": None,     # the live engine object, for cancel / prompt_continue
        }
    return jid


def _job(jid):
    with _jobs_lock:
        j = _jobs.get(jid)
    if j is None:
        raise ValueError("Unknown job id: %s" % jid)
    return j


def _log(jid, msg):
    j = _job(jid)
    with _jobs_lock:
        j["log"].append(str(msg))


def _progress(jid, msg, pct):
    j = _job(jid)
    with _jobs_lock:
        j["stage"] = str(msg)
        try:
            j["pct"] = float(pct)
        except (TypeError, ValueError):
            pass


def _finish(jid, result=None, error=None):
    j = _job(jid)
    with _jobs_lock:
        j["done"] = True
        j["result"] = result
        j["error"] = error
        j["engine"] = None


def job_status(job_id, since=0):
    """Poll one job. `since` is the log index already seen; only newer lines are
    returned along with the next cursor. Returns a JSON-serialisable snapshot."""
    j = _job(job_id)
    with _jobs_lock:
        try:
            since = int(since)
        except (TypeError, ValueError):
            since = 0
        log_slice = j["log"][since:]
        return {
            "id": j["id"],
            "stage": j["stage"],
            "pct": j["pct"],
            "done": j["done"],
            "result": j["result"],
            "error": j["error"],
            "ask": j["ask"],
            "stats": j["stats"],
            "log": list(log_slice),
            "log_next": since + len(log_slice),
        }


def cancel_job(job_id):
    """Ask the engine behind a job to stop. Safe to call on a finished job."""
    j = _job(job_id)
    eng = j.get("engine")
    if eng is not None and hasattr(eng, "cancel"):
        eng.cancel()
    return {"ok": True}


def resolve_ask(job_id, cont):
    """Answer a pending abort/continue question raised by a backup/sync failure.
    cont=True → continue anyway; cont=False → abort the run."""
    j = _job(job_id)
    eng = j.get("engine")
    with _jobs_lock:
        j["ask"] = None
    if eng is not None:
        if cont and hasattr(eng, "prompt_continue"):
            eng.prompt_continue()
        elif hasattr(eng, "cancel"):
            eng.cancel()
    return {"ok": True}


def _serialise(obj):
    """dataclass (AuditReport / CoverageReport / DedupeResult) → plain dict."""
    if is_dataclass(obj):
        return asdict(obj)
    return obj


# ─────────────────────────────────────────────────────────────────────────────
# Global config helpers
# ─────────────────────────────────────────────────────────────────────────────
def _global_cloud_from_cfg(cfg=None):
    """Mirror App.global_cloud_config() / headless._global_cloud_from_cfg."""
    cfg = cfg or cfg_module.load()
    return {
        "cloud_provider":         cfg.get("cloud", "provider", fallback="none"),
        "cloud_credentials_file": cfg.get("cloud", "credentials_file", fallback=""),
        "cloud_folder_id":        cfg.get("cloud", "folder_id", fallback=""),
    }


def _global_config_dict(cfg=None):
    """Whole config.ini as a plain dict, bundled into backups (BackupTab._global_config_dict)."""
    cfg = cfg or cfg_module.load()
    return {section: dict(cfg[section]) for section in cfg.sections()}


# ─────────────────────────────────────────────────────────────────────────────
# App state / profiles (the header controls + first load)
# ─────────────────────────────────────────────────────────────────────────────
def vault_status():
    """Credential-vault gate state (mirror App._gate_encryption_unlock inputs)."""
    return {
        "enabled":  secret_vault.is_enabled(),
        "unlocked": secret_vault.is_unlocked(),
        "crypto":   secret_vault.crypto_available(),
        "keychain": secret_vault.keychain_available(),
        "machine_key": secret_vault.has_machine_key(),
    }


def unlock_vault(passphrase):
    """Modal startup unlock. Returns True on success (App gate equivalent)."""
    if not secret_vault.is_enabled():
        return True
    return bool(secret_vault.unlock(passphrase or ""))


def load_state():
    """Everything the page needs on open."""
    # Recover an interrupted credential-vault migration before reading profiles,
    # exactly as App.__init__ does.
    try:
        profile_manager.recover_pending_migration()
    except Exception:
        pass
    cfg = cfg_module.load()
    return {
        "version": _BUILD_VERSION,
        "profiles": profile_manager.list_profiles(),
        "last_profile": cfg.get("app", "last_profile", fallback=""),
        "global_cloud": _global_cloud_from_cfg(cfg),
        "pacing": {
            "transfer_delay": cfg.get("pacing", "transfer_delay", fallback="2"),
            "batch_size":     cfg.get("pacing", "batch_size", fallback="0"),
        },
        "hub": {
            "url": cfg_module.shared_cred("hub_url"),
            "has_key": bool(cfg_module.shared_cred("hub_key")),
        },
        "vault": vault_status(),
        "schedule": os_schedule.schedule_state(),
        "sync_jobs": sync_manager.list_jobs(),
        "creds": cred_store.load(),
    }


_BUILD_VERSION = "0.7.27"   # keep in step with main.BUILD_VERSION


def list_profiles():
    return profile_manager.list_profiles()


def get_profile(name):
    p = profile_manager.load_profile(name)
    if not p:
        raise ValueError("Profile not found: %s" % name)
    return p


def new_profile_template():
    return profile_manager.new_profile_template()


def save_profile(profile):
    name = (profile.get("name") or "").strip()
    if not name:
        raise ValueError("Blog name is required.")
    # Coerce the int fields the tkinter Save did (ftp_port, pacing_delay, batch_size).
    for key in ("ftp_port", "pacing_delay", "batch_size"):
        if key in profile:
            try:
                profile[key] = int(profile[key])
            except (TypeError, ValueError):
                pass
    profile_manager.save_profile(profile)
    return {"ok": True, "name": name}


def delete_profile(name):
    profile_manager.delete_profile(name)
    remaining = profile_manager.list_profiles()
    return {"ok": True, "remaining": remaining}


def duplicate_profile(name, new_name=""):
    new_name = new_name or ("%s (copy)" % name)
    profile_manager.duplicate_profile(name, new_name)
    return {"ok": True, "name": new_name}


def select_profile(name):
    """Persist the last-used profile (App._cfg app.last_profile)."""
    cfg = cfg_module.load()
    if not cfg.has_section("app"):
        cfg.add_section("app")
    cfg.set("app", "last_profile", name or "")
    cfg_module.save(cfg)
    return {"ok": True}


# ─────────────────────────────────────────────────────────────────────────────
# Connection tests (ProfileDialog / SettingsTab)
# ─────────────────────────────────────────────────────────────────────────────
def test_login(profile):
    """Admin/API login test — Bearer key if set, else form login (main._test_login)."""
    url = str(profile.get("site_url", "")).strip().rstrip("/")
    user = str(profile.get("snap_admin_user", "")).strip()
    pw = str(profile.get("snap_admin_pass", ""))
    api_key = str(profile.get("api_key", "")).strip()
    slug = (str(profile.get("login_slug", "snap-in")).strip().strip("/") or "snap-in")
    if not url or (not api_key and (not user or not pw)):
        return "Need URL + API key (or admin username & password)"
    try:
        sess = SnapSmackSession(url, api_key=api_key, login_slug=slug)
        if api_key:
            r = sess.session.get("%s/suyb-export.php?type=schema" % url,
                                 timeout=15, allow_redirects=False, stream=True)
            code = r.status_code
            r.close()
            if code == 200:
                return "\u2713 API key valid"
            if code in (401, 403):
                return "\u26a0 API key rejected (HTTP %d)" % code
            if code in (301, 302, 303, 307, 308):
                return "\u26a0 API key not accepted — redirected to login"
            return "\u26a0 HTTP %d" % code
        sess.login(user, pw)
        return "\u2713 Login successful"
    except Exception as e:
        return "\u2717 %s" % e


def test_conn(profile):
    """FTP/SFTP connection test (main._test_conn)."""
    host = str(profile.get("ftp_host", "")).strip()
    if not host:
        return "Fill in the host first"
    is_sftp = str(profile.get("transport", "ftp")).lower() == "sftp"
    proto = "SFTP" if is_sftp else "FTP"
    try:
        port = int(profile.get("ftp_port") or (22 if is_sftp else 21))
    except (ValueError, TypeError):
        port = 22 if is_sftp else 21
    try:
        client = transport.make_client(profile, transfer_delay=0)
        client.connect()
        client.disconnect()
        return "\u2713 Connected to %s (%s)" % (host, proto)
    except Exception as e:
        return "\u2717 %s:%d — %s" % (host, port, e)


def test_cloud(profile):
    """Cloud credentials test (main._test_cloud). Returns {msg, folder_id}."""
    creds = str(profile.get("cloud_credentials_file", "")).strip()
    gc = _global_cloud_from_cfg()
    if not creds and not gc.get("cloud_credentials_file"):
        return {"msg": "Set the cloud credentials JSON first", "folder_id": ""}
    if creds and not os.path.isfile(creds):
        if creds.endswith("apps.googleusercontent.com"):
            return {"msg": "That's the client ID — Browse to the downloaded "
                           "client_secret_*.json instead.", "folder_id": ""}
        return {"msg": "Credentials file not found", "folder_id": ""}
    folder = ""
    try:
        client = cloud_client.get_cloud_client(profile, global_cloud=gc)
        if not client:
            return {"msg": "Pick a provider + set credentials first", "folder_id": ""}
        if not hasattr(client, "test_connection"):
            return {"msg": "Test not supported for provider '%s'"
                           % profile.get("cloud_provider", ""), "folder_id": ""}
        _ok, msg = client.test_connection()
        if _ok and hasattr(client, "resolved_folder_id"):
            try:
                folder = client.resolved_folder_id()
            except Exception:
                folder = ""
        return {"msg": msg, "folder_id": folder}
    except Exception as e:
        return {"msg": "\u2717 %s" % e, "folder_id": ""}


def validate_cloud_key(path):
    """Validate a Google credentials JSON and report OAuth token status
    (SettingsTab._validate_global_key). Returns {status, is_oauth}."""
    path = (path or "").strip()
    if not path:
        return {"status": "", "is_oauth": False}
    if cloud_client._is_service_account_key(path):
        return {"status": "\u2713 Valid service account key", "is_oauth": False}
    if cloud_client._is_oauth_client_secret(path):
        token_status = cloud_client.get_oauth_token_status(path)
        return {"status": token_status or "OAuth client secret — click Authenticate",
                "is_oauth": True}
    if os.path.isfile(path):
        return {"status": "Unrecognised format — expected an OAuth or service account JSON",
                "is_oauth": False}
    return {"status": "File not found", "is_oauth": False}


def authenticate_oauth(path):
    """Run the Google OAuth consent flow (opens a browser). Returns {ok, msg}."""
    path = (path or "").strip()
    if not path:
        return {"ok": False, "msg": "Set the cloud credentials JSON first"}
    if not os.path.isfile(path):
        return {"ok": False, "msg": "Credentials file not found"}
    if not cloud_client._is_oauth_client_secret(path):
        return {"ok": False,
                "msg": "Not an OAuth client secret — service-account keys don't need this."}
    try:
        ok, msg = cloud_client.authenticate_oauth(path)
        return {"ok": bool(ok), "msg": msg}
    except Exception as e:
        return {"ok": False, "msg": "\u2717 %s" % e}


# ─────────────────────────────────────────────────────────────────────────────
# Hub discovery
# ─────────────────────────────────────────────────────────────────────────────
def get_hub_creds():
    """The hub URL + key from The Hub's shared store (config.shared_cred)."""
    return {
        "url": cfg_module.shared_cred("hub_url"),
        "key": cfg_module.shared_cred("hub_key"),
        "has_key": bool(cfg_module.shared_cred("hub_key")),
    }


def default_staging_dir():
    root = (os.environ.get("SNAPSMACK_HOME") or "").strip() or os.path.expanduser("~/snapsmack")
    return os.path.join(root, "staging")


def discover_hub(url, key, base_dir=""):
    """Connect to a hub, pull every spoke, fill each key, point them at the
    Global Cloud Config, and create the missing profiles (HubDiscoveryDialog).
    Runs on a background thread; returns a job_id to poll."""
    url = (url or "").strip()
    key = (key or "").strip()
    base_dir = (base_dir or "").strip() or default_staging_dir()
    if not url or not key:
        raise ValueError("Hub URL and API key are required.")
    jid = _new_job()

    def _run():
        try:
            _progress(jid, "Connecting to hub…", 0.05)
            disc = hub_discovery.HubDiscovery(url, api_key=key)
            _log(jid, "Connected. Fetching spoke list…")
            hub_info, spokes = disc.discover_spokes()

            spoke_configs = {}
            total = len(spokes) or 1
            for i, spoke in enumerate(spokes):
                spoke_url = spoke.get("site_url", "").rstrip("/")
                api_key = spoke.get("api_key_remote", "")
                _progress(jid, "Querying spoke %d/%d: %s"
                          % (i + 1, len(spokes), spoke.get("site_name", "?")),
                          0.1 + 0.7 * (i / total))
                if spoke_url and api_key:
                    cfg = disc.fetch_spoke_backup_config(spoke_url, api_key)
                    if cfg:
                        spoke_configs[spoke_url] = cfg
            disc.close()

            profiles = hub_discovery.build_profiles_from_spokes(
                hub_info, spokes, spoke_configs, base_dir,
                global_cloud=_global_cloud_from_cfg(),
                hub_api_key=key,
            )
            existing = set(profile_manager.list_profiles())
            created = skipped = 0
            names = []
            for p in profiles:
                name = p.get("name", "")
                if name in existing:
                    skipped += 1
                    continue
                profile_manager.save_profile(p)
                existing.add(name)
                created += 1
                names.append(name)
                _log(jid, "Created profile: %s" % name)
            _progress(jid, "Done — created %d, skipped %d." % (created, skipped), 1.0)
            _finish(jid, result={"created": created, "skipped": skipped, "names": names})
        except Exception as e:
            _log(jid, "\u2717 %s" % e)
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


def pull_cloud_config(profile):
    """Pull a blog's cloud config via admin login (SettingsTab._pull_cloud_config).
    Returns {cloud_config, site_name, ...}."""
    site_url = str(profile.get("site_url", "")).strip()
    admin_user = str(profile.get("snap_admin_user", "")).strip()
    admin_pass = str(profile.get("snap_admin_pass", "")).strip()
    if not site_url or not admin_user or not admin_pass:
        raise ValueError("Fill in the site URL and admin credentials first, then save the profile.")
    disc = hub_discovery.HubDiscovery(site_url, admin_user, admin_pass,
                                      login_slug=profile.get("login_slug", "snap-in"))
    try:
        return disc.fetch_suyb_data()
    finally:
        disc.close()


# ─────────────────────────────────────────────────────────────────────────────
# Backup
# ─────────────────────────────────────────────────────────────────────────────
def precheck_backup(profile_name):
    """Pre-flight the cloud config for a cloud-method profile (BackupTab._start).
    Returns {ok, warning} — the page shows the warning and confirms local-only."""
    profile = profile_manager.load_profile(profile_name)
    if not profile:
        raise ValueError("Profile not found.")
    if not profile.get("backup_dir"):
        return {"ok": False, "warning": "Set a local backup directory in the profile."}
    if profile.get("backup_method") == "cloud":
        gc = _global_cloud_from_cfg()
        client = cloud_client.get_cloud_client(profile, global_cloud=gc)
        if not client:
            provider = (profile.get("cloud_provider")
                        or gc.get("cloud_provider") or "none")
            if provider == "google_drive":
                warn = ("Cloud provider is 'google_drive' but no credentials file is "
                        "configured. Set it in Settings → Global Cloud Config. "
                        "Continue with local-only backup instead?")
            else:
                warn = ("No cloud provider is configured. Set one in Settings → "
                        "Global Cloud Config. Continue with local-only backup instead?")
            return {"ok": True, "warning": warn}
    return {"ok": True, "warning": ""}


def check_resume(profile_name):
    """Look for an interrupted-backup checkpoint (BackupTab._start)."""
    from checkpoint import BackupCheckpoint
    profile = profile_manager.load_profile(profile_name)
    if not profile:
        return {"found": False}
    cp = BackupCheckpoint.load(profile.get("backup_dir", ""), profile.get("name", "blog"))
    if not cp:
        return {"found": False}
    return {
        "found": True,
        "created_at": cp.data.get("created_at", "")[:16].replace("T", " "),
        "files_downloaded": cp.data.get("files_downloaded", 0),
        "files_skipped": cp.data.get("files_skipped", 0),
    }


def clear_resume(profile_name):
    """Delete a checkpoint (user chose 'Start Fresh')."""
    from checkpoint import BackupCheckpoint
    profile = profile_manager.load_profile(profile_name)
    if profile:
        cp = BackupCheckpoint.load(profile.get("backup_dir", ""), profile.get("name", "blog"))
        if cp:
            cp.delete()
    return {"ok": True}


def _wire_backup_engine(jid, engine):
    """Attach the job registry to a BackupEngine's callbacks (BackupTab wiring)."""
    with _jobs_lock:
        _jobs[jid]["engine"] = engine


def start_backup(profile_name, mode="differential", include_settings=True, resume=False):
    """Start one blog's backup on a thread (BackupTab._start). Returns job_id."""
    profile = profile_manager.load_profile(profile_name)
    if not profile:
        raise ValueError("Profile not found.")
    if not profile.get("backup_dir"):
        raise ValueError("Set a local backup directory in the profile first.")

    resume_cp = None
    if resume:
        from checkpoint import BackupCheckpoint
        resume_cp = BackupCheckpoint.load(profile.get("backup_dir", ""),
                                          profile.get("name", "blog"))

    jid = _new_job()

    def on_ask(msg):
        with _jobs_lock:
            _jobs[jid]["ask"] = str(msg)

    engine = BackupEngine(
        profile,
        on_progress=lambda s, m, p: _progress(jid, m, p),
        on_log=lambda m: _log(jid, m),
        on_ask=on_ask,
        on_stats=lambda *a: _set_stats(jid, a),
        force_full=(mode == "full"),
        include_settings=bool(include_settings),
        global_config=_global_config_dict(),
        global_cloud=_global_cloud_from_cfg(),
        resume_checkpoint=resume_cp,
    )
    _wire_backup_engine(jid, engine)

    def _run():
        try:
            result = engine.run()
            if result.get("success"):
                profile["last_backup_date"] = datetime.now(timezone.utc).isoformat()
                profile_manager.save_profile(profile)
            _finish(jid, result=result)
        except Exception as e:
            _log(jid, "\u2717 %s" % e)
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


def _set_stats(jid, a):
    j = _job(jid)
    with _jobs_lock:
        j["stats"] = list(a)


def start_backup_all(mode="differential", include_settings=True):
    """Differential backup of every profile in turn (BackupTab._start_all /
    headless.run_backup_all), isolated per blog. Returns job_id."""
    jid = _new_job()

    def _run():
        try:
            names = profile_manager.list_profiles()
            if not names:
                _log(jid, "No profiles configured — nothing to back up.")
                _finish(jid, result={"ok": 0, "failed": 0, "skipped": 0})
                return
            gc = _global_cloud_from_cfg()
            gconf = _global_config_dict()
            ok = failed = skipped = 0
            total = len(names)
            for i, name in enumerate(names):
                _progress(jid, "Backing up %d/%d: %s" % (i + 1, total, name),
                          i / (total or 1))
                p = profile_manager.load_profile(name)
                if not p or not p.get("backup_dir"):
                    _log(jid, "• %s — skipped (no profile / no backup dir)." % name)
                    skipped += 1
                    continue
                _log(jid, "── Backing up: %s (%s)" % (p.get("name", name), p.get("site_url", "")))
                try:
                    engine = BackupEngine(
                        p,
                        on_log=lambda m: _log(jid, "    %s" % m),
                        on_progress=None,
                        on_ask=None,           # unattended → auto-abort on threshold
                        force_full=(mode == "full"),
                        include_settings=bool(include_settings),
                        global_config=gconf,
                        global_cloud=gc,
                    )
                    _wire_backup_engine(jid, engine)
                    result = engine.run()
                except Exception as e:
                    _log(jid, "    \u2717 crashed: %s" % e)
                    failed += 1
                    continue
                if result.get("success"):
                    ok += 1
                    p["last_backup_date"] = datetime.now(timezone.utc).isoformat()
                    profile_manager.save_profile(p)
                    _log(jid, "    \u2713 done — %s downloaded, %s skipped, %s failed"
                         % (result.get("files_downloaded", 0),
                            result.get("files_skipped", 0),
                            result.get("files_failed", 0)))
                else:
                    failed += 1
                    for err in result.get("errors", []):
                        _log(jid, "    \u2717 %s" % err)
            _progress(jid, "Finished: %d ok, %d failed, %d skipped" % (ok, failed, skipped), 1.0)
            _finish(jid, result={"ok": ok, "failed": failed, "skipped": skipped})
        except Exception as e:
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


# ─────────────────────────────────────────────────────────────────────────────
# Restore
# ─────────────────────────────────────────────────────────────────────────────
def list_cloud_backups(profile_name):
    """Cloud backup packages for the Restore 'browse cloud' picker (RestoreTab._browse_cloud)."""
    profile = profile_manager.load_profile(profile_name)
    if not profile:
        raise ValueError("Select a blog profile first.")
    client = cloud_client.get_cloud_client(profile, global_cloud=_global_cloud_from_cfg())
    if not client:
        raise ValueError("No cloud provider configured for this profile or global settings.")
    return cloud_manifest.list_available_backups(client) or []


def start_restore(profile_name, source, zip_path="", file_id="", kit_path="", media_dir=""):
    """Restore from a local ZIP / cloud package / recovery kit (RestoreTab._start).
    Returns job_id."""
    profile = profile_manager.load_profile(profile_name)
    if not profile:
        raise ValueError("Select a blog profile first.")
    jid = _new_job()
    engine = RestoreEngine(
        profile,
        on_progress=lambda s, m, p: _progress(jid, m, p),
        on_log=lambda m: _log(jid, m),
        global_cloud=_global_cloud_from_cfg(),
    )
    _wire_backup_engine(jid, engine)

    def _run():
        try:
            if source == "local":
                if not zip_path or not os.path.exists(zip_path):
                    raise ValueError("Select a valid backup package ZIP.")
                result = engine.restore_from_zip(zip_path)
            elif source == "cloud":
                if not file_id:
                    raise ValueError("Browse cloud and select a backup package.")
                backup_dir = profile.get("backup_dir", "") or os.path.expanduser("~")
                result = engine.restore_from_cloud(file_id, backup_dir)
            else:  # manual kit
                if not kit_path or not os.path.exists(kit_path):
                    raise ValueError("Select a valid recovery kit (.tar.gz).")
                if not media_dir or not os.path.isdir(media_dir):
                    raise ValueError("Select a valid media folder.")
                result = engine.restore_from_kit(kit_path, media_dir)
            _finish(jid, result=result)
        except Exception as e:
            _log(jid, "\u2717 %s" % e)
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


# ─────────────────────────────────────────────────────────────────────────────
# Audit / Coverage / De-dupe
# ─────────────────────────────────────────────────────────────────────────────
def _find_latest_kit(backup_dir, blog_name):
    """Newest recovery-kit .tar.gz in a backup dir (AuditTab._find_latest_kit)."""
    if not backup_dir or not os.path.isdir(backup_dir):
        return ""
    kits = []
    for root, _dirs, files in os.walk(backup_dir):
        for f in files:
            if f.endswith(".tar.gz") and "kit" in f.lower():
                kits.append(os.path.join(root, f))
    if not kits:
        # fall back to any .tar.gz
        for root, _dirs, files in os.walk(backup_dir):
            for f in files:
                if f.endswith(".tar.gz"):
                    kits.append(os.path.join(root, f))
    if not kits:
        return ""
    kits.sort(key=lambda p: os.path.getmtime(p), reverse=True)
    return kits[0]


def find_latest_kit(profile_name):
    profile = profile_manager.load_profile(profile_name)
    if not profile:
        return {"kit": ""}
    return {"kit": _find_latest_kit(profile.get("backup_dir", ""), profile.get("name", ""))}


def start_audit(profile_name):
    """Three-way server audit (AuditTab._run). Returns job_id; result = report dict."""
    profile = profile_manager.load_profile(profile_name)
    if not profile:
        raise ValueError("Select a blog profile first.")
    kit_path = _find_latest_kit(profile.get("backup_dir", ""), profile.get("name", ""))
    if not kit_path:
        raise ValueError("No recovery kit found in the backup directory. Run a backup first.")
    manifest = manifest_reader.from_tar(kit_path)
    jid = _new_job()
    engine = AuditEngine(
        profile, manifest,
        on_progress=lambda s, m, p: _progress(jid, m, p),
        on_log=lambda m: _log(jid, m),
    )

    def _run():
        try:
            report = engine.run()
            _finish(jid, result=_serialise(report))
        except Exception as e:
            _log(jid, "\u2717 %s" % e)
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


def start_coverage(profile_name):
    """Local-archive coverage scan (AuditTab._run_coverage). Returns job_id."""
    profile = profile_manager.load_profile(profile_name)
    if not profile:
        raise ValueError("Select a blog profile first.")
    backup_dir = profile.get("backup_dir", "")
    if not backup_dir or not os.path.isdir(backup_dir):
        raise ValueError("Set a backup directory in this profile first.")
    kit_path = _find_latest_kit(backup_dir, profile.get("name", ""))
    if not kit_path:
        raise ValueError("No recovery kit found in the backup directory. Run a backup first.")
    manifest = manifest_reader.from_tar(kit_path)
    jid = _new_job()
    engine = CoverageEngine(
        backup_dir=backup_dir,
        manifest=manifest,
        blog_name=profile.get("name", ""),
        on_progress=lambda s, m, p: _progress(jid, m, p),
        on_log=lambda m: _log(jid, m),
    )

    def _run():
        try:
            report = engine.run()
            _last_coverage[profile_name] = report   # keep for a follow-up de-dupe
            _finish(jid, result=_serialise(report))
        except Exception as e:
            _log(jid, "\u2717 %s" % e)
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


def start_dedupe(profile_name):
    """Rewrite over-backed ZIPs from the last coverage report (AuditTab._run_dedupe)."""
    report = _last_coverage.get(profile_name)
    if not report or report.count(OVER_BACKED) == 0:
        raise ValueError("Run a coverage check first (nothing over-backed to clean).")
    jid = _new_job()
    engine = DedupeEngine(
        report=report,
        on_progress=lambda s, m, p: _progress(jid, m, p),
        on_log=lambda m: _log(jid, m),
    )

    def _run():
        try:
            result = engine.run()
            _finish(jid, result=_serialise(result))
        except Exception as e:
            _log(jid, "\u2717 %s" % e)
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


# ─────────────────────────────────────────────────────────────────────────────
# Scheduler
# ─────────────────────────────────────────────────────────────────────────────
def list_schedules():
    """Per-profile schedule rows (SchedulerTab.refresh / _add_row)."""
    from scheduler import BackupScheduler
    rows = []
    for name in profile_manager.list_profiles():
        p = profile_manager.load_profile(name)
        if not p:
            continue
        rows.append({
            "name": name,
            "site_url": p.get("site_url", ""),
            "schedule_enabled": bool(p.get("schedule_enabled", False)),
            "schedule_type": p.get("schedule_type", "daily"),
            "schedule_day": p.get("schedule_day", "monday"),
            "schedule_time": p.get("schedule_time", "02:00"),
            "last_scheduled_run": (p.get("last_scheduled_run", "") or "Never")[:16].replace("T", " "),
            "next_run": BackupScheduler.next_run_str(p),
        })
    return rows


def save_schedule_field(name, key, value):
    """Persist one schedule field to a profile (SchedulerTab._save_field)."""
    if key not in ("schedule_enabled", "schedule_type", "schedule_day", "schedule_time"):
        raise ValueError("Not a schedule field: %s" % key)
    p = profile_manager.load_profile(name)
    if not p:
        raise ValueError("Profile not found.")
    p[key] = value
    profile_manager.save_profile(p)
    from scheduler import BackupScheduler
    return {"ok": True, "next_run": BackupScheduler.next_run_str(p),
            "enabled": bool(p.get("schedule_enabled"))}


def global_schedule_state():
    return os_schedule.schedule_state()


def set_global_schedule(enabled, time_str="02:00"):
    """Register / remove the OS-level daily backup-all task (SettingsTab._on_global_schedule_toggle)."""
    ok, msg = os_schedule.set_global_schedule(bool(enabled), time_str or "02:00")
    return {"ok": bool(ok), "msg": msg}


# ─────────────────────────────────────────────────────────────────────────────
# Cloud Sync (Google Drive → Backblaze B2)
# ─────────────────────────────────────────────────────────────────────────────
def list_sync_jobs():
    return sync_manager.list_jobs()


def get_sync_job(name):
    j = sync_manager.load_job(name)
    if not j:
        raise ValueError("Sync job not found: %s" % name)
    return j


def new_sync_job_template():
    return sync_manager.new_job_template()


def save_sync_job(config):
    name = (config.get("name") or "").strip()
    if not name:
        raise ValueError("Sync job name is required.")
    sync_manager.save_job(config)
    return {"ok": True, "name": name}


def delete_sync_job(name):
    sync_manager.delete_job(name)
    return {"ok": True, "jobs": sync_manager.list_jobs()}


def test_b2(key_id, app_key, bucket, folder=""):
    """B2 connection test used by the sync-job dialog (_SyncJobDialog._test_b2)."""
    try:
        ok, msg = cloud_client.test_b2_connection(key_id, app_key, bucket)
        return {"ok": bool(ok), "msg": msg}
    except Exception as e:
        return {"ok": False, "msg": "\u2717 %s" % e}


def start_sync(name):
    """Run a cloud-to-cloud sync job (CloudSyncTab._start). Returns job_id."""
    job = sync_manager.load_job(name)
    if not job:
        raise ValueError("Select or create a sync job first.")
    # Provider-aware required-field validation (mirror CloudSyncTab._start).
    missing = []
    src_p = job.get("source_provider", "google_drive")
    dst_p = job.get("dest_provider", "backblaze_b2")
    if src_p in ("google_drive", "box"):
        if not job.get("source_credentials_file"):
            missing.append("Source credentials file (OAuth JSON)")
        if not job.get("source_folder"):
            missing.append("Source folder ID")
    elif src_p in ("backblaze_b2", "b2"):
        if not job.get("source_b2_key_id"):
            missing.append("Source B2 Key ID")
        if not job.get("source_b2_app_key"):
            missing.append("Source B2 Application Key")
        if not job.get("source_folder"):
            missing.append("Source bucket name")
    if dst_p in ("google_drive", "box"):
        if not job.get("dest_credentials_file"):
            missing.append("Destination credentials file (OAuth JSON)")
        if not job.get("dest_folder"):
            missing.append("Destination folder")
    elif dst_p in ("backblaze_b2", "b2"):
        if not job.get("dest_b2_key_id"):
            missing.append("Destination B2 Key ID")
        if not job.get("dest_b2_app_key"):
            missing.append("Destination B2 Application Key")
        if not job.get("dest_folder"):
            missing.append("Destination bucket name")
    if missing:
        raise ValueError("Please configure:\n• " + "\n• ".join(missing))

    from cloud_sync_engine import CloudSyncEngine
    jid = _new_job()

    def on_ask(msg):
        with _jobs_lock:
            _jobs[jid]["ask"] = str(msg)

    engine = CloudSyncEngine(
        config=job,
        on_log=lambda m: _log(jid, m),
        on_progress=lambda p: _progress(jid, "", p),
        on_stats=lambda *a: _set_stats(jid, a),
        on_done=lambda r: _finish(jid, result=r),
        on_ask=on_ask,
    )
    _wire_backup_engine(jid, engine)

    def _run():
        try:
            engine.run()   # calls on_done → _finish
            # Guard: if the engine never fired on_done, close the job out.
            j = _job(jid)
            if not j["done"]:
                _finish(jid, result={"ok": True})
        except Exception as e:
            _log(jid, "\u2717 %s" % e)
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


# TODO(port): CloudSyncTab._audit_cleanup (inventory + delete-bad-versions +
# re-transfer) is a two-step confirm flow. Not yet wired to the web UI; the
# nightly sync itself is fully ported above. Wire audit-cleanup when the confirm
# UX is designed.


# ─────────────────────────────────────────────────────────────────────────────
# Settings — pacing, global cloud, credential library, encryption vault, AI
# ─────────────────────────────────────────────────────────────────────────────
def get_settings():
    cfg = cfg_module.load()
    return {
        "pacing": {
            "transfer_delay": cfg.get("pacing", "transfer_delay", fallback="2"),
            "batch_size":     cfg.get("pacing", "batch_size", fallback="0"),
        },
        "global_cloud": _global_cloud_from_cfg(cfg),
        "vault": vault_status(),
        "schedule": os_schedule.schedule_state(),
        "creds": cred_store.load(),
    }


def save_settings(pacing=None, global_cloud=None):
    """Save global defaults (SettingsTab._save)."""
    cfg = cfg_module.load()
    pacing = pacing or {}
    global_cloud = global_cloud or {}
    if not cfg.has_section("pacing"):
        cfg.add_section("pacing")
    if "transfer_delay" in pacing:
        cfg.set("pacing", "transfer_delay", str(pacing["transfer_delay"]))
    if "batch_size" in pacing:
        cfg.set("pacing", "batch_size", str(pacing["batch_size"]))
    if not cfg.has_section("cloud"):
        cfg.add_section("cloud")
    if "cloud_provider" in global_cloud:
        cfg.set("cloud", "provider", str(global_cloud["cloud_provider"]))
    if "cloud_credentials_file" in global_cloud:
        cfg.set("cloud", "credentials_file", str(global_cloud["cloud_credentials_file"]).strip())
    if "cloud_folder_id" in global_cloud:
        cfg.set("cloud", "folder_id", str(global_cloud["cloud_folder_id"]).strip())
    cfg_module.save(cfg)
    return {"ok": True}


# credential library (named credentials JSON) — _CredLibraryDialog / cred_store
def list_creds():
    return cred_store.load()


def add_cred(name, path):
    if not name or not path:
        raise ValueError("Both a name and a file path are required.")
    cred_store.add_or_update(name, path)
    return {"ok": True, "creds": cred_store.load()}


def remove_cred(name):
    cred_store.remove(name)
    return {"ok": True, "creds": cred_store.load()}


def rename_cred(old_name, new_name):
    if not new_name:
        raise ValueError("New name is required.")
    cred_store.rename(old_name, new_name)
    return {"ok": True, "creds": cred_store.load()}


# credential encryption vault (SettingsTab encryption panel)
def enc_status():
    return vault_status()


def enc_enable(passphrase, store_machine_key=False):
    if not passphrase:
        raise ValueError("Choose a passphrase.")
    profile_manager.enable_encryption(passphrase, store_machine_key=bool(store_machine_key))
    return {"ok": True, "vault": vault_status()}


def enc_change(old_passphrase, new_passphrase):
    if not new_passphrase:
        raise ValueError("Enter a new passphrase.")
    ok = profile_manager.change_encryption_passphrase(old_passphrase, new_passphrase)
    return {"ok": bool(ok), "vault": vault_status()}


def enc_disable():
    profile_manager.disable_encryption()
    return {"ok": True, "vault": vault_status()}


def enc_toggle_machine_key(on):
    """Cache/clear this machine's unattended-backup key (SettingsTab._on_enc_toggle_machine_key)."""
    if on:
        ok = secret_vault.store_machine_key_now()
    else:
        secret_vault.clear_machine_key()
        ok = True
    return {"ok": bool(ok), "vault": vault_status()}


# AI file matcher (optional dependency)
def ai_status():
    try:
        import ai_matcher
        return {"status": ai_matcher.status_string()}
    except Exception:
        return {"status": "Not installed — pip install sentence-transformers"}


def install_ai():
    """Install the optional AI matcher via pip on a background thread (SettingsTab._install_ai)."""
    import subprocess
    import shutil
    jid = _new_job()

    def _run():
        try:
            python = sys.executable
            if getattr(sys, "frozen", False):
                python = shutil.which("python") or shutil.which("python3")
                if not python:
                    _finish(jid, error="Compiled build — run 'pip install "
                                        "sentence-transformers' manually, then restart.")
                    return
            _progress(jid, "Installing sentence-transformers…", 0.2)
            result = subprocess.run(
                [python, "-m", "pip", "install", "sentence-transformers"],
                capture_output=True, text=True, timeout=600)
            if result.returncode == 0:
                _progress(jid, "Installed.", 1.0)
                _finish(jid, result={"ok": True})
            else:
                err = (result.stderr or result.stdout or "Unknown error").strip()[-300:]
                _finish(jid, error="Install failed: %s" % err)
        except Exception as e:
            _finish(jid, error=str(e))

    threading.Thread(target=_run, daemon=True).start()
    return jid


# export / import all settings (SettingsTab._export_settings / _import_settings)
# The tkinter version used file dialogs; the web version passes the data across
# the bridge instead, so there is no native file picker involved.
def export_settings():
    """Bundle every profile + the global config into one dict (JSON-serialisable)."""
    data = {
        "_suyb_export": True,
        "version": _BUILD_VERSION,
        "exported_at": datetime.now(timezone.utc).isoformat(),
        "config": _global_config_dict(),
        "profiles": [profile_manager.load_profile(n)
                     for n in profile_manager.list_profiles()],
    }
    return data


def import_settings(data):
    """Restore profiles from an export dict. Existing profiles are overwritten by name."""
    if not isinstance(data, dict) or not data.get("_suyb_export"):
        raise ValueError("Not a SUYB settings export.")
    profiles = data.get("profiles") or []
    count = 0
    for p in profiles:
        if isinstance(p, dict) and p.get("name"):
            profile_manager.save_profile(p)
            count += 1
    # Restore the global config sections if present.
    conf = data.get("config") or {}
    if conf:
        cfg = cfg_module.load()
        for section, values in conf.items():
            if not cfg.has_section(section):
                cfg.add_section(section)
            for k, v in (values or {}).items():
                cfg.set(section, str(k), str(v))
        cfg_module.save(cfg)
    return {"ok": True, "imported": count}

# ===== SNAPSMACK EOF =====
