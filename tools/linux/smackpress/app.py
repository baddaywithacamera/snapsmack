# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
#!/usr/bin/env python3
"""
SMACKPRESS — Linux Chrome/Blink port.

The WINDOW is now HTML/CSS/JS drawn by Chromium instead of the customtkinter
three-pane workbench. The WORK is unchanged: this module imports the tool's own
logic modules (config, db, wp_client, smacktalk_client, ai_client) exactly as the
Windows app.py did, and re-exposes each user action as a blink.call handler.

Nothing new is invented here. Every tkinter button/menu/field in the original
app.py maps to one handler below (see README.md for the full parity table).
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                        # tools/smackpress/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")        # tools/_shared/
PKG = os.path.join(TOOL_ROOT, "smackpress")              # tools/smackpress/smackpress/

# The logic modules do `import config`, `import db`, etc. — they must resolve
# against the package dir, just like the Windows app.py put it on sys.path.
for p in (SHARED, PKG, TOOL_ROOT):
    ap = os.path.abspath(p)
    if ap not in sys.path:
        sys.path.insert(0, ap)

import snap_blink

# The tool's REAL work — reused verbatim, not reimplemented.
import config
import db
import wp_client
import smacktalk_client
import ai_client

APP_TITLE = "SMACKPRESS"
APP_VERSION = "0.1.0"

app = snap_blink.App(tool="smackpress", title=APP_TITLE,
                     web_dir=os.path.join(HERE, "web"))


# ============================================================
# Small shared shapers (mirror the tkinter helpers)
# ============================================================

def _status_badge(status: str) -> str:
    return {"publish": "●", "private": "○",
            "draft": "◌", "trash": "✕"}.get(status, "?")


def _post_row(post: dict) -> dict:
    """One navigator row, with local migration/hidden flags (as _render_list did)."""
    local = db.get_post(post["id"])
    migrated = bool(local and local["snap_post_id"])
    hidden = bool(local and local["hidden_at"])
    return {
        "id": post["id"],
        "title": post.get("title") or "(untitled)",
        "status": post.get("status", ""),
        "badge": _status_badge(post.get("status", "")),
        "migrated": migrated,
        "hidden": hidden,
    }


# ============================================================
# Handlers — one per original tkinter action
# ============================================================

@app.api
def load_state():
    """
    Everything the window needs on open. Mirrors SmackPressApp.__init__:
    load saved settings, decide whether we auto-refresh or must open Settings,
    pull the SnapSmack categories the CardStack used to fetch in the background.
    """
    cfg = config.get_all()
    configured = bool(config.get("wp_url") and config.get("snap_url"))
    categories = []
    cat_err = ""
    if configured:
        try:
            categories = smacktalk_client.get_categories()
        except Exception as e:  # non-fatal, exactly like the tkinter bg load
            cat_err = str(e)
    return {
        "app_title": APP_TITLE,
        "app_version": APP_VERSION,
        "settings": cfg,
        "configured": configured,
        "ai_available": ai_client.available(),
        "caption_from_filename": (config.get("caption_from_filename") or "1") == "1",
        "last_wp_type": config.get("last_wp_type") or "Posts",
        "last_wp_status": config.get("last_wp_status") or "publish",
        "categories": categories,
        "category_error": cat_err,
    }


@app.api
def save_settings(settings, ai_system_prompt):
    """SettingsDialog._save: persist every field + the system prompt."""
    if not isinstance(settings, dict):
        raise ValueError("settings must be an object")
    for key, value in settings.items():
        config.set(key, (value or "").strip() if isinstance(value, str) else value)
    config.set("ai_system_prompt", (ai_system_prompt or "").strip())
    return {"ok": True, "ai_available": ai_client.available()}


@app.api
def test_connections(settings=None, ai_system_prompt=None):
    """SettingsDialog._test: save current fields, then probe WP + SnapSmack."""
    if isinstance(settings, dict):
        for key, value in settings.items():
            config.set(key, (value or "").strip() if isinstance(value, str) else value)
        if ai_system_prompt is not None:
            config.set("ai_system_prompt", (ai_system_prompt or "").strip())

    results = []
    try:
        info = wp_client.test_connection()
        results.append({
            "ok": True,
            "text": "✓ WordPress: %s (WP %s)" % (
                info.get("site_name"), info.get("wp_version")),
        })
    except wp_client.WPError as e:
        results.append({"ok": False, "text": "✗ WordPress: %s" % e})
    try:
        snap_info = smacktalk_client.get_categories()
        results.append({
            "ok": True,
            "text": "✓ SnapSmack: %d categories" % len(snap_info),
        })
    except smacktalk_client.SnapError as e:
        results.append({"ok": False, "text": "✗ SnapSmack: %s" % e})
    return {"results": results}


@app.api
def list_posts(page=1, per_page=20, status="publish", search="", post_type="post"):
    """
    NavigatorPane.refresh: fetch a page of WP posts/pages and stamp each with
    local migration state. Also persists the last-used status/type filter, as
    refresh() did via config.set.
    """
    post_type = "page" if post_type in ("page", "Pages") else "post"
    config.set("last_wp_status", status)
    config.set("last_wp_type", "Pages" if post_type == "page" else "Posts")
    result = wp_client.get_posts(
        page=int(page), per_page=int(per_page),
        status=status, search=search or "", post_type=post_type,
    )
    return {
        "posts": [_post_row(p) for p in result.get("posts", [])],
        "page": result.get("page", page),
        "total_pages": result.get("total_pages", 1),
        "total": result.get("total", 0),
    }


@app.api
def load_post(wp_id):
    """
    SmackPressApp._on_post_selected + WorkingCanvas.load_post + CardStack.load_post,
    combined into the one payload the window needs to fill both centre and right panes.
    """
    wp_id = int(wp_id)
    full = wp_client.get_post(wp_id)

    # Ensure a local tracking record exists (identical to _on_post_selected).
    db.upsert_post(
        full["id"],
        wp_slug=full.get("slug", ""),
        wp_title=full.get("title", ""),
        wp_date=full.get("date", "")[:10],
        wp_status=full.get("status", "publish"),
        wp_type=full.get("type", "post"),
    )

    local = db.get_post(full["id"])
    content_raw = full.get("content_raw", "")
    draft = (local["notes"] if local and local["notes"] else content_raw)

    # Migration status text (CardStack.load_post logic).
    if local and local["snap_url"]:
        mig_text = "✓ %s" % local["snap_url"]
    elif full.get("migrated_to"):
        mig_text = "✓ %s" % full["migrated_to"]
    else:
        mig_text = "Not yet migrated"

    images = full.get("images", [])
    return {
        "id": full["id"],
        "type": full.get("type", "post"),
        "title": full.get("title", ""),
        "date": full.get("date", ""),
        "status": full.get("status", ""),
        "wp_source": full.get("content_expanded") or content_raw,
        "content_raw": content_raw,
        "draft": draft,
        "tags": " ".join(full.get("tags", [])),
        "comment_count": full.get("comment_count", 0),
        "migrated_to": full.get("migrated_to", ""),
        "migration_text": mig_text,
        "images": [
            {"id": img.get("id"), "filename": img.get("filename", "")}
            for img in images
        ],
        "image_count": len(images),
        "status_line": "Loaded: %s  —  %s" % (
            full.get("title", ""), full.get("date", "")[:10]),
    }


@app.api
def save_note(wp_id, note):
    """WorkingCanvas._on_edit: autosave the draft into the local notes column."""
    db.set_note(int(wp_id), note or "")
    return {"ok": True}


@app.api
def ai_rewrite(wp_id, content_raw, title=""):
    """
    WorkingCanvas._ai_rewrite: run the configured provider, save the result as the
    note, and hand the rewritten text back for the draft box.
    """
    if not ai_client.available():
        raise RuntimeError("Configure an AI provider in Settings first.")
    result = ai_client.rewrite(content_raw or "", title or "")
    db.set_note(int(wp_id), result)
    return {"draft": result}


@app.api
def get_categories():
    """CardStack background category fetch (name + id list)."""
    return {"categories": smacktalk_client.get_categories()}


@app.api
def set_caption_from_filename(value):
    """CardStack caption checkbox -> config."""
    config.set("caption_from_filename", "1" if value else "0")
    return {"ok": True}


@app.api
def create_mosaic(wp_id, title, images, caption_from_filename=None):
    """
    CardStack._create_mosaic: import each gallery image into the SnapSmack Gallery,
    then build a mosaic from the returned Gallery ids. Returns the shortcode the
    window appends to the draft.
    """
    if not images:
        raise RuntimeError("No gallery images found for this post.")
    if not title:
        raise RuntimeError("A mosaic title is required.")

    gallery_ids = []
    for img in images:
        url = img.get("url") or img.get("source_url") or img.get("src")
        if not url:
            continue
        up = smacktalk_client.upload_media_from_url(
            url, img.get("filename"),
            caption_from_filename=bool(caption_from_filename))
        gallery_ids.append(up["image_id"])
    if not gallery_ids:
        raise smacktalk_client.SnapError(
            "None of this post's images had a usable URL to import.")

    result = smacktalk_client.create_mosaic(title, gallery_ids)
    mosaic_id = result.get("mosaic_id")
    return {"mosaic_id": mosaic_id, "shortcode": "[mosaic:%s]" % mosaic_id}


@app.api
def push_post(wp_id, title, draft, date="", tags="", category_id=0, post_type="post"):
    """
    SmackPressApp.push_post: send the current draft to SnapSmack. Pages go to the
    page endpoint (active/visible), posts go to the post endpoint (draft). Records
    the migration locally on success. Payload logic copied verbatim.
    """
    wp_id = int(wp_id)
    draft = (draft or "").strip()
    if not draft:
        raise RuntimeError("Write something before pushing.")

    is_page = (post_type == "page")
    local = db.get_post(wp_id)

    if is_page:
        payload = {
            "title": title or "",
            "content_raw": draft,
            # Pages land active/visible: the CMS page editor has no draft/reactivate
            # toggle, so an inactive page would be stranded.
            "status": "published",
        }
        if local and local["snap_post_id"]:
            payload["page_id"] = local["snap_post_id"]
        push_fn = smacktalk_client.create_page
        id_key = "page_id"
    else:
        payload = {
            "title": title or "",
            "content_raw": draft,
            "date": (date or "")[:10],
            "tags": tags or "",
            "status": "draft",
        }
        if category_id:
            payload["category_id"] = int(category_id)
        if local and local["snap_post_id"]:
            payload["post_id"] = local["snap_post_id"]
        push_fn = smacktalk_client.create_post
        id_key = "post_id"

    result = push_fn(payload)
    snap_id = result.get(id_key)
    snap_url = result.get("url", "")
    db.mark_migrated(wp_id, snap_id, snap_url)
    kind = "page" if is_page else "post"
    return {
        "kind": kind,
        "snap_id": snap_id,
        "snap_url": snap_url,
        "status_line": "✓ Pushed %s → %s" % (kind, snap_url),
        "migration_text": "✓ %s" % snap_url,
    }


@app.api
def hide_wp(wp_id, snap_url=""):
    """CardStack._hide_wp: set the WP post to private and record the destination."""
    wp_id = int(wp_id)
    if not snap_url:
        local = db.get_post(wp_id)
        snap_url = (local["snap_url"] if local else "") or ""
    wp_client.hide_post(wp_id, snap_url)
    db.mark_hidden(wp_id)
    return {
        "ok": True,
        "migration_text": "Hidden ✓  %s" % snap_url,
        "message": "WordPress post set to private.",
    }


if __name__ == "__main__":
    app.run()

# ===== SNAPSMACK EOF =====
