# BOARD LORD — status

The SnapSmack **forum operator console**. Qt (PySide6) desktop app, built on the
SNAP SLAPPER Qt foundation and its Midnight Lime theme (imported from
`slapper_qt.theme`, unchanged).

## Run
```
python tools/hub/run_board_lord.py
```
First launch pops **Settings** — paste the forum URL (defaults to
`https://snapsmack.ca/api/forum`) and your operator key (the God key
`FORUM_MOD_KEY` for full power, or an admin blog's api_key). Stored per-user via
QSettings; never leaves the machine.

## Build the exe
```
python -m PyInstaller tools/hub/board_lord.spec   # -> dist/BOARD LORD.exe
```

## What it does (v1)
- **Rail:** triage by author-flag (Support / Questions / Chat / Brag), view
  filters (Unread / Following), board picker, and sort (Active / Newest /
  Most-viewed / Unread-first).
- **Thread list:** custom rows with flag + pinned/locked/solved badges, unread dot.
- **Detail pane:** full thread + replies; operator actions — set flag, pin, lock,
  follow, delete; per-reply accept-answer / delete; reply as operator.
- **New Post / New Board** dialogs; **Blogs & Roles** promote/demote screen.

## Talks to
`projects/forum-server` (forum API, shipped 0.7.580D). All endpoints used were
live-verified on 2026-08-28. Backend spec: `_spec/SPEC-boardlord-forum-backend-v1.md`.

## Architecture
- `api.py` — zero-dep urllib client (`ForumClient`).
- `worker.py` — `run_async` (QThreadPool) so the UI never freezes.
- `config.py` — QSettings (base URL + key).
- `style.py` — Midnight Lime extensions (nav, flag badges, buttons).
- `widgets.py` — `ThreadRow`, `ReplyCard`, relative time.
- `dialogs.py` — Settings / NewBoard / NewPost / Roles.
- `main_window.py` — `BoardLordWindow` + `DetailPanel`.

## Not yet (v2 candidates)
- `supportme@snapsmack.ca` email intake + CLAUDE TAKE THE WHEEL AI drafting
  (GL-1..6 laws) — see `_spec` (main checkout) for the support-suite spec.
- Attachments/images in the detail view (backend supports them).
- Pagination (list currently shows page 1 / 25).
- Toolbar icons instead of unicode glyphs.

## Verified
- `py_compile` clean; headless (offscreen) smoke test builds the window, all four
  dialogs, and the detail render with no errors (2026-08-28).
- Live GUI + visual pass is Sean's / Codex's (no display in the build env).

<!-- ===== SNAPSMACK EOF ===== -->
