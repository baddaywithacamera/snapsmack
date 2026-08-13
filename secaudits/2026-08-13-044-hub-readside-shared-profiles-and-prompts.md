<!-- SNAPSMACK_EOF_HEADER: the last non-empty line must be the canonical HTML EOF marker. -->

# SECAUDIT 044 - Hub read-side: shared profile store (GYSS), shared Gemini prompts, launcher exe-discovery

| Field | Value |
|---|---|
| Date | 2026-08-13 |
| Scope | The Hub read-side wiring committed in `577f6440`: GYSS shared-profile store (`tools/gyss/src/scripts/profiles.js`, `paths.js` `siteKey()`); shared Gemini prompts (`tools/_shared/snap_prompts.py`, `snap_home.prompts_dir()`, `tools/sybu/config.py` + `tools/coldsnap/config.py` load/save + migration); THE HUB launcher exe-discovery (`tools/hub/main.py` `_find_exe`/`ROSTER`, v0.1.1). |
| Baseline | `dev` @ `577f6440` (this work is COMMITTED, not uncommitted). |
| Status | **No HIGH/MEDIUM findings. Two LOW items, both accepted + documented. Source-level release gate: satisfied for these desktop tools.** |
| Positive controls | All FS writes atomic (`os.replace`/tmp); an unreadable prompts store is preserved as `.corrupt` before overwrite (never silently wiped); profile/prompt filenames pass through `site_key()`/`siteKey()` which reduce to `[a-z0-9.-]`, strip leading/trailing `.-`, and throw on empty (fail-closed, no traversal); GYSS FS ops additionally go through the Rust `resolve_in_root` jail; the launcher runs exes with list-form `subprocess.Popen([path])` (no shell); migrations are marker-guarded, idempotent, and never delete legacy data; no SQL, no `eval`, no network endpoints are introduced by this diff. |
| Disclosure | No exploitation known. This surface is OFFLINE desktop tooling running as the owner on the owner's machine against the owner's own `C:\snapsmack\shared_library`. The only adversary in scope is another LOCAL process/account able to write into `shared_library` or a tool's install dir — which per SECAUDIT 037 already holds the credentials those folders contain, so it is a post-compromise position, not a remote one. |

## 1. Executive result

This change moves GYSS off its private profile folder onto the SHARED cross-tool
store, adds a SHARED Gemini-prompt store used by SYBU + COLD SNAP, and broadens
THE HUB's launcher so it can find versioned/relocated tool exes. It is
low-risk: it introduces no network surface, no auth boundary, no SQL, and no
shell. Everything it does is local JSON read/modify/write inside one
owner-owned directory tree, plus launching the owner's own installed exes.

The design holds the two properties that matter for a local store: writes are
atomic and never truncate on failure, and a hostile/corrupt local file degrades
gracefully (skipped, or preserved as `.corrupt`) rather than crashing the tool
or being executed. Filenames derived from a site URL cannot traverse out of the
store. Nothing here weakens the existing credential posture — the genuinely
secret material still lives in the `shared_library/auth` vault, unchanged.

Two items are recorded as LOW and accepted: the launcher will run whatever
matches a wildcard in a known install dir (Finding A), and GYSS now trusts a
locally-stored profile's `site_url`+`api_key` it did not itself author (Finding
B). Both require local write access to owner-only directories — the same
"local write == game over" boundary SECAUDIT 037 already established for these
tools — and neither is made materially worse than the pre-existing single-tool
behaviour.

## 2. Trust-boundary map

```text
owner, at the keyboard, on the owner's Windows machine
  -> THE HUB               globs known install dirs, launches an exe (list-form, no shell)
  -> SYBU / COLD SNAP      read/modify/write shared_library/prompts/gemini_prompts.json
  -> GYSS / SYBU           read/modify/write shared_library/profiles/<site_key>.json
                           (GYSS FS also passes the Rust resolve_in_root jail)

local same-machine writer of shared_library/* or C:\<tool>\*   (POST-COMPROMISE ONLY)
  -> can plant a profile/prompt file or a wildcard-matching exe   (Findings A, B)

network
  -> NONE introduced by this diff. The api_key still travels only via each tool's
     pre-existing HTTPS API client to the owner-entered site_url (out of scope).
```

The shared stores are per-blog CONNECTION data + prompt text, not the secret
vault. `api_key_enc` is base64 (obfuscation, not encryption) — the deliberate,
pre-existing family convention (see `snap_profiles.py` docblock and SECAUDIT
037/039); this diff writes to the SAME store the Python tools already used and
changes that posture in no direction.

## 3. Finding A - launcher runs the newest wildcard-matching exe in a known install dir (LOW, ACCEPTED)

`tools/hub/main.py` `_find_exe()` now accepts glob candidates and returns the
most-recently-modified match:

```python
if any(ch in p for ch in "*?["):
    matches = [m for m in glob.glob(p) if os.path.isfile(m)]
    if matches:
        return max(matches, key=os.path.getmtime)
```

For SUYB and OH SNAP the roster includes wildcards (`C:\SUYB\smackupyourbackup*.exe`,
`C:\snapsmack\ohsnap\*.exe`) because SUYB installs under a versioned filename. If
a local attacker can write into one of those dirs, dropping a newer
`smackupyourbackup-evil.exe` would cause the Hub to launch it (newest mtime wins).

Why LOW/accepted: (1) launching the owner's installed tools from owner-controlled
install dirs IS the launcher's purpose; (2) it requires local write to those dirs,
i.e. a pre-existing compromise; (3) the exact-path entries (SYBU, GYSS, COLD SNAP,
and the first SUYB/OH SNAP candidates) are unaffected — only the wildcard tail
has this breadth; (4) invocation is list-form `subprocess.Popen([path], cwd=...)`,
so there is no argument/shell injection, only the choice of which existing exe
runs. Guard to keep: never extend a wildcard candidate to a writable/temp/shared
location (`%TEMP%`, `Downloads`, a network share). Prefer, later, pinning SUYB to a
single canonical install path so the wildcard can be dropped.

## 4. Finding B - GYSS trusts a locally-stored profile it did not author (LOW, ACCEPTED)

After this change GYSS reads profiles from the shared store that another tool
(or the Hub's Discover Fleet) wrote. A profile file supplies BOTH the `site_url`
GYSS will connect to and the Bearer `api_key` it will send. A local writer of
`shared_library/profiles/<site_key>.json` could therefore repoint GYSS's Bearer
key at an attacker URL (credential redirection).

Why LOW/accepted: (1) it requires local write to `shared_library/profiles` —
already secret-equivalent per SECAUDIT 037, so a post-compromise position; (2)
this is inherent to ANY shared local store and is the same trust GYSS already
placed in its own private profile files; (3) GYSS still warns before using a
non-`https://` target (`confirmInsecureUrl`, SECAUDIT 039), which blunts the
plainest exfil-to-http variant. No code change is required; recorded so the
"shared_library lives on an owner-only path" assumption stays explicit.

## 5. Positive controls verified (not findings)

- **No path traversal.** `siteKey()` (JS) and `snap_home.site_key()` (Py) are
  byte-identical, reduce to `[a-z0-9.-]`, strip leading/trailing `.-`, collapse
  repeats, and RAISE on empty — a crafted `site_url` cannot yield `..`, an
  absolute path, or an empty name. GYSS FS additionally passes `resolve_in_root`.
  Verified with a JS/Py parity harness over the fleet + `user:pw@host:port` +
  IDN (`münchen.de`) + traversal-ish inputs.
- **Atomic, non-destructive writes.** `snap_prompts.save()` and
  `snap_profiles.save()` write `*.tmp` then `os.replace`; GYSS uses the jailed
  `write_file`. `snap_prompts.save()` moves an unreadable existing store to
  `.corrupt` BEFORE overwriting, so a single bad read cannot wipe presets.
- **Graceful handling of hostile/corrupt local data.** `listProfiles`/`readProfile`
  skip non-profile / malformed JSON; base64/`atob` failures are caught → `''`;
  `load()` returns `{}` on unreadable. No crash, no execution.
- **Migrations are safe.** Legacy→shared migrations (GYSS profiles; SYBU/COLD SNAP
  prompts) are marker-guarded (global + per-file for GYSS), idempotent, never
  delete or overwrite legacy files, and never resurrect a user-deleted item — the
  latter proven including the marker-write-failure case.
- **Prompt delta-merge cannot silently lose data.** `config.save_prompts` applies
  only this process's delta onto the fresh on-disk store, so a concurrently-running
  sibling tool's added presets survive; deletions only remove an entry whose
  on-disk value still matches what this process loaded.
- **No injection primitives.** No SQL, no `os.system`/`shell=True`, no `eval`, no
  templating, no new network endpoints in this diff.

## 6. Verification performed

- Executable harnesses (all green): GYSS↔Python profile interop (bidirectional,
  UTF-8 + IDN keys, siteKey parity); GYSS migration incl. deleted-profile
  resurrection cases; prompt concurrency (stale sibling add not clobbered) +
  corrupt-store recovery; user-override vs built-in preset semantics.
- `node --check` on the changed JS; `py_compile` on the changed Python; EOF-marker
  check on every changed file.
- Adversarial multi-agent functional review of the whole diff: 5 findings, all
  fixed and re-verified (2 were data-loss class: concurrent prompt clobber and
  corrupt-store wipe — both closed and re-tested here).
- No multi-user / hostile-local-writer environment was exercised; Findings A and B
  are reasoned from the code and the SECAUDIT 037 local-trust model, not reproduced.

## 7. Release gate

These are DESKTOP tools: they ship by building from the checkout into
`C:\snapsmack\<tool>` (and `C:\GYSS`), NOT through the core `0.7.x` updater, so
there is no fleet-facing rollout tied to this audit. Source-level gate is
satisfied — no HIGH/MEDIUM findings; Findings A and B are LOW and accepted under
the existing local-trust model. Operational note for the owner: keep
`C:\snapsmack\shared_library` and the tool install dirs on an owner-only path
(not a shared/multi-user or sync-shared location), which is the assumption both
LOW items rest on.

<!-- ===== SNAPSMACK EOF ===== -->
