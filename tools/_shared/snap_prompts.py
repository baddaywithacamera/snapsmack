"""
SNAPSMACK — snap_prompts.py  (the ONE cross-tool Gemini prompt-preset store)

Every SnapSmack tool that talks to Gemini (SYBU, COLD SNAP) kept its user-saved
prompt presets in its OWN folder — config_files/sybu/gemini_prompts.json,
config_files/coldsnap/gemini_prompts.json — so a prompt written in one tool was
invisible to the other, and worse, they were LOST when an install moved (the new
exe looked next to itself, not at the old folder — Sean lost ~18 KB of presets
exactly this way, 2026-08-13). This is the shared store that fixes both: one file

    <root>\\shared_library\\prompts\\gemini_prompts.json

a flat dict of {preset_name: prompt_text}, holding USER presets only (each tool
still ships its own built-in defaults and merges them under whatever's here). Set
a prompt up once; every Gemini tool sees it, and it survives a reinstall.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os

# snap_home is a sibling in tools/_shared; tools put _shared on sys.path before
# importing this, exactly as they do for snap_home / snap_profiles.
from snap_home import prompts_dir


def _store_path() -> str:
    return os.path.join(prompts_dir(), "gemini_prompts.json")


def load() -> dict:
    """User presets as {name: text} — the flat shape every Gemini tool expects.

    Entries may be stored either flat ("name": "text") or TYPED
    ("name": {"type": "title|caption|hashtags|general", "text": "..."}) so the
    same pool can round-trip with the CMS, which keeps prompts per field. Typed
    entries are flattened to their text here, so SYBU / COLD SNAP keep working
    unchanged. {} when unset or unreadable."""
    try:
        with open(_store_path(), encoding="utf-8") as f:
            data = json.load(f)
        if not isinstance(data, dict):
            return {}
        return {name: (val.get("text", "") if isinstance(val, dict) else val)
                for name, val in data.items()}
    except Exception:
        return {}


def load_typed() -> dict:
    """User presets as {name: {"type": ..., "text": ...}} — for cross-store sync
    with the CMS. A plain-string entry reads as {"type": "general", "text": ...}."""
    try:
        with open(_store_path(), encoding="utf-8") as f:
            data = json.load(f)
        if not isinstance(data, dict):
            return {}
        out = {}
        for name, val in data.items():
            if isinstance(val, dict):
                out[name] = {"type": val.get("type", "general") or "general",
                             "text": val.get("text", "")}
            else:
                out[name] = {"type": "general", "text": val}
        return out
    except Exception:
        return {}


def save(prompts: dict) -> None:
    """Persist the user-preset dict (atomic; never leaves a half-written file).

    If an existing store is present but UNREADABLE (corrupt / not a dict), move it
    aside to a `.corrupt` backup before overwriting — so a single bad read (which
    load() reports as {}) can never irrecoverably wipe every saved preset. A caller
    that merges onto load()'s {} would otherwise write an empty store over good data."""
    os.makedirs(prompts_dir(), exist_ok=True)
    path = _store_path()
    existing = {}
    if os.path.isfile(path):
        try:
            with open(path, encoding="utf-8") as f:
                loaded = json.load(f)
            readable = isinstance(loaded, dict)
            if readable:
                existing = loaded
        except Exception:
            readable = False
        if not readable:
            # Preserve the FIRST corrupt copy — a second corruption must not overwrite
            # the backup that most likely holds the recoverable data.
            backup = path + ".corrupt"
            try:
                if not os.path.exists(backup):
                    os.replace(path, backup)
            except Exception:
                pass

    # Type-preserving write: a caller (e.g. SYBU / COLD SNAP) that only sees flat
    # text via load() and saves it back must NOT strip the field types other
    # stores rely on. A value passed as a string keeps its previously-stored type;
    # a value passed as a dict {type,text} is stored as-is.
    src = prompts if isinstance(prompts, dict) else {}
    out = {}
    for name, val in src.items():
        if isinstance(val, dict):
            out[name] = val
        else:
            prev = existing.get(name)
            if isinstance(prev, dict) and prev.get("type"):
                out[name] = {"type": prev["type"], "text": val}
            else:
                out[name] = val

    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(out, f, indent=2, ensure_ascii=False)
    os.replace(tmp, path)


def migrate_in(legacy: dict, overwrite: bool = False) -> int:
    """Fold a tool's legacy per-tool presets into the shared store. Returns the
    number of presets added. By default an existing name is kept (a tool never
    clobbers a preset another tool already contributed)."""
    if not isinstance(legacy, dict) or not legacy:
        return 0
    cur = load()
    added = 0
    for name, text in legacy.items():
        if overwrite or name not in cur:
            cur[name] = text
            added += 1
    if added:
        save(cur)
    return added
# ===== SNAPSMACK EOF =====
