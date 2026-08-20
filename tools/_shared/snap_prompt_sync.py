"""
SNAPSMACK — snap_prompt_sync.py  (fleet sync for the per-site WHOLE-POST prompt)

Sean's per-site AI prompt (one whole-post prompt that fills caption/ALT/tags in a
SINGLE call) lives two places: the CMS setting `ai_post_enrichment_prompt` on each
blog (get/set via `GET|POST gyss/prompt`), and the shared desktop pool
`snap_prompts` (keyed by site). This syncs them across the fleet so a prompt set
once is available everywhere, and any spoke's prompt can be pulled into the pool
to reuse and customise.

Transport-agnostic ON PURPOSE: pull()/push() take fetch/push CALLABLES, so the
HTTP layer (requests) lives in the caller and this merge logic is unit-testable
with no network. NON-DESTRUCTIVE by default: a remote prompt that DIFFERS from a
locally-edited pool entry is REPORTED, never silently overwritten — the operator
resolves it (push local, or accept remote). New sites are added to the pool.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

_HERE = os.path.dirname(os.path.abspath(__file__))
if _HERE not in sys.path:
    sys.path.insert(0, _HERE)

import snap_prompts
import snap_home


def site_key(profile: dict) -> str:
    """The pool key for a discovered site = its canonical HOST (via
    snap_home.site_key on the site URL), so it lines up EXACTLY with how the
    shared pool and the on-disk profiles are already keyed (by host, e.g.
    'fauxlaroid.fyi'). Preferring the profile *name* here would key by display
    title and create duplicate, mismatched pool entries on the real fleet.
    Falls back to a scheme-stripped URL, then the name, only when there is no
    usable host."""
    url = (profile.get("site_url") or profile.get("url") or "").strip()
    if url:
        try:
            return snap_home.site_key(url)
        except Exception:
            for pre in ("https://", "http://"):
                if url.startswith(pre):
                    url = url[len(pre):]
                    break
            return url.rstrip("/")
    return (profile.get("name") or "").strip()


def pull(profiles, fetch) -> dict:
    """Pull each site's whole-post prompt into the shared pool.

    fetch(profile) -> prompt string (or None / raises on failure).
    Adds NEW sites to the pool; a site already in the pool with a DIFFERENT prompt
    is reported in 'differs' and left untouched (never silently overwritten).
    Returns a report; the pool is saved only if something was added.
    """
    pool = snap_prompts.load()  # {site: text}
    added, unchanged, differs, failed = [], [], [], []
    for p in profiles:
        key = site_key(p)
        try:
            remote = fetch(p)
        except Exception as e:  # noqa: BLE001 — report, never abort the whole sync
            failed.append({"site": key, "error": str(e)})
            continue
        if remote is None:
            failed.append({"site": key, "error": "no prompt returned"})
            continue
        remote = str(remote).strip()
        if key not in pool:
            pool[key] = remote
            added.append(key)
        elif str(pool[key]).strip() == remote:
            unchanged.append(key)
        else:
            differs.append({"site": key,
                            "local_len": len(str(pool[key])),
                            "remote_len": len(remote)})
    if added:
        snap_prompts.save(pool)
    return {"added": added, "unchanged": unchanged, "differs": differs, "failed": failed}


def accept_remote(profile, prompt) -> None:
    """Resolve a difference by taking the remote prompt into the local pool."""
    pool = snap_prompts.load()
    pool[site_key(profile)] = str(prompt or "").strip()
    snap_prompts.save(pool)


def push(profile, prompt, push_fn) -> bool:
    """Push a site's prompt to its CMS (POST gyss/prompt) and mirror it locally.

    push_fn(profile, prompt) -> truthy on success. Returns True on success; the
    local pool is updated to match only when the push succeeds.
    """
    text = str(prompt or "").strip()
    ok = bool(push_fn(profile, text))
    if ok:
        pool = snap_prompts.load()
        pool[site_key(profile)] = text
        snap_prompts.save(pool)
    return ok
# ===== SNAPSMACK EOF =====
