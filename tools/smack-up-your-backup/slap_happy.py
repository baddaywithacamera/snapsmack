"""Local SNAP SLAPPER discovery and versioned backup packages for SUYB."""

# SNAPSMACK_EOF_HEADER

from __future__ import annotations

import hashlib
import json
import os
import tempfile
import zipfile
from datetime import datetime, timezone


COMPONENTS = ("suyb_settings", "settings", "catalog", "photos", "projects")
PROJECT_EXTENSIONS = {".slapper", ".slaprecipe"}
SECRET_WORDS = ("password", "passwd", "secret", "token", "api_key", "key_id",
                "app_key", "credential", "private_key")


def _atomic_json(path: str, value: dict) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=".slap-happy-", suffix=".tmp",
                                     dir=os.path.dirname(path), text=True)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(value, handle, indent=2, sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    except Exception:
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise


def _read_json(path: str, default):
    try:
        with open(path, "r", encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError, TypeError):
        return default


def discover_contract(home: str) -> dict:
    """Read SNAP SLAPPER's handoff, with a safe fallback for older builds."""
    home = os.path.abspath(home)
    config_dir = os.path.join(home, "config_files", "snap-slapper")
    contract_path = os.path.join(config_dir, "backup_contract.json")
    contract = _read_json(contract_path, {})
    folders = contract.get("image_roots") if isinstance(contract, dict) else None
    if not isinstance(folders, list):
        state = _read_json(os.path.join(config_dir, "library_folders.json"), {})
        if isinstance(state, dict):
            folders = state.get("folders", [])
        else:
            folders = state if isinstance(state, list) else []
    roots = []
    for folder in folders:
        if isinstance(folder, str) and os.path.isdir(folder):
            absolute = os.path.abspath(folder)
            if absolute not in roots:
                roots.append(absolute)
    return {
        "contract_path": contract_path,
        "config_dir": os.path.abspath(contract.get("settings_dir", config_dir))
            if isinstance(contract, dict) else config_dir,
        "catalog_dir": os.path.abspath(contract.get(
            "catalog_dir", os.path.join(home, "shared_library")))
            if isinstance(contract, dict) else os.path.join(home, "shared_library"),
        "image_roots": roots,
        "saved_roots": [os.path.abspath(p) for p in contract.get("saved_roots", [])
                        if isinstance(p, str) and os.path.isdir(p)]
            if isinstance(contract, dict) else [],
    }


def _walk(root: str):
    if not os.path.isdir(root):
        return
    for base, dirs, names in os.walk(root):
        dirs[:] = sorted((d for d in dirs if not d.startswith(".")), key=str.lower)
        for name in sorted(names, key=str.lower):
            if not name.startswith("."):
                yield os.path.join(base, name)


def selected_files(contract: dict, components) -> list[dict]:
    selected = set(components) & set(COMPONENTS)
    records, seen = [], set()

    def add(component, root, path, prefix, predicate=None):
        for source in _walk(root) or ():
            if predicate and not predicate(source):
                continue
            identity = os.path.normcase(os.path.realpath(source))
            if identity in seen:
                continue
            seen.add(identity)
            relative = os.path.relpath(source, root).replace(os.sep, "/")
            records.append({"component": component, "source": source,
                            "archive": f"{prefix}/{relative}"})

    if "settings" in selected:
        add("settings", contract["config_dir"], contract["config_dir"], "settings",
            lambda p: "thumbnail_cache" not in p.split(os.sep))
    if "catalog" in selected:
        add("catalog", contract["catalog_dir"], contract["catalog_dir"], "catalog")
    photo_roots = list(contract["image_roots"])
    saved_roots = [root for root in contract.get("saved_roots", []) if root not in photo_roots]
    for index, root in enumerate(photo_roots, 1):
        prefix = f"photos/root-{index:03d}"
        if "photos" in selected:
            add("photos", root, root, prefix,
                lambda p: os.path.splitext(p)[1].lower() not in PROJECT_EXTENSIONS)
        if "projects" in selected:
            add("projects", root, root, f"projects/root-{index:03d}",
                lambda p: os.path.splitext(p)[1].lower() in PROJECT_EXTENSIONS)
    for index, root in enumerate(saved_roots, 1):
        if "photos" in selected:
            add("photos", root, root, f"saved-images/root-{index:03d}",
                lambda p: os.path.splitext(p)[1].lower() not in PROJECT_EXTENSIONS)
        if "projects" in selected:
            add("projects", root, root, f"saved-projects/root-{index:03d}",
                lambda p: os.path.splitext(p)[1].lower() in PROJECT_EXTENSIONS)
    return sorted(records, key=lambda row: row["archive"].lower())


def _fingerprint(path: str) -> dict:
    stat = os.stat(path)
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return {"size": stat.st_size, "mtime_ns": stat.st_mtime_ns,
            "sha256": digest.hexdigest()}


def _sanitized(value):
    if isinstance(value, dict):
        return {key: ("[REDACTED]" if any(word in key.lower() for word in SECRET_WORDS)
                      else _sanitized(item)) for key, item in value.items()}
    if isinstance(value, list):
        return [_sanitized(item) for item in value]
    return value


def suyb_settings_snapshot(home: str) -> bytes:
    """Portable SUYB preferences/profile shape, deliberately excluding secrets."""
    root = os.path.join(os.path.abspath(home), "config_files", "suyb")
    snapshot = {"format": 1, "notice": "Credentials and OAuth tokens are excluded.",
                "config_ini": "", "profiles": [], "sync_jobs": []}
    try:
        with open(os.path.join(root, "config.ini"), "r", encoding="utf-8") as handle:
            snapshot["config_ini"] = handle.read()
    except OSError:
        pass
    for bucket, field in (("profiles", "profiles"), ("sync_jobs", "sync_jobs")):
        directory = os.path.join(root, bucket)
        if not os.path.isdir(directory):
            continue
        for name in sorted(os.listdir(directory), key=str.lower):
            if not name.lower().endswith(".json"):
                continue
            value = _read_json(os.path.join(directory, name), None)
            if value is not None:
                snapshot[field].append({"file": name, "value": _sanitized(value)})
    return json.dumps(snapshot, indent=2, sort_keys=True).encode("utf-8")


def create_backup(home: str, output_dir: str, mode="incremental", components=COMPONENTS,
                  state_path: str | None = None, destination_key="local",
                  commit=True, on_progress=None) -> dict:
    """Create a self-describing full or incremental ZIP. State advances atomically."""
    if mode not in {"full", "incremental"}:
        raise ValueError("mode must be 'full' or 'incremental'")
    chosen = tuple(c for c in COMPONENTS if c in set(components))
    if not chosen:
        raise ValueError("Pick at least one SNAP SLAPPER component.")
    contract = discover_contract(home)
    files = selected_files(contract, chosen)
    if "suyb_settings" in chosen:
        payload = suyb_settings_snapshot(home)
        files.append({"component": "suyb_settings", "source": None,
                      "archive": "suyb-settings/SUYB-SETTINGS.json", "data": payload})
    state_path = state_path or os.path.join(home, "config_files", "suyb",
                                            "slap_happy_manifest.json")
    all_state = _read_json(state_path, {})
    safe_destination = hashlib.sha256(str(destination_key).encode("utf-8")).hexdigest()[:16]
    key = safe_destination + ":" + "+".join(chosen)
    previous = all_state.get(key, {}).get("files", {}) if mode == "incremental" else {}
    current, changed = {}, []
    total = len(files)
    for number, record in enumerate(files, 1):
        if "data" in record:
            fingerprint = {"size": len(record["data"]),
                           "sha256": hashlib.sha256(record["data"]).hexdigest()}
        else:
            fingerprint = _fingerprint(record["source"])
        current[record["archive"]] = fingerprint
        if previous.get(record["archive"]) != fingerprint or mode == "full":
            changed.append(record)
        if on_progress:
            on_progress("Scanning", number, total)
    deleted = sorted(set(previous) - set(current))
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    os.makedirs(output_dir, exist_ok=True)
    target = os.path.join(output_dir, f"SLAP-HAPPY-{mode}-{stamp}.zip")
    fd, temporary = tempfile.mkstemp(prefix=".slap-happy-", suffix=".zip",
                                     dir=output_dir)
    os.close(fd)
    manifest = {"format": 1, "created_utc": stamp, "mode": mode,
                "components": list(chosen), "home": os.path.abspath(home),
                "files_in_snapshot": len(current), "files_in_package": len(changed),
                "deleted_since_previous": deleted, "snapshot": current}
    try:
        with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED,
                             allowZip64=True) as archive:
            archive.writestr("SLAP-HAPPY-MANIFEST.json",
                             json.dumps(manifest, indent=2, sort_keys=True))
            for number, record in enumerate(changed, 1):
                if "data" in record:
                    archive.writestr(record["archive"], record["data"])
                else:
                    archive.write(record["source"], record["archive"])
                if on_progress:
                    on_progress("Packing", number, len(changed))
        os.replace(temporary, target)
        if commit:
            all_state[key] = {"updated_utc": stamp, "files": current}
            _atomic_json(state_path, all_state)
    except Exception:
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise
    return {"path": target, "manifest": manifest, "changed": len(changed),
            "deleted": len(deleted), "total": len(current),
            "_state_path": state_path, "_state_key": key, "_state": current,
            "_stamp": stamp}


def commit_backup_state(result: dict) -> None:
    """Advance an incremental baseline only after its destination is durable."""
    state_path = result["_state_path"]
    value = _read_json(state_path, {})
    value[result["_state_key"]] = {"updated_utc": result["_stamp"],
                                    "files": result["_state"]}
    _atomic_json(state_path, value)

# ===== SNAPSMACK EOF =====
