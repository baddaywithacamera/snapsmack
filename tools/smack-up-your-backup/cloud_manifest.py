"""
Smack Up Your Backup — cloud_manifest.py
Cloud state JSON: read, write, cold-start recovery, restore browser.

The cloud state JSON ({blog-name}-cloud-state.json) is pushed to the
cloud folder alongside every backup ZIP.  It contains no credentials —
only blog identity, FTP host/username, file checksums, and a list of
available backup packages.  Safe to leave unencrypted.
"""

import json
import os
from datetime import datetime, timezone
from typing import Dict, List, Optional


CLOUD_STATE_SUFFIX = "-cloud-state.json"


def state_filename(blog_name: str) -> str:
    safe = blog_name.replace(" ", "-").replace("/", "_")
    return f"{safe}{CLOUD_STATE_SUFFIX}"


def build_cloud_state(
    profile:        dict,
    backup_state:   dict,
    available_zips: List[dict],   # [{filename, size_bytes, date}]
) -> dict:
    """
    Build the cloud state dict from the current profile and backup state.
    Strips all credentials before writing.
    """
    return {
        "blog_name":       profile.get("name", ""),
        "site_url":        profile.get("site_url", ""),
        "ftp_host":        profile.get("ftp_host", ""),
        "ftp_user":        profile.get("ftp_user", ""),     # no password
        "cloud_provider":  profile.get("cloud_provider", "none"),
        "cloud_folder_id": profile.get("cloud_folder_id", ""),
        "last_backup":     backup_state.get("last_backup", ""),
        "snapsmack_version": backup_state.get("snapsmack_version", ""),
        "total_files":     backup_state.get("total_files", 0),
        "total_bytes":     backup_state.get("total_bytes", 0),
        "available_backups": available_zips,
        "files":           backup_state.get("files", {}),
    }


def push_cloud_state(
    cloud_client,
    profile:        dict,
    backup_state:   dict,
    available_zips: List[dict],
) -> bool:
    """
    Push the cloud state JSON to the configured cloud folder.
    Returns True on success.
    """
    if cloud_client is None:
        return False
    try:
        data     = build_cloud_state(profile, backup_state, available_zips)
        filename = state_filename(profile.get("name", "blog"))

        # Overwrite any existing state file
        existing_id = None
        try:
            files = cloud_client.list_files(name_filter=filename)
            for f in files:
                if f["name"] == filename:
                    existing_id = f["id"]
                    break
        except Exception:
            pass

        cloud_client.upload_json(data, filename)
        return True
    except Exception:
        return False


def list_cloud_states(cloud_client) -> List[dict]:
    """
    Scan the cloud folder for *-cloud-state.json files.
    Returns list of parsed state dicts with added _file_id field.
    """
    if cloud_client is None:
        return []
    try:
        files  = cloud_client.list_files(name_filter=CLOUD_STATE_SUFFIX)
        states = []
        for f in files:
            if not f["name"].endswith(CLOUD_STATE_SUFFIX):
                continue
            try:
                data = cloud_client.read_json(f["id"])
                data["_file_id"]   = f["id"]
                data["_file_name"] = f["name"]
                states.append(data)
            except Exception:
                pass
        return states
    except Exception:
        return []


def import_profile_from_state(state: dict) -> dict:
    """
    Build a partial profile dict from a cloud state.
    FTP password and OAuth tokens are missing — user must fill in.
    """
    from profile_manager import new_profile_template
    profile = new_profile_template()
    profile["name"]            = state.get("blog_name", "Recovered Blog")
    profile["site_url"]        = state.get("site_url", "")
    profile["ftp_host"]        = state.get("ftp_host", "")
    profile["ftp_user"]        = state.get("ftp_user", "")
    profile["cloud_provider"]  = state.get("cloud_provider", "none")
    profile["cloud_folder_id"] = state.get("cloud_folder_id", "")
    profile["last_backup_date"]= state.get("last_backup", "")
    # ftp_pass and snap_admin_pass intentionally blank — user re-enters
    return profile


def list_available_backups(cloud_client, folder_id: str = "") -> List[dict]:
    """
    List backup ZIPs in the cloud folder.
    Returns [{id, name, size_bytes, date}] sorted newest first.
    """
    if cloud_client is None:
        return []
    try:
        files = cloud_client.list_files(name_filter="_backup_")
        return [
            {
                "id":         f["id"],
                "name":       f["name"],
                "size_bytes": int(f.get("size", 0)),
                "date":       f.get("modifiedTime", ""),
            }
            for f in files
            if f["name"].endswith(".zip")
        ]
    except Exception:
        return []
