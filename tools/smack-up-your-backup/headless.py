"""
Smack Up Your Backup — headless.py

GUI-less "back up every blog" runner.

Invoked from the command line:   suyb(.exe) --backup-all [--silent]

This is what the global "Automatic Backups" schedule registers with the OS
(Windows Task Scheduler / Linux cron), so backups run on time whether or not
the SUYB window is open. No Tk is imported on this path.

Behaviour: differential backup over the whole roster, one blog at a time, with
per-blog isolation (one blog's failure never aborts the rest), file + optional
stdout logging. Hub status write-back is handled inside BackupEngine.run().
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys
import logging
from datetime import datetime

import config as cfg_module
import profile_manager
from backup_engine import BackupEngine


def _logs_dir() -> str:
    d = os.path.join(cfg_module._app_dir(), "logs")
    os.makedirs(d, exist_ok=True)
    return d


def _setup_logger(silent: bool) -> logging.Logger:
    log = logging.getLogger("suyb.headless")
    log.setLevel(logging.INFO)
    log.handlers.clear()          # avoid duplicate handlers on repeat runs
    log.propagate = False
    ts = datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
    fh = logging.FileHandler(
        os.path.join(_logs_dir(), f"auto-backup_{ts}.log"), encoding="utf-8")
    fh.setFormatter(logging.Formatter("%(asctime)s  %(message)s", "%Y-%m-%d %H:%M:%S"))
    log.addHandler(fh)
    if not silent:
        sh = logging.StreamHandler(sys.stdout)
        sh.setFormatter(logging.Formatter("%(message)s"))
        log.addHandler(sh)
    return log


def _global_cloud_from_cfg(cfg) -> dict:
    """Mirror App.global_cloud_config() from config.ini for the cloud factory."""
    return {
        "cloud_provider":         cfg.get("cloud", "provider",         fallback="none"),
        "cloud_credentials_file": cfg.get("cloud", "credentials_file", fallback=""),
        "cloud_folder_id":        cfg.get("cloud", "folder_id",        fallback=""),
    }


def run_backup_all(silent: bool = True) -> int:
    """Run a differential backup of every profile.

    Returns a process exit code: 0 = all blogs succeeded (or nothing to do),
    1 = one or more blogs failed.
    """
    log = _setup_logger(silent)
    started = datetime.now()
    log.info("=" * 60)
    log.info("SUYB automatic backup — all blogs — %s",
             started.strftime("%Y-%m-%d %H:%M:%S"))

    cfg = cfg_module.load()
    global_cloud = _global_cloud_from_cfg(cfg)

    names = profile_manager.list_profiles()
    if not names:
        log.info("No profiles configured — nothing to back up.")
        return 0

    ok = failed = skipped = 0
    for name in names:
        p = profile_manager.load_profile(name)
        if not p:
            log.info("• %s — could not load profile, skipping.", name)
            skipped += 1
            continue
        if not p.get("backup_dir"):
            log.info("• %s — no backup directory set, skipping.",
                     p.get("name", name))
            skipped += 1
            continue

        log.info("-" * 60)
        log.info("Backing up: %s  (%s)", p.get("name", name), p.get("site_url", ""))
        try:
            engine = BackupEngine(
                p,
                on_log=lambda m: log.info("    %s", m),
                on_progress=None,
                on_ask=None,            # unattended → auto-abort on failure threshold
                force_full=False,       # differential
                include_settings=True,
                global_config=None,
                global_cloud=global_cloud,
            )
            result = engine.run()
        except Exception as e:                      # never let one blog kill the run
            log.info("    ✗ crashed: %s", e)
            failed += 1
            continue

        if result.get("success"):
            ok += 1
            log.info("    ✓ done — %s downloaded, %s skipped, %s failed",
                     result.get("files_downloaded", 0),
                     result.get("files_skipped", 0),
                     result.get("files_failed", 0))
        else:
            failed += 1
            for err in result.get("errors", []):
                log.info("    ✗ %s", err)

    elapsed = (datetime.now() - started).total_seconds()
    log.info("=" * 60)
    log.info("Finished: %s ok, %s failed, %s skipped  (%.0fs)",
             ok, failed, skipped, elapsed)
    return 0 if failed == 0 else 1

# ===== SNAPSMACK EOF =====
