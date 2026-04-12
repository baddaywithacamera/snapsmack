# SMACK SOME SHIT UP
## Release Packager — Tool Specification
**Version:** 1.0 draft  
**Date:** 2026-04-11  
**Platform:** Windows + Linux  
**Language:** Python 3.11+

---

## The Problem

Cutting a release currently means going to at least six different places:

| What | Where |
|---|---|
| Bump version numbers | `tools/release.py` |
| Build core update package | `tools/build-release.php` |
| Package skin(s) | `tools/build-skin-package.php` |
| Build SYBU for Windows | `tools/ft-batch-poster/build.bat` |
| Build SYBU for Linux | `tools/ft-batch-poster/build.sh` |
| Build SUYBE for Windows | `tools/smack-up-your-backup/build.bat` |
| Build SUYBE for Linux | `tools/smack-up-your-backup/build.sh` |

SMACK SOME SHIT UP (SSSU) is one tool that runs all of them from a single terminal menu, in the right order, with the right inputs.

---

## Overview

- **Type:** Python CLI / interactive terminal menu — no GUI
- **Entry points:** `sssu.bat` (Windows) and `sssu.sh` (Linux) in repo root; both call `python tools/sssu/sssu.py`
- **Trigger:** Manual run. You launch it when you want a release.
- **Config:** `tools/sssu/sssu-config.ini` — output paths, overrideable defaults
- **Platform-aware:** Native app builds (PyInstaller) warn and skip if you're on the wrong OS. Site/skin packaging works on both.

---

## Interface

Plain terminal. No dependencies beyond Python stdlib (plus `colorama` for Windows colour support).

```
╔══════════════════════════════════════════════════════════╗
║          SMACK SOME SHIT UP — Release Packager           ║
╠══════════════════════════════════════════════════════════╣
║  SnapSmack Alpha v0.7.9c "Electric Chair"                ║
║  Branch: master  |  HEAD: a3f9c12  |  Status: CLEAN      ║
╠══════════════════════════════════════════════════════════╣
║  Dependency check:  git ✓   php ✓   python ✓             ║
╚══════════════════════════════════════════════════════════╝

  1.  SnapSmack Core Release
  2.  Skin Package(s)
  3.  SYBU — Windows Build
  4.  SYBU — Linux Build
  5.  SUYBE — Windows Build
  6.  SUYBE — Linux Build
  7.  THE FULL SMACK  (everything this platform can build)

  0.  Exit

>
```

At startup SSSU checks for git, php, and python and marks each ✓ or ✗. Targets that require a missing dependency are shown in dim text with `[unavailable]` next to them rather than hidden entirely — so you know what's broken and why.

---

## Packaging Targets

### 1. SnapSmack Core Release

**What it does:**

1. Reads the current version from `core/constants.php`.
2. Prompts: *New version number?* (e.g. `0.7.9d`) and *Codename?* (e.g. `Footrest`).
3. Runs `tools/release.py <version> <codename>` — patches `core/constants.php`, `smack-central/sc-version.php`, and `CHANGELOG.md`.
4. Prompts: *Previous release tag for diff?* (e.g. `0.7.9c`). Lists recent git tags so you can pick without memorising them.
5. Runs `php tools/build-release.php --from <prev> --to HEAD --output <releases_dir>`.
6. Reports the output ZIP path and size.
7. Prints the suggested git commands (add, commit, tag, push) but does **not** run them — that stays on the developer.

**Output:** `releases/snapsmack-<version>.zip`

**Requires:** git, php

---

### 2. Skin Package(s)

**What it does:**

1. Scans `skins/` and lists all skin directories with their status (`stable` / `beta` / `development`) read from each `manifest.php`.
2. Prompts: pick one skin, a range, or `all` (excluding `development` skins unless explicitly requested).
3. Runs `php tools/build-skin-package.php <skin-name> --output <releases_dir>/skins/` for each.
4. Reports each output ZIP.

**Output:** `releases/skins/snapsmack-skin-<name>-<version>.zip`

**Requires:** php

---

### 3. SYBU — Windows Build

**What it does:**

1. Checks `sys.platform == 'win32'`. Aborts with a clear message if not on Windows.
2. Reads `BUILD_VERSION` from `tools/ft-batch-poster/main.py`.
3. Ensures `C:\SmackYourBatchUp` exists.
4. Checks for the versioned spec file; generates from template if missing (replicating the existing `build.bat` logic, now in Python so it works in the unified runner).
5. Runs `pip install -r requirements.txt` quietly.
6. Runs PyInstaller with `--distpath "C:\SmackYourBatchUp"`.
7. Reports the output exe path.

**Output:** `C:\SmackYourBatchUp\smackyourbatchup-<version>.exe`

**Requires:** Windows, PyInstaller

---

### 4. SYBU — Linux Build

**What it does:**

1. Checks `sys.platform.startswith('linux')`. Aborts if not on Linux.
2. Reads `BUILD_VERSION` from `tools/ft-batch-poster/main.py`.
3. Checks for the versioned spec file; generates from template if missing (same logic as target 3).
4. Creates output dir `~/SmackYourBatchUp/` if it doesn't exist.
5. Runs `pip3 install -r requirements.txt` quietly.
6. Runs PyInstaller with `--distpath ~/SmackYourBatchUp`.
7. Creates a `.tar.gz` of the output for easy distribution.
8. Reports the output paths.

**Output:**  
`~/SmackYourBatchUp/smackyourbatchup-<version>` (binary)  
`~/SmackYourBatchUp/smackyourbatchup-<version>.tar.gz`

**Requires:** Linux, PyInstaller

---

### 5. SUYBE — Windows Build

**What it does:**

Same flow as target 3, pointed at `tools/smack-up-your-backup/`.

**Output:** `C:\SmackUpYourBackup\smackupyourbackup-<version>.exe`

**Requires:** Windows, PyInstaller

---

### 6. SUYBE — Linux Build

**What it does:**

Same flow as target 4, pointed at `tools/smack-up-your-backup/`.

**Output:**  
`~/SmackUpYourBackup/smackupyourbackup-<version>` (binary)  
`~/SmackUpYourBackup/smackupyourbackup-<version>.tar.gz`

**Requires:** Linux, PyInstaller

---

### 7. THE FULL SMACK

Runs all targets the current platform can handle, in order:

1. SnapSmack Core Release
2. Skin Package(s) — prompts once to confirm "all stable skins"
3. SYBU build (platform-appropriate)
4. SUYBE build (platform-appropriate)

Cross-platform targets (site + skins) always run. Native builds run only if the platform matches; the others are skipped and noted in the summary rather than causing an error.

---

## Configuration

`tools/sssu/sssu-config.ini`

```ini
[paths]
# Where site/skin ZIPs land
releases_dir = releases

# Override output dirs for app builds (defaults shown)
sybu_win_output  = C:\SmackYourBatchUp
sybu_lin_output  = ~/SmackYourBatchUp
suybe_win_output = C:\SmackUpYourBackup
suybe_lin_output = ~/SmackUpYourBackup

[git]
# Remote name used in the suggested push commands
remote = Github
```

All paths support `~` expansion and relative paths from repo root.

---

## Directory Structure

```
tools/
  sssu/
    sssu.py           — main script (entry point)
    sssu-config.ini   — local config (gitignored)
    sssu-config.ini.example — committed template
    runner_site.py    — site release logic
    runner_skins.py   — skin packaging logic
    runner_app.py     — SYBU / SUYBE build logic (shared)
    util.py           — dep checks, git helpers, header renderer

sssu.bat              — Windows launcher (repo root)
sssu.sh               — Linux launcher (repo root)
```

The individual runners import shared utilities from `util.py` and can also be called directly for scripting/CI without going through the menu.

---

## Dependencies

| Dependency | Used for | Check command |
|---|---|---|
| Python 3.11+ | Everything | `python --version` |
| Git | All targets | `git --version` |
| PHP 8.0+ | Site + skin packaging | `php --version` |
| PyInstaller | App builds | `pyinstaller --version` |
| colorama | Colour output on Windows | installed via pip |

All dependency checks happen at startup. Missing items disable the relevant targets but don't prevent SSSU from launching.

---

## Behaviour Notes

- **Dirty working tree:** If `git status` shows uncommitted changes, SSSU warns before running any target that touches the repository (site release, skin packaging). It does not block — the warning is informational.
- **Existing output files:** If a release ZIP or exe for the current version already exists, SSSU asks whether to overwrite rather than silently replacing it.
- **Subprocess output:** Each sub-tool's stdout/stderr is streamed live so you can see what's happening. If a subprocess exits non-zero, SSSU prints a clear failure message, skips remaining steps in the current target, and offers to continue to the next target or abort.
- **No commits, no pushes:** SSSU runs build tools. It never runs `git commit`, `git tag`, or `git push`. After a site release it prints the exact commands to use.
- **Logging:** A timestamped run log is written to `tools/sssu/sssu.log` on every run — stdout from all subprocesses, build results, and any errors.

---

## Launcher Files

**`sssu.bat`** (Windows, repo root):
```bat
@echo off
cd /d "%~dp0"
python tools/sssu/sssu.py %*
pause
```

**`sssu.sh`** (Linux, repo root):
```bash
#!/usr/bin/env bash
cd "$(dirname "$0")"
python3 tools/sssu/sssu.py "$@"
```

Double-click `sssu.bat` on Windows. Run `./sssu.sh` on Linux. That's it.

---

## Out of Scope (v1)

- Code signing (Windows EV cert, Linux GPG) — noted as a future target
- Uploading ZIPs to the releases server — manual step, by design
- macOS builds
- Automated git tagging
