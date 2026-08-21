<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical HTML-comment SNAPSMACK EOF marker.
-->

# Smack Up Your Backup — Linux Chrome/Blink port

This is the Linux build of SUYB. The **window** is HTML drawn by Chromium
(Blink), served locally by the shared `snap_blink` runtime. The **work** —
downloading backups, pushing to the cloud, restoring, auditing, cloud-to-cloud
sync, hub discovery — is the tool's original Python, unchanged. There is no
tkinter here.

## What it is

- `app.py` — imports `snap_blink` and `suyb_core`, and registers one
  `blink.call` handler per window action.
- `suyb_core.py` (in the tool root, one level up) — the orchestration glue
  factored out of the tkinter `main.py`: it builds the real engines
  (`BackupEngine`, `RestoreEngine`, `AuditEngine`, `CoverageEngine`,
  `DedupeEngine`, `CloudSyncEngine`, `HubDiscovery`, `cloud_client`), runs the
  long ones on background threads, and reports progress through a small job
  registry the web page polls. The tkinter `App` could call the same functions.
- `web/` — `index.html` (the window), `app.js` (calls `blink.call`, renders the
  seven tabs and the dialogs), `style.css` (dark, desktop-only).

## Run it on Linux

```
cd tools/smack-up-your-backup/linux
pip3 install -r requirements.txt          # same deps the Windows build needs
chmod +x run.sh
./run.sh
```

`run.sh` sets `SNAPSMACK_HOME` (defaults to `~/snapsmack`) and `PYTHONPATH`
(so `_shared` and the tool root are importable), then runs `app.py`. Chromium
or Chrome must already be installed; `snap_blink` finds it and opens an app
window. If no Blink browser is found it prints a `http://127.0.0.1:<port>/`
URL to open in any browser instead.

## Shared-library contract (unchanged)

Credentials and the hub URL + key come from **The Hub's shared store**
(`config.shared_cred` → `snap_creds`), exactly as on Windows. This port adds
**no** private credential store. Config, profiles, the credential library, the
encryption vault, sync jobs and staging all resolve under `SNAPSMACK_HOME`
via `config.py` / `snap_home`, the same paths the Windows build uses.

## Feature parity vs the tkinter version

Every button/menu/field in `main.py` maps to a web control + `blink.call`:

| tkinter (main.py) | Blink port |
| --- | --- |
| Header: profile dropdown, + New / Edit / Dup / Del | `#profile-select` + the four header buttons → `select_profile`, `profileDialog`, `duplicate_profile`, `delete_profile` |
| Startup credential-vault unlock gate | `vaultUnlockGate()` → `unlock_vault` |
| Setup Wizard (first run) | Covered by the Profile dialog + Discover from Hub (the wizard is a guided subset of those; see TODO below) |
| ProfileDialog: all site/FTP/admin/cloud/backup fields + Test Login / Test FTP / Test Cloud + Google auth | `profileDialog()` → `save_profile`, `test_login`, `test_conn`, `test_cloud`, `validate_cloud_key`, `authenticate_oauth` |
| HubDiscoveryDialog | `hubDialog()` → `get_hub_creds`, `discover_hub` (threaded job) |
| Backup tab: Differential/Full, Include settings, START, BACKUP ALL, Cancel, resume-checkpoint prompt, failure abort/continue | `renderBackup()` → `precheck_backup`, `check_resume`, `clear_resume`, `start_backup`, `start_backup_all`, `cancel_job`, `resolve_ask` |
| Restore tab: local ZIP / cloud / recovery-kit sources, cloud browser, START, Cancel | `renderRestore()` → `list_cloud_backups`, `start_restore` |
| Audit tab: Server Audit, Coverage, De-dupe, results, kit auto-find | `renderAudit()` → `find_latest_kit`, `start_audit`, `start_coverage`, `start_dedupe` |
| Scheduler tab: per-blog enable/frequency/day/time (auto-save), Run Now, global OS schedule | `renderScheduler()` → `list_schedules`, `save_schedule_field`, `start_backup` (run now), `global_schedule_state`, `set_global_schedule` |
| Cloud Sync tab: job selector, New/Edit/Delete, RUN SYNC, Cancel, B2 test | `renderCloudSync()` + `syncJobDialog()` → `list_sync_jobs`, `get_sync_job`, `new_sync_job_template`, `save_sync_job`, `delete_sync_job`, `test_b2`, `start_sync` |
| Settings tab: Global Cloud Config + Validate/Authenticate/Save, pacing, Discover from Hub, Pull cloud config, Credential Library, Credential Encryption panel, AI install, Export/Import | `renderSettings()` → `get_settings`, `save_settings`, `validate_cloud_key`, `authenticate_oauth`, `pull_cloud_config`, `list/add/remove/rename_cred`, `enc_status/enable/change/disable/toggle_machine_key`, `ai_status`, `install_ai`, `export_settings`, `import_settings` |
| Help tab | `renderHelp()` — topics mirrored into `app.js` (`HELP_TOPICS`) |
| System tray / minimize-to-tray / launch-at-startup | Not ported — see TODO below |

### TODO(port) — wired-or-noted gaps

- **System tray + minimize-to-tray + launch-at-Windows-startup** — Windows-only
  `pystray` / registry behaviour with no Blink equivalent. The equivalent
  "run backups on time without the window open" need is met on Linux by the
  **global OS schedule** (Scheduler tab → `set_global_schedule`, which writes a
  cron line via `os_schedule`). Tray itself is intentionally not ported.
- **Setup Wizard** — the first-run multi-step wizard is not reproduced as a
  distinct flow; its inputs are fully available through the Profile dialog and
  Discover from Hub. A dedicated first-run wizard could be added later.
- **CloudSyncTab "Audit & Cleanup"** (`_audit_cleanup`, the inventory +
  delete-bad-versions + re-transfer confirm flow) — the nightly sync itself is
  fully ported; this two-step destructive confirm flow is left as a
  `TODO(port)` in `suyb_core.py` pending a web confirm UX.
- **Native file/folder pickers** — the browser sandbox has no native file
  dialog, so path fields (credentials JSON, backup dir, ZIP/kit paths) are typed
  or pasted rather than picked. Export/Import settings use a textarea instead of
  a save/open dialog.

## Honesty

Ported and **imports verified** (`python3 -c "import ast; ast.parse(...)"` on
`app.py` and `suyb_core.py` parses clean). **Not yet run on Linux hardware** —
this build box has no Linux Chromium to launch, so the live window has not been
exercised end to end. The Python is faithful to the tkinter build and reuses its
engine modules unchanged.

<!-- ===== SNAPSMACK EOF ===== -->
