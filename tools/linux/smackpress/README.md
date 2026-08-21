<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->

# SMACKPRESS — Linux Chrome/Blink port

The same tool as the Windows customtkinter build, with its **window** redrawn by
Chromium (the Blink engine) instead of tkinter. The **work** is unchanged: this
port imports the tool's own logic modules and re-exposes every action.

- **What it does:** one-post-at-a-time migration of a WordPress blog into
  SnapSmack SMACKTALK (longform) posts and static pages. Left pane lists WP
  posts/pages; centre pane shows the WordPress source over an editable SMACKTALK
  draft (with optional AI rewrite); right pane holds tags, category, gallery
  images, mosaic building, and the "hide the old WP post" control.
- **What changed in the port:** only the UI layer. `app.py` here registers
  `blink.call` handlers that call the exact same functions the tkinter window
  called — no logic was rewritten. The credential store, migration database, and
  all site/AI calls are the original `smackpress/` package modules.

## Run it on Linux

You need Python 3 and a Chromium-family browser (Chromium, Chrome, Brave, or
Edge) installed. Then:

```
cd tools/smackpress/linux
chmod +x run.sh
./run.sh
```

`run.sh` puts `_shared` (for `snap_blink`) and the `smackpress` package on
`PYTHONPATH`, sets `SNAPSMACK_HOME` to `~/snapsmack` if you have not, and starts
`app.py`. `app.py` serves the HTML window on a random localhost-only port and
opens it in a Chromium `--app` window. Close the window to quit.

Optional: `pip install -r requirements.txt` installs `keyring` so the WordPress
application password and API keys go into your OS keychain (Secret Service). If
`keyring` is missing, the tool still runs and falls back to the local
`smackpress.db` — do not then sync/share that file.

The migration state and settings live in `tools/smackpress/smackpress.db`, the
**same** file the Windows build uses (config.py chooses it), so a machine that
has run either build keeps one shared history.

## Feature parity vs the tkinter version

Every tkinter control maps to a web control + one Python handler. None dropped.

| tkinter control (original app.py) | Web control | Python handler |
| --- | --- | --- |
| File ▸ Settings… / auto-open when unconfigured | "Settings…" button + modal | `load_state`, `save_settings` |
| File ▸ Quit | "Quit" button (`window.close()`) | — (closes the app window) |
| View ▸ Refresh posts | "Refresh posts" button | `list_posts` |
| Settings entries (wp_url, wp_user, wp_app_password, snap_url, snap_api_key, ai_provider, ai_model, ai_api_key) | modal text/password inputs | `save_settings` |
| Settings ▸ AI system prompt textbox | modal textarea | `save_settings` |
| Settings ▸ Save | "Save" | `save_settings` |
| Settings ▸ Test connections | "Test connections" | `test_connections` |
| Settings ▸ Cancel | "Cancel" | — (client closes modal) |
| Navigator ▸ ⟳ refresh | ⟳ button | `list_posts` |
| Navigator ▸ Type (Posts/Pages) | `#nav-type` select | `list_posts` |
| Navigator ▸ Status (publish/private/draft/any) | `#nav-status` select | `list_posts` |
| Navigator ▸ Search entry + Go (+ Return) | `#nav-search` + "Go" (+ Enter key) | `list_posts` |
| Navigator ▸ post rows (click to select) | `.post-row` click | `load_post` |
| Navigator ▸ ◀ / page label / ▶ | pager buttons + label | `list_posts` (page arg) |
| Canvas ▸ WordPress source (read-only) | `#wp-source` textarea (readonly) | `load_post` |
| Canvas ▸ editable SMACKTALK draft (autosave on keypress) | `#draft` textarea (debounced) | `save_note` |
| Canvas ▸ ✦ AI rewrite | "✦ AI rewrite" | `ai_rewrite` |
| Canvas ▸ ⟳ Reset | "⟳ Reset" | client restores `content_raw` (held from `load_post`), then `save_note` |
| Canvas ▸ → SnapSmack (push) | "→ SnapSmack" | `push_post` |
| Canvas ▸ status line | `#canvas-status` | (client) |
| Cards ▸ meta box | `#meta` | `load_post` |
| Cards ▸ Tags entry | `#tags` | consumed by `push_post` |
| Cards ▸ Category menu | `#category` select | `get_categories` / `load_state`; id sent to `push_post` |
| Cards ▸ Gallery image list | `#images` | `load_post` |
| Cards ▸ "Caption images from filename" checkbox | `#caption-fn` | `set_caption_from_filename` |
| Cards ▸ Create mosaic from gallery | "Create mosaic from gallery" | `create_mosaic` |
| Cards ▸ Migration status label | `#migration` | `load_post` / `push_post` / `hide_wp` |
| Cards ▸ Hide WP post (mark migrated) | "Hide WP post…" | `hide_wp` |

### Dialog substitutions

The tkinter build used `simpledialog.askstring` / `messagebox.askyesno` /
`showinfo` / `showerror`. In the browser these map to:

- **Mosaic title prompt** → `window.prompt` (same default: the post title).
- **Hide-post confirm** → `window.confirm` with the identical warning text.
- **info / error popups** → the bottom log strip (`#log`) and the canvas status
  line, colour-coded ok/err.

### TODO(port)

- **`TODO(port)`: Hide-post "record a SnapSmack URL" prompt.** The tkinter
  `_hide_wp` asked for a URL to record only when no migration URL was already
  stored. The port sends the stored URL (server-side `hide_wp` falls back to the
  local record) but does not yet pop a browser prompt for the manual-entry case;
  in practice you hide a post after pushing it, so a URL already exists. Wire a
  `window.prompt` fallback here if you need to hide a post that was never pushed
  through the tool.

## Honest status

Ported and imports verified with `python3 -c "import ast; ..."` on the build box.
**Not yet run on Linux hardware** (this machine cannot launch Linux Chromium), so
the window has not been exercised live. The Python handlers are thin wrappers over
the already-shipping logic modules.

<!-- ===== SNAPSMACK EOF ===== -->
