#!/usr/bin/env python3
"""
SMACK YOUR BATCH UP (SYBU) — Linux Chrome/Blink port.

The window is HTML/CSS/JS served locally and shown in a Chromium --app window;
the WORK is the original SYBU Python, reached through blink.call handlers. No
tkinter. Every action from the desktop window (Connect, Scan, Load Manifest,
Enrich, Validate, Post — solo & gram, Audit, Repair rename/re-enrich/backfill,
Advanced Visual Match, Site Profiles) is exposed here and lives in sybu_core.py,
which reuses poster / gemini / drive / manifest_parser / profile_manager /
recovery / matcher unchanged and keeps the shared-library contract.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                     # tools/sybu/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")     # tools/_shared/
for p in (SHARED, TOOL_ROOT):
    ap = os.path.abspath(p)
    if ap not in sys.path:
        sys.path.insert(0, ap)

import snap_blink
import sybu_core
from sybu_core import ENGINE

app = snap_blink.App(tool="sybu", title="SMACK YOUR BATCH UP",
                     web_dir=os.path.join(HERE, "web"))


# ── boot / config ────────────────────────────────────────────────────────────
@app.api
def load_state():
    """Everything the page needs on open."""
    return {
        "config": ENGINE.config_fields(),
        "connection": ENGINE.connection_state(),
        "profiles": ENGINE.profiles_list(),
        "preset_names": ENGINE.preset_names(),
        "queue": ENGINE.serialize_queue(),
        "picker": _picker_available(),
    }


@app.api
def save_config(fields):
    ENGINE.save_config(fields or {})
    return {"ok": True}


# ── native folder / file pickers (best-effort; text field is the real control) ─
def _picker_available():
    return bool(shutil.which("zenity") or shutil.which("kdialog"))


def _pick(kind, start=""):
    """Open a native picker via zenity/kdialog when present. kind: 'dir' | 'txt' | 'json' | 'image'.
    Returns the chosen path or '' (cancel / no picker). The page always has a
    plain text field too, so choosing a path never depends on this."""
    zen = shutil.which("zenity")
    kde = shutil.which("kdialog")
    try:
        if zen:
            args = [zen, "--file-selection"]
            if kind == "dir":
                args.append("--directory")
            if start:
                args += ["--filename", start if start.endswith(os.sep) else start + os.sep]
            if kind == "txt":
                args += ["--file-filter", "Manifest text | *.txt", "--file-filter", "All | *"]
            elif kind == "json":
                args += ["--file-filter", "JSON | *.json", "--file-filter", "All | *"]
            elif kind == "image":
                args += ["--file-filter", "Images | *.jpg *.jpeg *.png *.webp", "--file-filter", "All | *"]
            out = subprocess.run(args, capture_output=True, text=True, timeout=300)
            return (out.stdout or "").strip()
        if kde:
            if kind == "dir":
                args = [kde, "--getexistingdirectory", start or os.path.expanduser("~")]
            else:
                filt = {"txt": "*.txt", "json": "*.json",
                        "image": "*.jpg *.jpeg *.png *.webp"}.get(kind, "*")
                args = [kde, "--getopenfilename", start or os.path.expanduser("~"), filt]
            out = subprocess.run(args, capture_output=True, text=True, timeout=300)
            return (out.stdout or "").strip()
    except Exception:
        return ""
    # TODO(port): no zenity/kdialog installed — the page's text field is used instead.
    return ""


@app.api
def browse_folder(start=""):
    return {"path": _pick("dir", start)}


@app.api
def browse_manifest(start=""):
    return {"path": _pick("txt", start)}


@app.api
def browse_creds(start=""):
    return {"path": _pick("json", start)}


@app.api
def browse_image(start=""):
    return {"path": _pick("image", start)}


# ── op polling / cancel (shared plumbing) ─────────────────────────────────────
@app.api
def op_poll(key, seen=0):
    return ENGINE.op_poll(key, seen)


@app.api
def cancel_op(key):
    return ENGINE.cancel_op(key)


# ── connect ───────────────────────────────────────────────────────────────────
@app.api
def connect(url, api_key, remember, ack_insecure=False):
    return ENGINE.connect(url, api_key, remember, ack_insecure=ack_insecure)


# ── queue: scan / load / edit / select / reorder / clear / thumbs ─────────────
@app.api
def scan_folder(image_folder, def_cat, def_album, def_orient):
    return ENGINE.scan_folder(image_folder, def_cat, def_album, def_orient)


@app.api
def load_manifest(manifest_path, image_folder):
    return ENGINE.load_manifest(manifest_path, image_folder)


@app.api
def apply_resume():
    return ENGINE.apply_resume()


@app.api
def update_entry(index, patch):
    return ENGINE.update_entry(index, patch or {})


@app.api
def set_selected(index, on):
    ENGINE.set_selected(index, on)
    return {"ok": True}


@app.api
def set_all_selected(on):
    ENGINE.set_all_selected(on)
    return {"ok": True}


@app.api
def reorder(from_index, to_index):
    return ENGINE.reorder(from_index, to_index)


@app.api
def shuffle():
    return ENGINE.shuffle()


@app.api
def clear_queue():
    return ENGINE.clear_queue()


@app.api
def clear_enrichment_warning():
    return ENGINE.clear_enrichment_warning()


@app.api
def thumb(index):
    return {"data": ENGINE.thumb(index)}


# ── gemini presets + test ─────────────────────────────────────────────────────
@app.api
def preset_text(name):
    return {"text": ENGINE.preset_text(name)}


@app.api
def preset_save(name, text):
    return ENGINE.preset_save(name, text)


@app.api
def preset_delete(name):
    return ENGINE.preset_delete(name)


@app.api
def gemini_test(api_key):
    return ENGINE.gemini_test(api_key)


# ── enrich ────────────────────────────────────────────────────────────────────
@app.api
def enrich_start(api_key, custom_prompt=""):
    return ENGINE.enrich_start(api_key, custom_prompt)


# ── validate / post ───────────────────────────────────────────────────────────
@app.api
def validate(def_cat="", def_album=""):
    return ENGINE.validate(def_cat, def_album)


@app.api
def post_preflight(as_grams, drive_enabled=True):
    return ENGINE.post_preflight(as_grams, drive_enabled)


@app.api
def post_start(as_grams, def_cat="", def_album="", def_orient="auto", def_color="",
               copyright_text="", drive_folder_id="", ack_no_drive=False,
               ack_unknown_mode=False, drive_enabled=True):
    return ENGINE.post_start(as_grams, def_cat, def_album, def_orient, def_color,
                             copyright_text, drive_folder_id,
                             ack_no_drive=ack_no_drive, ack_unknown_mode=ack_unknown_mode,
                             drive_enabled=drive_enabled)


@app.api
def cancel_post():
    return ENGINE.cancel_post()


# ── google drive ──────────────────────────────────────────────────────────────
@app.api
def drive_toggle(enabled):
    return ENGINE.drive_toggle(enabled)


@app.api
def auth_drive(creds_path):
    return ENGINE.auth_drive(creds_path)


@app.api
def drive_status():
    return ENGINE.drive_status()


# ── audit ─────────────────────────────────────────────────────────────────────
@app.api
def audit_refresh():
    return ENGINE.audit_refresh()


# ── repair: rename / re-enrich / backfill ─────────────────────────────────────
@app.api
def rename_start():
    return ENGINE.rename_start()


@app.api
def rename_stop():
    return ENGINE.rename_stop()


@app.api
def reenrich_start():
    return ENGINE.reenrich_start()


@app.api
def reenrich_stop():
    return ENGINE.reenrich_stop()


@app.api
def backfill_list(drive_folder_id=""):
    return ENGINE.backfill_list(drive_folder_id)


@app.api
def backfill_auto(snap_id, title, drive_folder_id=""):
    return ENGINE.backfill_auto(snap_id, title, drive_folder_id)


@app.api
def backfill_save(snap_id, url):
    return ENGINE.backfill_save(snap_id, url)


# ── advanced visual match ─────────────────────────────────────────────────────
@app.api
def match_start(srv_folder, orig_folder):
    return ENGINE.match_start(srv_folder, orig_folder)


@app.api
def match_stop():
    return ENGINE.match_stop()


@app.api
def match_preview(row_id, which):
    return {"data": ENGINE.match_preview(row_id, which)}


@app.api
def match_pick(row_id, new_path):
    return ENGINE.match_pick(row_id, new_path)


@app.api
def match_skip(row_id):
    return ENGINE.match_skip(row_id)


@app.api
def match_upload(row_id, drive_folder_id=""):
    return ENGINE.match_upload(row_id, drive_folder_id)


# ── site profiles (settings) ──────────────────────────────────────────────────
@app.api
def profiles_list():
    return {"profiles": ENGINE.profiles_list()}


@app.api
def profile_get(name):
    return ENGINE.profile_get(name)


@app.api
def profile_new():
    return ENGINE.profile_new()


@app.api
def profile_duplicate(name):
    return ENGINE.profile_duplicate(name)


@app.api
def profile_save(fields, old_name="", overwrite=False):
    return ENGINE.profile_save(fields or {}, old_name, overwrite)


@app.api
def profile_delete(name):
    return ENGINE.profile_delete(name)


@app.api
def profile_apply_to_post(name):
    return ENGINE.profile_apply_to_post(name)


@app.api
def sp_test(url, api_key, ack_insecure=False):
    return ENGINE.sp_test(url, api_key, ack_insecure=ack_insecure)


@app.api
def sp_gemini_test(api_key):
    return ENGINE.sp_gemini_test(api_key)


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
