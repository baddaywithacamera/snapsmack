# SECAUDIT 028 - sc-config.php exposure: remediation runbook

| Field | Value |
|---|---|
| Audit ID | 2026-11-10-028 (remediation for the historical-exposure finding under audit 028) |
| Opened | 2026-11-10 |
| State | CLOSED — stale root sc-config.php removed from all affected sites in 0.7.992D. Owner-accepted, own-site credentials, rotation at server bifurcation. |
| Author | Claude Code (design only) |
| Executes | Sean, and Sean alone. Key material is his. |
| Measured basis | Codex, Brain Trust m0csLNuZ (webroots) + y4kae4Tc (public remote, 19 tags) + Xhh8sJRe (no current code path reads the root file; six copies stale) + _continuity/2026-11-10-secaudit-028-presence-check.md |

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
- Live HTTP (Codex final, y4kae4Tc): foundtextures.ca, hekeepsdroningon.ca,
  photowalk.ing, pixhellated.ca, wateronthebrain.ca return HTTP 200 with a
  **zero-byte** `text/html` body — the file is routed through PHP and produces no
  output, so it is **not served as source text** under the current config.
  acolourlesslife.ca returns 500/zero bytes: the Apache error log shows a PHP
  parse error at line 46 (the file was not opened). `/smack-central/sc-config.php`
  returned 404 on the checked site. A zero-byte 200 means it is not being served
  as plaintext **right now**; it is not evidence the secrets are safe.
- **This is NOT the only exposure, and the webroot is not the worst of it (see the
  UPDATE below).** The file is also in the 2026-08-17 CT102 backup and — confirmed
  public — in 19 tags on the public GitHub remote.
- Treat every one of the five secret classes above as **publicly disclosed**, not
  merely "potentially compromised." Rotate all five on that basis. Do not treat
  the zero-byte 200 as evidence the secrets are intact.

## UPDATE 2026-11-10 — CONFIRMED PUBLIC remote exposure (Codex final read-only, y4kae4Tc)

This arrived after the first draft and raises the severity. Codex's final
read-only inventory (tree names only; no protected content opened) found:

- The public GitHub remote carries the file **right now**: of 1,281 tags, **19 still
  contain root `sc-config.php`** (Alpha-0.7.9M; alpha-0.7.9O; two archive/claude-*
  tags; v0.7.17/.18/.19/.21/.22/.23/.24/.25; v0.7.9b/9L/9M/9M-image-fade-load/9N/9O/9P).
  122 branch heads are clean; 0 pull refs.
- The repository **is public now** and was public ~2 months ago while those tags
  existed, so the exposure is not merely historical — anyone can clone the repo,
  check out one of those 19 tags, and read every secret in the file today.
- The **GPL history rewrite did not remove it** — the rewrite only purged licence
  material. Both the pre-rewrite mirrors and the GPL-rewritten mirror retain the
  same 19 affected tag trees.

What this changes: the webroot copies (Step 0) are the *least* of it. The live,
unauthenticated, public copy is on github.com. That makes **secret rotation the
urgent, primary action** — the GitHub token revoke (Step 1) and the signing-key
rotation (Step 3) are responses to a live public disclosure, not precautions.

New decision point for Sean/PM (it is theirs, not an agent's): whether to **delete
the 19 affected tags from the public remote**. Honest tradeoff:
- Deleting them stops *new* readers from pulling the secrets via those tags, and
  is the only way to reduce the public copy at all.
- It does NOT close the exposure. The tags are immutable under AGENTS.md once on
  origin; anyone who already cloned, and both mirror sets, still hold them. So
  deleting tags is containment-of-further-spread, never closure.
- Deleting published tags breaks anyone relying on them and is itself a
  significant, irreversible repo action — exactly the class AGENTS.md reserves to
  the owner. I am not proposing to do it; I am flagging it as a decision.

Closure does not depend on deleting the tags. Rotation (Steps 1–3) is what makes
every public, backup, and already-cloned copy carry dead credentials. Do rotation
regardless of what is decided about the tags.

## Remediation order (why this order)

Order is driven by two things: how fast and cheap the step is, and how much it
reduces active risk. Do the instant, zero-fleet-impact kills first; do the
highest-blast-radius operation (signing key) last and deliberately.

### Step 0 — Containment: remove the six stale ROOT copies (do first, it is cheap and verified safe)

Goal: stop ongoing and future web exposure without waiting on rotation.

**Verified safe to remove (Codex read-only, Xhh8sJRe):** a repository-wide
current-dev source search found NO `include`, `require`, `is_file`, or
`SNAPSMACK_ROOT`-style path that reads `/sc-config.php` at the webroot root. The
only runtime readers of an sc-config are the **`/smack-central/sc-config.php`**
path (`core/updater.php`, `core/smackback.php`, and snapsmack.ca's
`sc-network-api.php`). So the six root copies are stale duplicates; removing them
does not break any current SnapSmack code path. All six also pre-date the 8/17
backup; acolourlesslife's root copy is additionally **malformed** (the line-46
parse error), i.e. already non-functional.

- For each of the six webroots, **remove (or move out of the document root) the
  ROOT `sc-config.php`**. The correct end state is that these six sites have no
  root `sc-config.php`. A site webroot is the wrong place for a SMACK CENTRAL
  config to live at all.
- **Do NOT touch `/smack-central/sc-config.php`.** That is the LIVE hub config that
  holds the current secrets and that the running code actually reads. Removing it
  would break the hub. Its secrets are handled by rotation (Steps 1–3), not by
  deletion. (On the checked site `/smack-central/sc-config.php` returned 404; where
  it exists it stays.)
- If for any reason a box cannot have the root copy removed immediately, deny
  direct web access at the server level (e.g. Apache
  `<Files "sc-config.php"> Require all denied </Files>` or the nginx equivalent) as
  a stopgap — but removal is the goal, since nothing current reads the root file.
- Do **not** rely on the zero-byte 200 as the control — that is a side effect of
  the current PHP handler, not a deliberate deny rule.
- This is containment only. It does not undo the backup, the mirror, or the public
  tag copies. Rotation (below) is what actually closes the exposure.
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

The file is in six webroots, the 8/17 backup, and 19 tags on the **public** GitHub
remote (confirmed — see the UPDATE above). You can get it out of the webroots
(Step 0) and you can stop tracking it (already done — current dev tracks neither
config path and both are gitignored). Deleting the 19 public tags is a Sean/PM
decision (UPDATE above) and at best contains further spread; it cannot close the
exposure, because both mirror sets and anyone who already cloned still hold them,
and AGENTS.md makes published tags immutable. So the copies will persist. Rotating
all five secret classes is what makes those persistent copies — public tags,
mirrors, backup, webroots — carry only **dead** credentials. After Steps 1–3 the
sc-config copies are worthless, which is the actual close condition for the
exposure, whether or not the tags are ever deleted.

## What stays Sean's decision

- Whether to rotate the shared forum DB account in place or split SMACK CENTRAL to
  its own least-privilege DB user.
- Timing of the signing-key rotation (it should be paired with a planned release).
- Whether the GitHub token's real scope makes it urgent-high or low.
- Whether to delete the 19 affected tags from the public remote (UPDATE above):
  containment of further spread only, irreversible, and owner-reserved under
  AGENTS.md. Rotation does not depend on this either way.
- The git-history / public-exposure questions are now ANSWERED by Codex's final
  read-only result (y4kae4Tc): repo public, 19 tags still carry the file, GPL
  rewrite did not purge it, mirrors retain it. This runbook does not touch history.

## Status

028 is **CLOSED**: stale root `sc-config.php` removed from all affected sites in
0.7.992D. Owner-accepted, own-site credentials, rotation at server bifurcation.
No claim is made that rotation is complete.

<!-- ===== SNAPSMACK EOF ===== -->
