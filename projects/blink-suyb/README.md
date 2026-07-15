<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical Markdown/HTML EOF
  marker: <!-- ===== SNAPSMACK EOF ===== -->
  Missing or different = truncated/corrupted. Restore before saving.
-->

# BLINK-SUYB

The cross-platform, browser-UI edition of **SMACK UP YOUR BACKUP**.

`blink` for Chrome's rendering engine. Same SUYB engines, no tkinter, runs on
**Linux, macOS and Windows**.

## Why this architecture

SUYB is a mature tkinter desktop app whose real work lives in a stack of Python
engines: `backup_engine`, `restore_engine`, `cloud_sync_engine`, `audit_engine`,
plus `ftp_client`, `sftp_client` (paramiko/SSH), `cloud_client` (B2/Box/Drive/
OneDrive) and an OS scheduler.

A **pure Chrome extension cannot** do FTP, SFTP or run scheduled backups with the
browser closed — the sandbox forbids raw sockets and background execution. So
blink-suyb keeps the engines where they can actually work — in a local Python
process — and moves only the **UI** into Chrome:

```
  launch.py  ──starts──▶  localhost server (stdlib, 127.0.0.1 + per-launch token)
      │                        │
      │                        ├── serves the HTML/CSS/JS UI  ─▶  Chrome (--app window)
      └──opens Chrome──────────┘
                               └── JSON API  ─▶  reuses the real SUYB engines
                                                  (FTP, SFTP, B2, Box, Drive, scheduler…)
```

The UI is in the browser; the muscle is native. That is how it does **everything**
the Windows app does while being cross-platform and "run it in Chrome."

## Carry-over from the desktop app

blink-suyb reads SUYB's own portable state files **as-is, no conversion**:

| File               | Format | What it holds                       |
| ------------------ | ------ | ----------------------------------- |
| `config.ini`       | INI    | window/app/pacing/cloud defaults    |
| `profiles/*.json`  | JSON   | one file per backup profile         |
| `credentials.json` | JSON   | the credential library              |

On launch it auto-detects your existing SUYB folder (override with the
`BLINK_SUYB_DATA` environment variable) and your profiles appear immediately.

## Run it (dev)

```
python3 launch.py
```

Starts the server on a free localhost port and opens the UI in a Chrome app
window. `pip install -r requirements.txt` to wire every transport engine.

## Status

- **v0.1.0 — foundation.** Launcher + localhost server + full 10-tab UI shell.
  Carry-over is **live**: Profiles, Credentials and Settings render your real
  SUYB data. Dashboard shows per-engine import health.
- **Next:** wire the engine-backed tabs (Backup / Restore / Cloud Sync / Audit /
  Scheduler) to run real operations with a live progress stream to the browser,
  then per-OS packaging.

*Built by Sean & Claude.*

<!-- ===== SNAPSMACK EOF ===== -->
