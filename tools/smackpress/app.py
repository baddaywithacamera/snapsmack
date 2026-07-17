# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
"""
SmackPress — app.py
Three-pane customtkinter workbench for one-post-at-a-time WP → SnapSmack migration.

Layout
------
┌──────────────┬─────────────────────────────────┬──────────────────┐
│  NAVIGATOR   │         WORKING CANVAS           │   CARD STACK     │
│  post list   │  ┌──────────────────────────┐   │  draft preview   │
│  + filters   │  │  WordPress source (top)  │   │  + mosaic tools  │
│              │  ├──────────────────────────┤   │                  │
│              │  │  SMACKTALK preview (bot) │   │                  │
│              │  └──────────────────────────┘   │                  │
└──────────────┴─────────────────────────────────┴──────────────────┘

# ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import sys
import threading
import tkinter as tk
import tkinter.messagebox as mb
import tkinter.simpledialog as sd
from pathlib import Path
from typing import Any

try:
    import customtkinter as ctk
except ImportError:
    print("customtkinter not found.  Run:  pip install customtkinter")
    sys.exit(1)

# Ensure the smackpress package directory is on the path when running directly
_HERE = Path(__file__).resolve().parent
_PKG = _HERE / "smackpress"
if str(_PKG) not in sys.path:
    sys.path.insert(0, str(_PKG))

import config
import db
import wp_client
import smacktalk_client
import ai_client

# ============================================================
# App constants
# ============================================================

APP_TITLE   = "SMACKPRESS"
APP_VERSION = "0.1.0"
NAV_WIDTH   = 280
CARD_WIDTH  = 320
MIN_HEIGHT  = 700


# ============================================================
# Helpers
# ============================================================

def _status_badge(status: str) -> str:
    return {"publish": "●", "private": "○", "draft": "◌", "trash": "✕"}.get(status, "?")


def _run_in_thread(fn, *args, callback=None):
    """Run fn(*args) in a daemon thread; call callback(result) on main thread."""
    def _worker():
        try:
            result = fn(*args)
        except Exception as e:
            result = e
        if callback:
            # Schedule on main thread
            app._root.after(0, lambda: callback(result))
    t = threading.Thread(target=_worker, daemon=True)
    t.start()
    return t


# ============================================================
# Settings dialog
# ============================================================

class SettingsDialog(ctk.CTkToplevel):
    def __init__(self, parent):
        super().__init__(parent)
        self.title("SMACKPRESS — Settings")
        self.geometry("560x680")
        self.resizable(False, False)
        self.grab_set()

        ctk.CTkLabel(self, text="SMACKPRESS Settings",
                     font=ctk.CTkFont(size=16, weight="bold")).pack(pady=(20, 10))

        frame = ctk.CTkScrollableFrame(self, width=500, height=520)
        frame.pack(padx=20, pady=5, fill="both", expand=True)

        self._entries = {}

        fields = [
            ("WordPress", None),
            ("wp_url",          "WordPress site URL"),
            ("wp_user",         "WordPress username"),
            ("wp_app_password", "Application Password"),
            ("SnapSmack", None),
            ("snap_url",        "SnapSmack site URL"),
            ("snap_api_key",    "SMACKPRESS API key"),
            ("AI (optional)", None),
            ("ai_provider",     "Provider (none | gemini | openai | anthropic)"),
            ("ai_model",        "Model (leave blank for default)"),
            ("ai_api_key",      "AI API key"),
        ]

        for key, label in fields:
            if label is None:
                ctk.CTkLabel(frame, text=key,
                             font=ctk.CTkFont(weight="bold"),
                             anchor="w").pack(fill="x", padx=5, pady=(12, 2))
                continue
            ctk.CTkLabel(frame, text=label, anchor="w").pack(fill="x", padx=5)
            e = ctk.CTkEntry(frame, width=460)
            e.insert(0, config.get(key))
            if "password" in key or "api_key" in key:
                e.configure(show="•")
            e.pack(padx=5, pady=2)
            self._entries[key] = e

        # AI system prompt
        ctk.CTkLabel(frame, text="AI system prompt", anchor="w").pack(fill="x", padx=5, pady=(8, 2))
        self._prompt_box = ctk.CTkTextbox(frame, width=460, height=80)
        self._prompt_box.insert("0.0", config.get("ai_system_prompt"))
        self._prompt_box.pack(padx=5, pady=2)

        btn_frame = ctk.CTkFrame(self, fg_color="transparent")
        btn_frame.pack(pady=10)
        ctk.CTkButton(btn_frame, text="Save", command=self._save).pack(side="left", padx=5)
        ctk.CTkButton(btn_frame, text="Test connections",
                      command=self._test).pack(side="left", padx=5)
        ctk.CTkButton(btn_frame, text="Cancel",
                      fg_color="gray40",
                      command=self.destroy).pack(side="left", padx=5)

    def _save(self):
        for key, entry in self._entries.items():
            config.set(key, entry.get().strip())
        config.set("ai_system_prompt", self._prompt_box.get("0.0", "end").strip())
        mb.showinfo("Settings", "Settings saved.", parent=self)
        self.destroy()

    def _test(self):
        # Save first
        for key, entry in self._entries.items():
            config.set(key, entry.get().strip())

        results = []
        try:
            info = wp_client.test_connection()
            results.append(f"✓ WordPress: {info.get('site_name')} (WP {info.get('wp_version')})")
        except wp_client.WPError as e:
            results.append(f"✗ WordPress: {e}")
        try:
            snap_info = smacktalk_client.get_categories()
            results.append(f"✓ SnapSmack: {len(snap_info)} categories")
        except smacktalk_client.SnapError as e:
            results.append(f"✗ SnapSmack: {e}")

        mb.showinfo("Connection test", "\n".join(results), parent=self)


# ============================================================
# Navigator pane (left)
# ============================================================

class NavigatorPane(ctk.CTkFrame):
    def __init__(self, parent, on_select):
        super().__init__(parent, width=NAV_WIDTH, corner_radius=0)
        self.on_select = on_select
        self._posts    = []
        self._page     = 1
        self._total_pages = 1

        # Header
        hdr = ctk.CTkFrame(self, fg_color="transparent")
        hdr.pack(fill="x", padx=8, pady=(8, 4))
        ctk.CTkLabel(hdr, text="WordPress",
                     font=ctk.CTkFont(weight="bold")).pack(side="left")
        ctk.CTkButton(hdr, text="⟳", width=30,
                      command=self.refresh).pack(side="right")

        # Type filter (Posts vs Pages)
        type_frame = ctk.CTkFrame(self, fg_color="transparent")
        type_frame.pack(fill="x", padx=8, pady=(2, 0))
        ctk.CTkLabel(type_frame, text="Type:").pack(side="left")
        self._type_var = ctk.StringVar(value=config.get("last_wp_type") or "Posts")
        type_menu = ctk.CTkOptionMenu(type_frame,
                                      values=["Posts", "Pages"],
                                      variable=self._type_var,
                                      command=lambda _: self._reset())
        type_menu.pack(side="left", padx=4)

        # Status filter
        filter_frame = ctk.CTkFrame(self, fg_color="transparent")
        filter_frame.pack(fill="x", padx=8, pady=2)
        ctk.CTkLabel(filter_frame, text="Status:").pack(side="left")
        self._status_var = ctk.StringVar(value=config.get("last_wp_status") or "publish")
        status_menu = ctk.CTkOptionMenu(filter_frame,
                                        values=["publish", "private", "draft", "any"],
                                        variable=self._status_var,
                                        command=lambda _: self._reset())
        status_menu.pack(side="left", padx=4)

        # Search
        search_frame = ctk.CTkFrame(self, fg_color="transparent")
        search_frame.pack(fill="x", padx=8, pady=2)
        self._search_var = ctk.StringVar()
        search_entry = ctk.CTkEntry(search_frame, textvariable=self._search_var,
                                    placeholder_text="Search…")
        search_entry.pack(side="left", fill="x", expand=True)
        search_entry.bind("<Return>", lambda _: self._reset())
        ctk.CTkButton(search_frame, text="Go", width=36,
                      command=self._reset).pack(side="left", padx=2)

        # Post list
        self._list_frame = ctk.CTkScrollableFrame(self)
        self._list_frame.pack(fill="both", expand=True, padx=4, pady=4)

        # Pagination
        pg_frame = ctk.CTkFrame(self, fg_color="transparent")
        pg_frame.pack(fill="x", padx=8, pady=4)
        ctk.CTkButton(pg_frame, text="◀", width=36,
                      command=self._prev_page).pack(side="left")
        self._page_label = ctk.CTkLabel(pg_frame, text="1 / 1")
        self._page_label.pack(side="left", expand=True)
        ctk.CTkButton(pg_frame, text="▶", width=36,
                      command=self._next_page).pack(side="right")

        self._loading_label = ctk.CTkLabel(self._list_frame, text="Loading…")

    def _reset(self):
        self._page = 1
        self.refresh()

    def _post_type(self) -> str:
        return "page" if self._type_var.get() == "Pages" else "post"

    def refresh(self):
        config.set("last_wp_status", self._status_var.get())
        config.set("last_wp_type", self._type_var.get())
        for w in self._list_frame.winfo_children():
            w.destroy()
        self._loading_label = ctk.CTkLabel(self._list_frame, text="Loading…")
        self._loading_label.pack()

        def _load():
            return wp_client.get_posts(
                page=self._page,
                per_page=20,
                status=self._status_var.get(),
                search=self._search_var.get(),
                post_type=self._post_type(),
            )

        def _done(result):
            if isinstance(result, Exception):
                mb.showerror("WordPress", str(result))
                for w in self._list_frame.winfo_children():
                    w.destroy()
                return
            self._posts        = result["posts"]
            self._total_pages  = result["total_pages"]
            self._page_label.configure(
                text=f"{self._page} / {self._total_pages}"
            )
            self._render_list()

        _run_in_thread(_load, callback=_done)

    def _render_list(self):
        for w in self._list_frame.winfo_children():
            w.destroy()

        for post in self._posts:
            local  = db.get_post(post["id"])
            migrated = bool(local and local["snap_post_id"])
            hidden   = bool(local and local["hidden_at"])

            badge = "✓ " if migrated else ""
            color = "#2a5" if migrated else ("gray50" if hidden else None)

            row = ctk.CTkFrame(self._list_frame, fg_color="transparent",
                               cursor="hand2")
            row.pack(fill="x", pady=1)

            title_text = f"{badge}{_status_badge(post['status'])} {post['title'] or '(untitled)'}"
            lbl = ctk.CTkLabel(row, text=title_text, anchor="w",
                               wraplength=NAV_WIDTH - 20,
                               text_color=color)
            lbl.pack(fill="x", padx=4, pady=2)

            # Bind click
            _p = post  # capture
            row.bind("<Button-1>", lambda e, p=_p: self.on_select(p))
            lbl.bind("<Button-1>",  lambda e, p=_p: self.on_select(p))

    def _prev_page(self):
        if self._page > 1:
            self._page -= 1
            self.refresh()

    def _next_page(self):
        if self._page < self._total_pages:
            self._page += 1
            self.refresh()


# ============================================================
# Working canvas (center)
# ============================================================

class WorkingCanvas(ctk.CTkFrame):
    def __init__(self, parent, on_content_changed):
        super().__init__(parent, corner_radius=0)
        self.on_content_changed = on_content_changed
        self._post = None

        # Top: WP source
        ctk.CTkLabel(self, text="WordPress source",
                     font=ctk.CTkFont(weight="bold"),
                     anchor="w").pack(fill="x", padx=8, pady=(8, 2))
        self._wp_box = ctk.CTkTextbox(self, height=300, wrap="word")
        self._wp_box.pack(fill="both", expand=True, padx=8, pady=(0, 4))
        self._wp_box.configure(state="disabled")

        # Divider toolbar
        div = ctk.CTkFrame(self, fg_color="gray25", height=36)
        div.pack(fill="x", padx=0, pady=2)
        div.pack_propagate(False)

        ctk.CTkLabel(div, text="SMACKTALK draft",
                     font=ctk.CTkFont(weight="bold"),
                     text_color="white").pack(side="left", padx=10)

        ctk.CTkButton(div, text="✦ AI rewrite", width=100,
                      command=self._ai_rewrite).pack(side="left", padx=4)
        ctk.CTkButton(div, text="⟳ Reset", width=70,
                      fg_color="gray40",
                      command=self._reset_draft).pack(side="left", padx=2)
        ctk.CTkButton(div, text="→ SnapSmack", width=110,
                      fg_color="#1a6a2a",
                      command=self._push_to_snapsmack).pack(side="right", padx=10)

        # Bottom: editable SMACKTALK draft
        self._snap_box = ctk.CTkTextbox(self, height=300, wrap="word")
        self._snap_box.pack(fill="both", expand=True, padx=8, pady=(4, 8))
        self._snap_box.bind("<KeyRelease>", self._on_edit)

        # Status bar
        self._status_var = ctk.StringVar(value="Select a post from the navigator.")
        ctk.CTkLabel(self, textvariable=self._status_var,
                     anchor="w", text_color="gray60").pack(fill="x", padx=8, pady=(0, 4))

    def load_post(self, post: dict, full_post: dict):
        """Load WP post data; full_post has content_expanded + images."""
        self._post      = full_post
        self._wp_images = full_post.get("images", [])

        self._wp_box.configure(state="normal")
        self._wp_box.delete("0.0", "end")
        self._wp_box.insert("0.0", full_post.get("content_expanded") or
                             full_post.get("content_raw", ""))
        self._wp_box.configure(state="disabled")

        # Populate draft: existing local notes or WP content
        local = db.get_post(post["id"])
        draft = (local["notes"] if local and local["notes"] else
                 full_post.get("content_raw", ""))
        self._snap_box.delete("0.0", "end")
        self._snap_box.insert("0.0", draft)

        self._status_var.set(
            f"Loaded: {post.get('title', '')}  —  {post.get('date', '')[:10]}"
        )
        self.on_content_changed()

    def get_draft(self) -> str:
        return self._snap_box.get("0.0", "end").strip()

    def set_draft(self, text: str):
        self._snap_box.delete("0.0", "end")
        self._snap_box.insert("0.0", text)
        self.on_content_changed()

    def _on_edit(self, _event=None):
        if self._post:
            db.set_note(self._post["id"], self.get_draft())
        self.on_content_changed()

    def _reset_draft(self):
        if not self._post:
            return
        self._snap_box.delete("0.0", "end")
        self._snap_box.insert("0.0", self._post.get("content_raw", ""))
        self.on_content_changed()

    def _ai_rewrite(self):
        if not self._post:
            return
        if not ai_client.available():
            mb.showinfo("AI rewrite", "Configure an AI provider in Settings first.")
            return
        self._status_var.set("⏳ AI rewriting…")

        def _rewrite():
            return ai_client.rewrite(
                self._post.get("content_raw", ""),
                self._post.get("title", ""),
            )

        def _done(result):
            if isinstance(result, ai_client.AIError):
                mb.showerror("AI error", str(result))
                self._status_var.set("AI rewrite failed.")
                return
            if isinstance(result, Exception):
                mb.showerror("Error", str(result))
                self._status_var.set("Error.")
                return
            self.set_draft(result)
            if self._post:
                db.set_note(self._post["id"], result)
            self._status_var.set("✓ AI rewrite applied.")

        _run_in_thread(_rewrite, callback=_done)

    def _push_to_snapsmack(self):
        if not self._post:
            return
        # Delegate to the app-level push handler
        app.push_post()

# ============================================================
# Card stack (right pane)
# ============================================================

class CardStack(ctk.CTkFrame):
    def __init__(self, parent):
        super().__init__(parent, width=CARD_WIDTH, corner_radius=0)
        self._post   = None
        self._images = []

        ctk.CTkLabel(self, text="Post details",
                     font=ctk.CTkFont(weight="bold")).pack(pady=(10, 4))

        # Meta card
        self._meta_frame = ctk.CTkFrame(self)
        self._meta_frame.pack(fill="x", padx=8, pady=4)
        self._meta_text = ctk.CTkTextbox(self._meta_frame, height=120,
                                          wrap="word", state="disabled")
        self._meta_text.pack(fill="x", padx=4, pady=4)

        # Tags entry
        ctk.CTkLabel(self, text="Tags (space-separated)", anchor="w").pack(
            fill="x", padx=12)
        self._tags_var = ctk.StringVar()
        ctk.CTkEntry(self, textvariable=self._tags_var).pack(
            fill="x", padx=12, pady=2)

        # Category
        ctk.CTkLabel(self, text="Category", anchor="w").pack(fill="x", padx=12)
        self._cat_var = ctk.StringVar(value="")
        self._cat_menu = ctk.CTkOptionMenu(self, values=["(none)"],
                                            variable=self._cat_var)
        self._cat_menu.pack(fill="x", padx=12, pady=2)

        # Mosaic tools
        ctk.CTkLabel(self, text="Gallery images",
                     font=ctk.CTkFont(weight="bold")).pack(pady=(12, 2))
        self._img_frame = ctk.CTkScrollableFrame(self, height=200)
        self._img_frame.pack(fill="x", padx=8, pady=4)

        self._caption_fn_var = ctk.BooleanVar(
            value=(config.get("caption_from_filename") or "1") == "1")
        ctk.CTkCheckBox(self, text="Caption images from filename",
                        variable=self._caption_fn_var,
                        command=lambda: config.set(
                            "caption_from_filename",
                            "1" if self._caption_fn_var.get() else "0")
                        ).pack(padx=8, pady=(2, 4), fill="x")

        ctk.CTkButton(self, text="Create mosaic from gallery",
                      command=self._create_mosaic).pack(padx=8, pady=4, fill="x")

        # Migration status
        ctk.CTkLabel(self, text="Migration status",
                     font=ctk.CTkFont(weight="bold")).pack(pady=(8, 2))
        self._mig_label = ctk.CTkLabel(self, text="—", wraplength=CARD_WIDTH - 20)
        self._mig_label.pack(padx=8)

        ctk.CTkButton(self, text="Hide WP post (mark migrated)",
                      fg_color="gray40",
                      command=self._hide_wp).pack(padx=8, pady=(4, 12), fill="x")

        # Load categories in background
        _run_in_thread(smacktalk_client.get_categories,
                       callback=self._set_categories)

    def _set_categories(self, result):
        if isinstance(result, Exception):
            return
        names = ["(none)"] + [c["name"] for c in result]
        self._cat_menu.configure(values=names)
        self._categories = {c["name"]: c["id"] for c in result}

    def load_post(self, post: dict, full_post: dict):
        self._post   = full_post
        self._images = full_post.get("images", [])

        # Meta
        self._meta_text.configure(state="normal")
        self._meta_text.delete("0.0", "end")
        self._meta_text.insert("0.0",
            f"ID:     {full_post['id']}\n"
            f"Date:   {full_post.get('date', '')[:10]}\n"
            f"Status: {full_post.get('status', '')}\n"
            f"Images: {len(self._images)}\n"
            f"Comments: {full_post.get('comment_count', 0)}\n"
            + (f"Migrated: yes" if full_post.get('migrated_to') else "")
        )
        self._meta_text.configure(state="disabled")

        # Tags
        self._tags_var.set(" ".join(full_post.get("tags", [])))

        # Migration status
        local = db.get_post(full_post["id"])
        if local and local["snap_url"]:
            self._mig_label.configure(text=f"✓ {local['snap_url']}")
        elif full_post.get("migrated_to"):
            self._mig_label.configure(text=f"✓ {full_post['migrated_to']}")
        else:
            self._mig_label.configure(text="Not yet migrated")

        # Images
        for w in self._img_frame.winfo_children():
            w.destroy()
        for img in self._images[:20]:  # cap at 20 for speed
            row = ctk.CTkLabel(self._img_frame,
                               text=f"#{img['id']} {img.get('filename', '')[-30:]}",
                               anchor="w")
            row.pack(fill="x", padx=2)

    def get_tags(self) -> str:
        return self._tags_var.get().strip()

    def get_category_id(self) -> int:
        name = self._cat_var.get()
        return getattr(self, "_categories", {}).get(name, 0)

    def _create_mosaic(self):
        if not self._post or not self._images:
            mb.showinfo("Mosaic", "No gallery images found for this post.")
            return

        title = sd.askstring("Mosaic title",
                             "Enter a title for this mosaic:",
                             initialvalue=self._post.get("title", ""))
        if not title:
            return

        def _make():
            # Download each WordPress image and ingest it into the SnapSmack Gallery,
            # collecting the returned Gallery image ids. The mosaic renderer resolves
            # these ids against snap_images, so they must be Gallery ids — not WP ids.
            gallery_ids = []
            for img in self._images:
                url = img.get("url") or img.get("source_url") or img.get("src")
                if not url:
                    continue
                up = smacktalk_client.upload_media_from_url(
                    url, img.get("filename"),
                    caption_from_filename=self._caption_fn_var.get())
                gallery_ids.append(up["image_id"])
            if not gallery_ids:
                raise smacktalk_client.SnapError(
                    "None of this post's images had a usable URL to import."
                )
            return smacktalk_client.create_mosaic(title, gallery_ids)

        def _done(result):
            if isinstance(result, Exception):
                mb.showerror("Mosaic", str(result))
                return
            mosaic_id = result.get("mosaic_id")
            shortcode = f"[mosaic:{mosaic_id}]"
            # Insert shortcode at cursor in draft
            app.canvas.set_draft(app.canvas.get_draft() + f"\n\n{shortcode}")
            mb.showinfo("Mosaic created",
                        f"Mosaic #{mosaic_id} created.\n{shortcode} added to draft.")

        _run_in_thread(_make, callback=_done)

    def _hide_wp(self):
        if not self._post:
            return
        local = db.get_post(self._post["id"])
        snap_url = local["snap_url"] if local else ""
        if not snap_url:
            snap_url = sd.askstring("SnapSmack URL",
                                    "Enter the SnapSmack post URL to record:",
                                    initialvalue="") or ""

        def _hide():
            return wp_client.hide_post(self._post["id"], snap_url)

        def _done(result):
            if isinstance(result, Exception):
                mb.showerror("Hide post", str(result))
                return
            db.mark_hidden(self._post["id"])
            self._mig_label.configure(text=f"Hidden ✓  {snap_url}")
            mb.showinfo("Done", "WordPress post set to private.")

        if mb.askyesno("Hide post",
                       "Set this WordPress post to private?\n"
                       "This cannot be undone without going into WP admin."):
            _run_in_thread(_hide, callback=_done)


# ============================================================
# Main application window
# ============================================================

class SmackPressApp:
    def __init__(self):
        ctk.set_appearance_mode("dark")
        ctk.set_default_color_theme("dark-blue")

        self._root = ctk.CTk()
        self._root.title(f"{APP_TITLE} {APP_VERSION}")
        w = int(config.get("window_width") or 1400)
        h = int(config.get("window_height") or 900)
        self._root.geometry(f"{w}x{h}")
        self._root.minsize(900, MIN_HEIGHT)
        self._root.protocol("WM_DELETE_WINDOW", self._on_close)

        self._current_post_summary = None
        self._current_post_full    = None

        self._build_menu()
        self._build_layout()

        # Expose globals used by callbacks inside pane classes
        global app
        app = self

        # Auto-load if configured
        if config.get("wp_url") and config.get("snap_url"):
            self._root.after(200, self.navigator.refresh)
        else:
            self._root.after(300, self._open_settings)

    def _build_menu(self):
        menu = tk.Menu(self._root)
        self._root.configure(menu=menu)

        file_menu = tk.Menu(menu, tearoff=False)
        file_menu.add_command(label="Settings…",   command=self._open_settings)
        file_menu.add_separator()
        file_menu.add_command(label="Quit",        command=self._on_close)
        menu.add_cascade(label="File", menu=file_menu)

        view_menu = tk.Menu(menu, tearoff=False)
        view_menu.add_command(label="Refresh posts", command=self.navigator.refresh
                              if hasattr(self, "navigator") else lambda: None)
        menu.add_cascade(label="View", menu=view_menu)

    def _build_layout(self):
        outer = ctk.CTkFrame(self._root, corner_radius=0, fg_color="transparent")
        outer.pack(fill="both", expand=True)

        self.navigator = NavigatorPane(outer, on_select=self._on_post_selected)
        self.navigator.pack(side="left", fill="y")

        self.canvas    = WorkingCanvas(outer, on_content_changed=self._on_draft_changed)
        self.canvas.pack(side="left", fill="both", expand=True)

        self.cards     = CardStack(outer)
        self.cards.pack(side="right", fill="y")

    def _on_post_selected(self, post_summary: dict):
        self._current_post_summary = post_summary
        # Show loading state
        self.canvas._status_var.set("Loading full post…")

        def _load():
            return wp_client.get_post(post_summary["id"])

        def _done(result):
            if isinstance(result, Exception):
                mb.showerror("WordPress", f"Could not load post: {result}")
                return
            self._current_post_full = result
            # Ensure local tracking record exists
            db.upsert_post(
                result["id"],
                wp_slug=result.get("slug", ""),
                wp_title=result.get("title", ""),
                wp_date=result.get("date", "")[:10],
                wp_status=result.get("status", "publish"),
                wp_type=result.get("type", "post"),
            )
            self.canvas.load_post(post_summary, result)
            self.cards.load_post(post_summary, result)

        _run_in_thread(_load, callback=_done)

    def _on_draft_changed(self):
        pass  # Future: update word count, preview, etc.

    def push_post(self):
        """Push the current draft to SnapSmack (a longform post, or a static page)."""
        if not self._current_post_full:
            return

        post  = self._current_post_full
        draft = self.canvas.get_draft()
        if not draft:
            mb.showwarning("Empty draft", "Write something before pushing.")
            return

        is_page = (post.get("type") == "page")
        local   = db.get_post(post["id"])

        if is_page:
            payload = {
                "title":       post.get("title", ""),
                "content_raw": draft,
                # Pages land active/visible: the CMS page editor has no
                # draft/reactivate toggle, so an inactive page would be stranded.
                "status":      "published",
            }
            if local and local["snap_post_id"]:
                payload["page_id"] = local["snap_post_id"]
            push_fn = smacktalk_client.create_page
            id_key  = "page_id"
        else:
            payload = {
                "title":       post.get("title", ""),
                "content_raw": draft,
                "date":        post.get("date", "")[:10],
                "tags":        self.cards.get_tags(),
                "status":      "draft",
            }
            cat_id = self.cards.get_category_id()
            if cat_id:
                payload["category_id"] = cat_id
            if local and local["snap_post_id"]:
                payload["post_id"] = local["snap_post_id"]
            push_fn = smacktalk_client.create_post
            id_key  = "post_id"

        kind = "page" if is_page else "post"
        self.canvas._status_var.set(f"⏳ Pushing {kind} to SnapSmack…")

        def _push():
            return push_fn(payload)

        def _done(result):
            if isinstance(result, Exception):
                mb.showerror("SnapSmack", str(result))
                self.canvas._status_var.set("Push failed.")
                return
            snap_id  = result.get(id_key)
            snap_url = result.get("url", "")
            db.mark_migrated(post["id"], snap_id, snap_url)
            self.canvas._status_var.set(f"✓ Pushed {kind} → {snap_url}")
            self.cards._mig_label.configure(text=f"✓ {snap_url}")

        _run_in_thread(_push, callback=_done)

    def _open_settings(self):
        SettingsDialog(self._root)

    def _on_close(self):
        config.set("window_width",  str(self._root.winfo_width()))
        config.set("window_height", str(self._root.winfo_height()))
        self._root.destroy()

    def run(self):
        self._root.mainloop()


# ============================================================
# Entry point
# ============================================================

app: SmackPressApp  # type: ignore  # set during __init__

if __name__ == "__main__":
    SmackPressApp().run()

# ===== SNAPSMACK EOF =====
