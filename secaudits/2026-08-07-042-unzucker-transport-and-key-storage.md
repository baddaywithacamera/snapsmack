<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, space, the literal string
  'SNAPSMACK EOF', space, five equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# SECAUDIT 042 — Unzucker: transport, key storage, and a false closure in 040

Date: 2026-08-07
Scope: `tools/unzucker/` (build 0.7.39) — `main.py`, `config.py`, `poster.py`,
`ig_parser.py`
Status: **three findings, all closed in this pass**

## Why Unzucker

FLKR FCKR's `config.py` states it was *forked from `tools/unzucker/config.py`*.
SECAUDIT 040 found four issues in that fork. The working hypothesis was that some
were still live in the original. Two were. The more interesting result is the
third, which is about the previous audit rather than the code.

## Finding A — the API key crossed plaintext HTTP, and 040 believed otherwise (CLOSED)

**Severity: credential disclosure on every request.**

`UnzuckerClient.__init__` sets `Authorization: Bearer <key>` as a session header
and every call inherits it. There was **no scheme check anywhere** in `main.py`,
`poster.py` or `config.py` — a grep for `https` across all three returned
nothing. Configure the site URL as `http://` and the scoped `threeacross` key —
which can create posts and upload images — went across the network in the clear
on ping, upload, post, every request, forever.

**The part worth recording.** SECAUDIT 040 fixed exactly this class of bug in
`tools/_shared/snap_stepup.py` and recorded that the shared fix "closes the same
gap in **Unzucker**, GYSS, SUYB, SYBU and Oh Snap at the same time."

For Unzucker that was **not true**. Unzucker never imported `snap_stepup` at all.
The fix was real, the helper was correct, and the blast radius was wrong — a
shared safety helper only protects the tools that call it, and nothing in the
tree could tell the difference between "covered" and "never wired up". The audit
trail said protected; the code said nothing.

**Fix.** Unzucker now imports `confirm_insecure_transport()` from the shared
helper and calls it in `_on_connect()` **before** `UnzuckerClient` is
constructed — because constructing it puts the key in a session header and the
next line sends it. Warn-and-confirm rather than hard refusal, which is the line
SECAUDIT 039 drew for GYSS: refuse outright for an account password, warn and
confirm for a scoped key. Loopback is exempt; there is no network path to sit on.

If the shared helper cannot be imported (an old build without `_shared/`), the
local fallback **fails closed** and refuses any non-`https://` URL.

## Finding B — a failed keyring write destroyed the API key (CLOSED)

**Severity: data loss, no security impact.**

`config.save()` did:

```python
if _HAS_KEYRING:
    _kr_set(_api_key_account(url), api_key)
    ini_api_key = ''   # wipe any old base64 value so it doesn't linger
```

`_kr_set()` returns `False` on **any** keyring failure — no backend installed, a
locked keychain, D-Bus absent under a headless Linux session — and it swallows
the exception to do it. That return value was ignored. So when the keyring
refused the secret, the ini was wiped anyway: the keyring did not have the key,
the ini no longer had it, and the next launch came up blank with no explanation.

The fallback path was correct; it just was not reachable when it was needed.

**Fix.** `if _HAS_KEYRING and _kr_set(...)` — the ini is only wiped when the
keyring confirms it actually took the secret, otherwise the base64 fallback is
written as designed. A behavioural test forces the keyring to refuse and asserts
the key still round-trips.

## Finding C — `unzucker.ini` was world-readable (CLOSED)

SECAUDIT 040 applied an owner-only permission floor to `flkrfckr.ini` because
that file can hold the API key as base64. Unzucker's ini can hold exactly the
same thing on any machine without a working keyring, and never got the floor.

**Fix.** `os.chmod(path, 0o600)` after write, best-effort and swallowed — a no-op
on FAT, only partly meaningful on NTFS, and a permissions hiccup must never cost
the operator their settings. It is the floor. The keyring is the ceiling.

## Reviewed and sound — no findings

- **`ig_parser.py` path containment.** An Instagram export is a third party's
  archive and its `media[].uri` values are untrusted. The parser resolves each
  through `os.path.normpath(os.path.join(export_root, uri))` and requires the
  result to sit under the export root, rejecting absolute paths and `../..`
  traversal with an explicit error. This was already right, and is now pinned by
  a regression so it stays right.
- **Keyring scoping.** Secrets are stored per site URL
  (`{url}:api_key` under service `unzucker`), so two sites cannot collide or
  read each other's key.
- **Bearer-only auth.** No session scraping, no password handling, so the harder
  step-up refusal in `snap_stepup.request_authorization()` does not apply here.

## Regression

`tools/unzucker/tests/test_security_regressions.py` — **8 tests**, verified to
fail against the pre-fix tree before being accepted.

The load-bearing one is `test_the_guard_is_actually_wired_in`. It asserts both
the import **and** the call site, because Finding A's real lesson is not "Unzucker
lacked an HTTPS check" — it is that a shared helper a tool never calls is
indistinguishable from no helper at all, and that the resulting audit comes back
green. `test_the_guard_runs_before_the_client_is_built` pins the ordering, since
a check that runs after the session header is set protects nothing.

## The other four tools 040 named — checked, not assumed

040 recorded GYSS, SUYB, SYBU and Oh Snap as covered by the same shared fix.
Rather than repeat the mistake of trusting that sentence, each was checked for
**both** the shared helper and any equivalent local guard:

| Tool | Shared helper | Own guard | Verdict |
| --- | --- | --- | --- |
| Unzucker | now wired | — | **fixed in this audit** |
| GYSS | not used | `confirmInsecureUrl()` in `src/scripts/main.js` | **covered** — it is a Tauri app, so the Python helper was never applicable; SECAUDIT 039 gave it its own gate |
| SUYB | not used | none found | **needs its own audit** |
| SYBU | imports `snap_stepup`, never calls the transport gate | none found | **needs its own audit** |
| Oh Snap | not determined | not determined | unchecked — the directory is ~4 GB and the scan timed out |

GYSS is the reason this table exists rather than a blanket claim: a grep for the
shared helper alone would have marked it vulnerable when it is fine. The check
that matters is "does this tool refuse or warn on `http://` **somehow**", not
"does it import our module".

SUYB and SYBU show no scheme check by either route. That is *grounds for an
audit*, not a confirmed finding — neither was read line by line here, and both
may constrain the URL somewhere this scan did not reach. **SECAUDIT 043 should
be SUYB and SYBU**, in that order: SUYB holds FTP/SFTP credentials and cloud
tokens, which is a heavier payload than a scoped key.

<!-- ===== SNAPSMACK EOF ===== -->
