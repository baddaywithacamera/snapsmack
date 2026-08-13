<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->


# THE HUB — Unified Desktop Front End & Launcher
## Tool Specification (DRAFT)
**Version:** 0.1 draft
**Date:** 2026-08-11
**Platform:** Windows first, Linux second
**Status:** v1 BUILT (2026-08-13). `tools/hub/{main.py,hub.spec,build.bat}` →
`C:\snapsmack\hub\hub.exe`: launches installed tools + one-place HUB SETUP →
Discover Fleet writes the shared vault (snap_creds) + shared profiles (snap_profiles)
via `tools/_shared/snap_discovery.py`. The READ side that makes discovery pay off is
now wired across the fleet: SYBU + GYSS read shared profiles; SYBU + COLD SNAP share
Gemini prompts (snap_prompts). v1 DEFERS fetching MISSING tools (the "distribution"
open decision below is still open). SUYB profile-sharing is deliberately NOT wired —
see the note under "The Roster".
**Working title:** "THE HUB" is a placeholder — needs a real SnapSmack-voice name.

---

## The Thesis (why this is the whole game)

What sets SnapSmack apart is **not** merely that you own your files, domain, and
archive. Every "own your data" pitch says that. The real differentiator is that you
own **desktop-grade power tools, offline, to both customize AND manage your site** —
tooling no hosted platform can give you, because on those platforms *they are the
tool* and they keep it.

The Hub is the front door to that promise. It is the single thing a photographer opens
to reach every offline power tool, and it manages the local content those tools work
against so the whole suite feels like one product instead of a scatter of exes.

**The sites themselves are deliberately simple.** A SnapSmack site is a clean, fast
photo blog — not a sprawling web admin you fight with in a browser tab. That simplicity
is a feature, not a shortfall: the *power* lives in the offline desktop tools, where it
belongs (fast, native, works without a connection, no round-trip to the server for every
edit). The Hub is the seam between the two — simple site, powerful offline tooling. That
split is the architecture, and it's exactly what a hosted platform can never copy.

Two verbs, one roof:
- **Customize** — OH SNAP (no-code skin design).
- **Manage** — posting, importing, offline data sync, backup.

---

## What It Is

A single desktop front end that does three jobs:

1. **Launches** every desktop util from one place — one door, not a folder of exes.
2. **Manages the shared cached offline content** — the local library the tools read
   and write (`snap_library`). This is the load-bearing piece, not the launcher chrome.
   It is where a photographer's offline archive lives between sessions.
3. **Detects and offers to download missing utils** — you have the Hub but not COLD
   SNAP yet? It fetches it. (See the Distribution question — this is the hard part.)

Windows version ships first; Linux follows.

---

## The Roster (utils the Hub fronts)

| Util | Category | Notes |
|---|---|---|
| SMACK YOUR BATCH UP (SYBU-batch) | Manage | Load a shoot, arrange, metadata, publish the batch |
| GYSS | Manage | Needs a persistent local library to function (see LOCAL-LIBRARY-SPEC) |
| COLD SNAP | Manage | Standalone offline poster; offline data sync is its core |
| SUYBE (backup) | Manage | Smack Up Your Backup |
| OH SNAP | Customize | No-code skin designer — **read-only consumer** of the cache (see below) |
| Importers (unzucker / flkr-fckr) | Manage | Flickr / Instagram export importers — confirm inclusion |

The authoritative list lives in the **Util Manifest** (below), not hardcoded here.

### Shared-profile participation (read side)

Discover Fleet writes `snap_profiles`; a tool "pays off" only once it READS that store.
Status per tool:

- **SYBU** — reads shared profiles (thin adapter over `snap_profiles`). ✅
- **GYSS** — reads/writes shared profiles as of 0.1.5-alpha (`src/scripts/profiles.js`),
  byte-compatible with the Python peer; migrates its old private profiles once. ✅
- **COLD SNAP / SYBU** — share Gemini prompt presets via `snap_prompts`. ✅
- **SUYB — deliberately NOT wired, and it is NOT a mechanical port.** SUYB profiles
  are not the same object as the others': they carry FTP/SFTP/admin passwords inside a
  real encryption **vault** (`secret_vault`, SECAUDIT 037), and their `api_key` is often
  a least-privilege **backup-scoped** key valid only on `multisite/backup/*` — NOT a
  general posting/import key. Pushing those into the base64 shared store would be a
  security regression (faking at-rest protection) AND would poison the shared `api_key`
  with keys that can't post/import in SYBU/GYSS. Correct design (needs Sean's call):
  SUYB CONSUMES the shared store for the site LIST + a general key when present, keeps
  its FTP/vault secrets local, and never writes backup-scoped keys back as the shared
  key. SUYB already has its own hub discovery, so it is not "broken" today — just not
  unified. Do this as a designed consume-side, not a copy of SYBU's adapter.

---

## The Shared Cache Is the Point

All the posting/importing tools fill a **shared cached offline library** (`snap_library`
in `tools/_shared/`). The Hub owns the lifecycle of that cache — sync, status, staleness,
disk usage, per-install separation.

**OH SNAP consumes it read-only.** A skin designed against *real* archived content —
real aspect ratios, real caption lengths, panoramas, multi-image carousels, the
occasional missing caption — survives contact with reality. Lorem ipsum and stock
squares hide every layout edge case. So the Hub surfaces the cached library to OH SNAP
as a design fixture, and should deliberately expose **variety** (orientation extremes,
caption extremes, multi-image posts) so a designer can stress the layout, not just the
happy path.

Foundation already built — the Hub sits on `tools/_shared/`:
- `snap_home` — install discovery (a photographer may run several SnapSmack sites)
- `snap_creds` — shared credentials
- `snap_enrich` — enrichment
- `snap_library` — the cache the Hub manages

This is a front end over plumbing that already exists, not a from-scratch build.

---

## OPEN DECISIONS (resolve before build)

These are the real questions. Flagged loud so they don't get lost.

### 1. Distribution — the hard one
The Hub **cannot live inside a single install** the way other tools do, because it
manages *all* installs and fetches *all* utils.

**NOT DECIDED — captured thinking only (2026-08-11). Explicitly not rushing this.**

Rejected: "download from your own install" — the tools are too large to serve from
installs.

Direction leaning (to confirm later, not committed):
- **Host on Cloudflare.** Per-file cap is ~25 MB, so packages are split into **20 MB
  chunks** and reassembled client-side.
- **Signed manifest, not encryption.** A manifest lists every chunk + each chunk's
  SHA-256 + the whole-file SHA-256; **Sean's private key signs the manifest** (one
  signature covers all chunks). The Hub downloads chunks, checks each against the
  manifest, reassembles, verifies the whole-file hash, then verifies the signature.
  Encryption was considered and **dropped**: the tools aren't secret, so authenticity
  (signing) is what's wanted, not secrecy.
- **Signing proves *who*, not *where*.** Sean's public key ships baked into installs +
  the Hub. Packages not signed by him are rejected. A fork signing with its own key only
  works on installs trusting that key — Sean can't stop forks and doesn't need to;
  signing protects *his* channel from poisoning, nothing more.
- **The unsolvable part, named so nobody re-proposes it:** you cannot make a file refuse
  to run based on where it was downloaded from — a file doesn't remember its origin.
  Location-binding (encrypt-to-location, "won't work if moved") is impossible. A genuine
  signed copy re-hosted on download.com stays genuine.
- **What IS solvable — the real worry is staleness, not copying** (tools are free, so
  there's no piracy harm; the harm is an old/insecure copy lingering as if evergreen).
  Fix = **freshness enforcement**: the signed manifest carries an expiry + current
  version and lives at Sean's baked-in endpoint; every tool checks it on update and
  pulls the current build from Cloudflare *regardless of where the exe came from*. A
  casual mirror self-corrects or dies. A determined cracker who patches out the check is
  just a fork (already conceded). Respect the offline thesis: check-on-update with a
  generous offline grace window, never phone-home-every-launch.
- **Still open:** where does the *Hub itself* come from on first run? Grace-window
  policy for genuinely-offline old-but-fine tools. Whether freshness can refuse-to-run
  or only nag.

### 2. Util Manifest
The "offer missing" feature needs a manifest: util name, version, platform, and where to
fetch it. Per #1 the fetch source is the user's install, so the manifest is likely
**served by the install** (hub-authoritative, spokes report availability) rather than a
static file. Confirm shape.

### 3. Build stack
OH SNAP is **Tauri (Rust + JS)**; the posting/backup tools are **Python + PyInstaller**.
The Hub needs a GUI (it's a "front end"). Decision: Tauri (matches OH SNAP, real GUI) vs
Python GUI (matches the tool stack). Leaning Tauri for the front-end quality, but this is
a genuine architecture call for Sean + Codex.

### 4. Name
"THE HUB" is a placeholder. Needs a real name in SnapSmack voice.

### 5. Multi-install management
`snap_home` implies the Hub can see several installs. Decide whether the Hub manages the
cache and tools **per-install** with a switcher, or assumes one primary. (Sean runs
several sites — lean multi-install.)

---

## Out of Scope (v1)

- macOS.
- The PWA / SMACK THAT APP UP (that's a separate post-1.0 track — the Hub is desktop).
- Building any util that isn't already built (the Hub launches/fetches; it doesn't
  contain them).

---

## Related

- `tools/smack-some-shit-up/SMACK-SOME-SHIT-UP-Spec.md` — the *developer* release
  packager (different tool, different audience: Sean-at-the-dev-machine, not end users).
- `tools/gyss/LOCAL-LIBRARY-SPEC.md` — GYSS's local library requirement.
- `tools/_shared/` — the foundation the Hub sits on.

<!-- ===== SNAPSMACK EOF ===== -->
