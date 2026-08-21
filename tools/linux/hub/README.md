<!--
  THE HUB — Linux Chrome/Blink port. README.
-->

# THE HUB — Linux (Chrome/Blink) port

**What it is.** The Windows Hub (`../main.py`) drawn with tkinter, re-skinned to run
on Linux with its window drawn by Chromium/Chrome (the Blink engine) on top of the
shared `snap_blink` runtime. Same job, new window:

- The **window** is HTML/CSS/JS in `web/` (`index.html`, `app.js`, `style.css`).
- The **work** is the original Python — the shared library (`snap_creds`,
  `snap_profiles`, `snap_discovery`, `snap_prompts`, `snap_prompt_sync`) plus the
  Hub's own launch/prompt logic, factored out of the tkinter class into
  `hub_core.py` so both UIs could use it.
- `app.py` is the bridge: it registers `@app.api` handlers that the page calls with
  `blink.call('<name>', ...args)`.

The Hub is the shared credential/discovery centre. **Discover Fleet** fills the shared
stores (`snap_creds` + `snap_profiles`) once, and every other tool (SYBU / SUYB /
GYSS / COLD SNAP …) reads from them — no per-tool setup. It also launches the other
tools and syncs each blog's one-call AI prompt.

## How to run on Linux

```bash
# from tools/hub/linux/
python3 -m pip install -r requirements.txt      # just: requests
chmod +x run.sh
./run.sh
```

`run.sh` puts `tools/_shared` and `tools/hub` on `PYTHONPATH`, sets `SNAPSMACK_HOME`
to `~/snapsmack` if unset, and starts the app. `snap_blink` serves the UI on a
random localhost port and opens a Chromium `--app` window; **close the window to
quit**. If no Chromium/Chrome is installed, `snap_blink` prints a localhost URL to
open in any browser instead.

## Feature parity vs. the tkinter Hub

Every tkinter control has a web control + `blink.call`:

| tkinter Hub action | Linux port |
| --- | --- |
| LAUNCH grid (8 tool buttons, enabled/disabled by install) | `roster` grid; `launch_tool(path)` |
| HUB SITE URL field | `#hub_url` input |
| HUB API KEY field + **Test** | `#hub_key` input; `test_hub(url,key)` |
| GEMINI API KEY field + **Test** | `#gemini_api_key` input; `test_gemini(key)` |
| GOOGLE DRIVE CREDENTIALS field + browse `…` + **Test** | `#google_credentials` input; `test_drive(path,folder)` (see note) |
| BACKUP FOLDER ID field | `#drive_folder_id` input |
| **SAVE SHARED CREDENTIALS** | `save_creds(creds)` |
| **⟳ DISCOVER FLEET** (save-then-discover) | `discover(creds)` |
| SHARED PROFILES list | `#profiles` list (from `load_state`/`discover`) |
| PROMPT SYNC: site picker | `#psite` select |
| **⟳ PULL ALL FROM FLEET** | `pull_all()` |
| **⬇ PULL THIS SITE** | `pull_one(site_key)` |
| **⬆ PUSH TO THIS SITE** (with confirm) | `push_one(site_key,text)` (browser confirm dialog) |
| **COPY FROM…** picker dialog | in-page modal; `copy_from(src_key)` |
| prompt editor text box | `#ptext` textarea; `load_prompt(site_key)` on select |

### TODO(port) items (wired, with a note — nothing silently dropped)

1. **Launch targets: `.exe` → `run.sh`.** The Windows Hub launched each tool's
   versioned Windows `.exe` under the shared root. A `.exe` has no Linux meaning, so
   the roster points each tool at `<SNAPSMACK_HOME>/<tool>/linux/run.sh` instead (see
   `hub_core._TOOLS` / `hub_core.roster`). A tool shows as *not installed* until its
   own Linux port's `run.sh` exists — exactly as a missing `.exe` behaved on Windows.
   `hub_core.launch` runs it via `bash run.sh`. As the sibling tools get their Linux
   ports, they light up automatically.
2. **Drive credentials file picker (`…` browse button).** The tkinter version opened
   a native `filedialog`. A sandboxed Chromium `--app` window cannot hand a real
   filesystem path back to Python (a web `<input type=file>` only yields the file
   contents, not its path — and the Drive test needs the *path*). So the port keeps
   the field as a plain text box: the user pastes/types the full path to the
   credentials `.json`, and **Test** validates it server-side (same
   `test_drive` logic as Windows). Behaviour and validation are identical; only the
   pick-a-file convenience is replaced by paste-the-path.

Everything else is a straight port of the same shared-library calls the Windows Hub
made — creds/config/profiles/prompts still go through the shared library, not any
new path.

## Honesty

Ported and **imports verified** (`python3 -c "import ast; ast.parse(...)"` on
`app.py` and `hub_core.py` parses clean). **Not yet run on Linux hardware** — this
was built on Windows and cannot open Linux Chromium here. Run `./run.sh` on a Linux
box with `requests` installed and a Chromium/Chrome present to exercise it live.

<!-- ===== SNAPSMACK EOF ===== -->
