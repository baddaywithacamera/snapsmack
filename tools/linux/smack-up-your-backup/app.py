#!/usr/bin/env python3
"""
SMACK UP YOUR BACKUP (SUYB) — Linux Chrome/Blink port.

The window is HTML drawn by Chromium; the WORK is the tool's original Python
(backup_engine, restore_engine, audit/coverage engines, cloud_sync_engine,
hub_discovery, cloud_client, profile_manager, sync_manager, os_schedule,
secret_vault …), reached through the shared snap_blink bridge.

There is no tkinter here. Every window action the Windows build had is exposed
as a blink.call handler below, delegating to suyb_core — the orchestration glue
factored out of main.py so both the tkinter App and this Blink app drive the
exact same backup logic. Credentials and the hub key still come from The Hub's
shared store (config.shared_cred → snap_creds); SUYB never keeps a private one.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                    # tools/smack-up-your-backup/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")    # tools/_shared/
for p in (SHARED, TOOL_ROOT):
    sys.path.insert(0, os.path.abspath(p))

import snap_blink
import suyb_core as core

app = snap_blink.App(tool="suyb", title="Smack Up Your Backup",
                     web_dir=os.path.join(HERE, "web"))


# ── app state / profiles ─────────────────────────────────────────────────────
@app.api
def load_state():
    return core.load_state()


@app.api
def unlock_vault(passphrase):
    return {"ok": core.unlock_vault(passphrase)}


@app.api
def list_profiles():
    return core.list_profiles()


@app.api
def get_profile(name):
    return core.get_profile(name)


@app.api
def new_profile_template():
    return core.new_profile_template()


@app.api
def save_profile(profile):
    return core.save_profile(profile)


@app.api
def delete_profile(name):
    return core.delete_profile(name)


@app.api
def duplicate_profile(name, new_name=""):
    return core.duplicate_profile(name, new_name)


@app.api
def select_profile(name):
    return core.select_profile(name)


# ── job polling (shared by every long-running action) ────────────────────────
@app.api
def job_status(job_id, since=0):
    return core.job_status(job_id, since)


@app.api
def cancel_job(job_id):
    return core.cancel_job(job_id)


@app.api
def resolve_ask(job_id, cont):
    return core.resolve_ask(job_id, cont)


# ── connection tests ─────────────────────────────────────────────────────────
@app.api
def test_login(profile):
    return {"msg": core.test_login(profile)}


@app.api
def test_conn(profile):
    return {"msg": core.test_conn(profile)}


@app.api
def test_cloud(profile):
    return core.test_cloud(profile)


@app.api
def validate_cloud_key(path):
    return core.validate_cloud_key(path)


@app.api
def authenticate_oauth(path):
    return core.authenticate_oauth(path)


# ── hub discovery ────────────────────────────────────────────────────────────
@app.api
def get_hub_creds():
    return core.get_hub_creds()


@app.api
def default_staging_dir():
    return {"dir": core.default_staging_dir()}


@app.api
def discover_hub(url, key, base_dir=""):
    return {"job_id": core.discover_hub(url, key, base_dir)}


@app.api
def pull_cloud_config(profile):
    return core.pull_cloud_config(profile)


# ── backup ───────────────────────────────────────────────────────────────────
@app.api
def precheck_backup(profile_name):
    return core.precheck_backup(profile_name)


@app.api
def check_resume(profile_name):
    return core.check_resume(profile_name)


@app.api
def clear_resume(profile_name):
    return core.clear_resume(profile_name)


@app.api
def start_backup(profile_name, mode="differential", include_settings=True, resume=False):
    return {"job_id": core.start_backup(profile_name, mode, include_settings, resume)}


@app.api
def start_backup_all(mode="differential", include_settings=True):
    return {"job_id": core.start_backup_all(mode, include_settings)}


# ── restore ──────────────────────────────────────────────────────────────────
@app.api
def list_cloud_backups(profile_name):
    return core.list_cloud_backups(profile_name)


@app.api
def start_restore(profile_name, source, zip_path="", file_id="", kit_path="", media_dir=""):
    return {"job_id": core.start_restore(profile_name, source, zip_path, file_id, kit_path, media_dir)}


# ── audit / coverage / de-dupe ───────────────────────────────────────────────
@app.api
def find_latest_kit(profile_name):
    return core.find_latest_kit(profile_name)


@app.api
def start_audit(profile_name):
    return {"job_id": core.start_audit(profile_name)}


@app.api
def start_coverage(profile_name):
    return {"job_id": core.start_coverage(profile_name)}


@app.api
def start_dedupe(profile_name):
    return {"job_id": core.start_dedupe(profile_name)}


# ── scheduler ────────────────────────────────────────────────────────────────
@app.api
def list_schedules():
    return core.list_schedules()


@app.api
def save_schedule_field(name, key, value):
    return core.save_schedule_field(name, key, value)


@app.api
def global_schedule_state():
    return core.global_schedule_state()


@app.api
def set_global_schedule(enabled, time_str="02:00"):
    return core.set_global_schedule(enabled, time_str)


# ── cloud sync ───────────────────────────────────────────────────────────────
@app.api
def list_sync_jobs():
    return core.list_sync_jobs()


@app.api
def get_sync_job(name):
    return core.get_sync_job(name)


@app.api
def new_sync_job_template():
    return core.new_sync_job_template()


@app.api
def save_sync_job(config):
    return core.save_sync_job(config)


@app.api
def delete_sync_job(name):
    return core.delete_sync_job(name)


@app.api
def test_b2(key_id, app_key, bucket, folder=""):
    return core.test_b2(key_id, app_key, bucket, folder)


@app.api
def start_sync(name):
    return {"job_id": core.start_sync(name)}


# ── settings ─────────────────────────────────────────────────────────────────
@app.api
def get_settings():
    return core.get_settings()


@app.api
def save_settings(pacing=None, global_cloud=None):
    return core.save_settings(pacing, global_cloud)


@app.api
def list_creds():
    return core.list_creds()


@app.api
def add_cred(name, path):
    return core.add_cred(name, path)


@app.api
def remove_cred(name):
    return core.remove_cred(name)


@app.api
def rename_cred(old_name, new_name):
    return core.rename_cred(old_name, new_name)


@app.api
def enc_status():
    return core.enc_status()


@app.api
def enc_enable(passphrase, store_machine_key=False):
    return core.enc_enable(passphrase, store_machine_key)


@app.api
def enc_change(old_passphrase, new_passphrase):
    return core.enc_change(old_passphrase, new_passphrase)


@app.api
def enc_disable():
    return core.enc_disable()


@app.api
def enc_toggle_machine_key(on):
    return core.enc_toggle_machine_key(on)


@app.api
def ai_status():
    return core.ai_status()


@app.api
def install_ai():
    return {"job_id": core.install_ai()}


@app.api
def export_settings():
    return core.export_settings()


@app.api
def import_settings(data):
    return core.import_settings(data)


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
