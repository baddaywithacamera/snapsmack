# SECAUDIT 028 - sc-config.php exposure: remediation runbook

| Field | Value |
|---|---|
| Audit ID | 2026-11-10-028 (remediation for the historical-exposure finding under audit 028) |
| Opened | 2026-11-10 |
| State | OPEN. This is a DESIGN runbook, not a fix. Nothing here has been applied. |
| Author | Claude Code (design only) |
| Executes | Sean, and Sean alone. Key material is his. |
| Measured basis | Codex, Brain Trust m0csLNuZ + _continuity/2026-11-10-secaudit-028-presence-check.md |

## Read this first

This is written for Sean to execute. Per the PM's 03:00 ruling, no agent opens,
copies, hashes, prints, or moves the contents of any `sc-config.php` or the
signing key, and no agent removes, changes permissions on, or rotates anything.
I designed this from the public sample (`smack-central/sc-config.sample.php`) and
the consuming code only. I did not read any live `sc-config.php`.

Lead answer: you cannot scrub every copy of this file — it is in six live
webroots, in the 2026-08-17 CT102 backup, and in 19 git tag trees. So the
remediation is not "delete the copies." It is **rotate every secret the file
contained, so the copies become worthless**, and separately get the file out of
the webroots so it stops accumulating new exposure. Rotation is the closure;
removal is containment.

The one piece of good news, and it is the load-bearing one: the **root signing
key is not in this file**. It lives offline (Bitwarden) and has never been on a
server (`core/updater.php:181-183`). That means an attacker holding the leaked
release private key still cannot forge a key-rotation announcement, and you can
rotate the release key through the fleet's own trust channel. Without the root
key off-box, this would be far worse.

## What leaked (secret classes in sc-config.php)

From `smack-central/sc-config.sample.php`, the live file defines these secrets:

1. **SMACK CENTRAL database** — `SC_DB_HOST/NAME/USER/PASS`. The sample notes this
   is the **same database as forum-server** (`squir871_smackforum`).
2. **SMACK THE ENEMY database** — `STE_DB_HOST/NAME/USER/PASS` (`squir871_enemy`).
3. **Forum moderation key** — `FORUM_MOD_KEY` (mirrors `forum-server/api/forum/config.php`).
4. **Release signing private key** — `SMACK_RELEASE_PRIVKEY` (Ed25519 secret, 128 hex).
   This is the most dangerous single value: it signs release packages the whole
   fleet trusts.
5. **GitHub token** — `SNAPSMACK_GITHUB_TOKEN` (personal access token).

Non-secret, for context: `SC_BASE_URL`, `FORUM_API_URL`, repo/release paths,
`SC_ASSETS_DIR/URL`. These need no rotation.

## Severity and the live picture (measured, not re-measured here)

- Root `sc-config.php` currently exists in six CT102 webroots (acolourlesslife.ca,
  foundtextures.ca, hekeepsdroningon.ca, photowalk.ing, pixhellated.ca,
  wateronthebrain.ca). CT106 has none.
- Five of those return HTTP 200 with a **zero-byte body** (PHP executes, so the
  source is not served as text today); acolourlesslife returns 500 (Codex is
  answering separately why). A zero-byte 200 means it is not being served as
  plaintext **right now**, under the current PHP+server config. It does not mean
  the secrets are safe: the file is still on disk in a web-served directory, still
  in the 8/17 backup, and still in 19 git tags. Any future "PHP off" misconfig,
  path-traversal bug, backup download, or repo/tag read re-exposes it.
- Treat every one of the five secret classes above as **potentially already
  compromised** since at least 2026-08-17 (the backup date), and rotate on that
  assumption. Do not treat the zero-byte 200 as evidence the secrets are intact.

## Remediation order (why this order)

Order is driven by two things: how fast and cheap the step is, and how much it
reduces active risk. Do the instant, zero-fleet-impact kills first; do the
highest-blast-radius operation (signing key) last and deliberately.

### Step 0 — Containment: get the file out of the webroots (do first, it is cheap)

Goal: stop ongoing and future web exposure without waiting on rotation.

- For each of the six webroots, **move `sc-config.php` out of the document root**,
  or deny it at the server level. SMACK CENTRAL is the vendor hub; a site webroot
  is the wrong place for it to live at all — the correct state is that these six
  sites have no `sc-config.php`.
- Preferred: move the file to a non-web path the hub code reads from, OR, if a
  given box genuinely runs SMACK CENTRAL from that root, deny direct web access
  with a server rule (e.g. an Apache `<Files "sc-config.php"> Require all denied`
  or the nginx equivalent) AND confirm it is not includable via any traversal.
- Do **not** rely on the zero-byte 200 as the control — that is a side effect of
  the current PHP handler, not a deliberate deny rule.
- This is containment only. It does not undo the backup or tag copies. Rotation
  (below) is what actually closes the exposure.
- Caveat: AGENTS.md forbids agents from FTPing to a SnapSmack install. This step
  is yours; I am not doing it, and I am not scripting an FTP.

### Step 1 — GitHub token (instant, zero fleet impact)

- **Revoke** `SNAPSMACK_GITHUB_TOKEN` in GitHub now (Settings → Developer settings
  → Personal access tokens → revoke). Revocation is immediate and one-click.
- Before or right after revoking, check the token's **scopes** and GitHub's audit
  log / security log for use you did not make. The sample claims "no scopes needed
  for public repos," but the live token's real scopes are unknown to me and
  decide the blast radius:
  - read-only / no-scope on a public repo → low blast radius (worst case: someone
    used your rate limit).
  - `repo` / write / `workflow` scope → **high**. A leaked write token can push
    commits, move tags, or alter releases. If the scope was write-capable, treat
    this as the second-most-urgent item after the signing key, and review repo
    history and tags for unexpected changes.
- Generate a replacement, put it in `sc-config.php` on the hub only. Blast radius
  of the fix: the Release Packager and Skin Packager changelog auto-fill and git
  pulls on the hub (`smack-central/sc-release.php`, `sc-skins.php`). No site is
  affected.

### Step 2 — Databases and forum mod key (hub-side; coordinate the shared forum DB)

- **SMACK CENTRAL DB** (`SC_DB_*`): change the DB user's password on the DB server,
  update `sc-config.php` on the hub. **Shared-DB catch:** the sample says this is
  the same database as forum-server (`squir871_smackforum`). If `SC_DB_USER` is
  the same account forum-server uses, changing its password also breaks the forum
  until `forum-server/api/forum/config.php` is updated too. Decide whether to
  rotate the shared account (update both files in one window) or split SMACK
  CENTRAL onto its own DB user with least privilege (better, slightly more work).
- **SMACK THE ENEMY DB** (`STE_DB_*`): change `squir871_enemyadmin`'s password,
  update `sc-config.php`. Isolated system; blast radius is the enemy/reputation
  tables only.
- **FORUM_MOD_KEY**: regenerate in `forum-server/api/forum/config.php`, update
  `sc-config.php`. Blast radius: the hub↔forum moderation integration only. No
  public site uses this key.
- None of Step 2 touches a SnapSmack site install. It is all hub + forum.

### Step 3 — Release signing key (do last; highest blast radius; use the built channel)

This is the one that reaches the whole fleet. It is also the one the system was
designed to rotate safely, because the trust anchor (root key) did not leak.

**What signs and what verifies (so the blast radius is concrete):**
- The hub signs each release's SHA-256 checksum with `SMACK_RELEASE_PRIVKEY`
  (`smack-central/sc-release.php`).
- Every install carries the matching **public** key in `core/release-pubkey.php`
  (gitignored, protected from updates). The updater verifies a package's Ed25519
  signature against it (`core/updater.php:653` `updater_verify_signature`,
  `updater_verify_package`). A real pubkey makes signing **enforced**: a package
  that fails the signature is refused ("This package cannot be trusted").
- The current fleet public key is `b0cbadef25a6…f53bc2` (the self-heal canonical
  in `core/updater.php:60`; public, not a secret).

**How to rotate across the fleet without FTPing every box** — the system already
has this, verified against an **offline root key**:
1. Generate a new release keypair offline (the procedure is in
   `sc-config.sample.php`'s Release Signing comment).
2. Publish a signed rotation announcement: `key-rotation.json`
   (`{new_pubkey, old_pubkey, issued_at, reason}`) plus `key-rotation.sig`, the
   JSON signed by the **root private key** (offline, Bitwarden). Put both at
   `https://snapsmack.ca/releases/key-rotation.json` and `…/key-rotation.sig`
   (`core/updater.php:187-189`).
3. Each install's updater runs `updater_fetch_key_rotation()`
   (`core/updater.php:2234`): it downloads both, verifies the JSON against the
   hardcoded `SNAPSMACK_ROOT_PUBKEY` (`:185`), and rewrites its own
   `core/release-pubkey.php` to the new pubkey via `updater_repair_pubkey()`
   (`:2159`). No FTP, no manual per-site edit.
4. Update the hub's own `core/release-pubkey.php` to the new pubkey **before**
   building the next release — `sc-release.php`'s preflight **blocks the build**
   if its derived pubkey does not match `core/release-pubkey.php` (`:66-85`),
   precisely so you cannot ship a release the fleet will reject.
5. Build and publish a fresh release **signed with the new key**, and re-point
   `latest-dev.json` to it.

**What rotation does to existing signed releases (the part the PM asked for):**
- Running installs are **not** affected at rotation time — a site does not
  re-verify the build it is already running. No site goes down because you rotated.
- Once a site adopts the new pubkey, any package still signed with the **old** key
  fails verification on that site. So every release JSON the fleet might install
  after rotation — above all `latest-dev.json` — must point at a package signed
  with the **new** key. Old-key packages become uninstallable (not deleted, just
  untrusted) until re-signed. This is why Step 3 is "rotate **and** publish a
  fresh new-key-signed release," not "rotate" alone.
- Never rotate the pubkey to the all-zero placeholder: that disables Ed25519 and
  drops the fleet to SHA-256-only (`updater_verify_signature`, `:664`). Zero is a
  dev-only state, not a rotation target.
- The root key itself is NOT rotated here and should not be. It is the anchor that
  made this safe. Rotating it would be a separate, much larger operation and is
  not required by this incident, because it never sat in `sc-config.php`.

## Why rotation is the closure (and removal alone is not)

The file is in six webroots, one backup, and 19 git tag trees. You can get it out
of the webroots (Step 0) and you can stop tracking it (already done — current dev
tracks neither config path and both are gitignored). You cannot remove it from
published git tags without rewriting and force-moving refs others may hold, which
AGENTS.md forbids once a tag is on origin, and you cannot un-ship the 8/17 backup.
So the copies will persist. Rotating all five secret classes is what makes those
persistent copies carry only **dead** credentials. After Steps 1–3, the sc-config
copies in the tags and the backup are worthless, which is the actual close
condition for the exposure.

## What stays Sean's decision

- Whether to rotate the shared forum DB account in place or split SMACK CENTRAL to
  its own least-privilege DB user.
- Timing of the signing-key rotation (it should be paired with a planned release).
- Whether the GitHub token's real scope makes it urgent-high or low.
- Any git-history question about the 19 tags goes to Codex's read-only brief
  (PM 03:00, item 3); this runbook does not touch history.

## Status

028 is **OPEN**. This runbook is design only; nothing here has been applied. The
exposure has existed in webroots since at least 2026-08-17. Closure requires Sean
to execute Steps 0–3; an agent may then verify, read-only and metadata-only, that
`sc-config.php` is gone from the six webroots and that a new-key-signed release
verifies on a test install — never by reading any secret value.

<!-- ===== SNAPSMACK EOF ===== -->
