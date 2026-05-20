<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->


# Smack Up Your Backup — Versioning

## Scheme

SUYB uses the same `0.7.9x` version scheme as SnapSmack. The base (`0.7.9`) tracks which SnapSmack release SUYB was built alongside. The letter suffix increments independently per SUYB release.

```
SnapSmack:  0.7.9P
SUYB:       0.7.9d   ← letter is SUYB's own counter, not tied to SnapSmack's letter
```

## Where the version lives

- `BUILD_VERSION` in `main.py` — displayed in window title, About dialog, and manifest.
- Top entry in `CHANGELOG.md` — must match `BUILD_VERSION`.

These two must always be in sync. If they disagree, `BUILD_VERSION` is wrong.

## How to bump

1. Increment the letter in `BUILD_VERSION` (`main.py`).
2. Add an entry at the top of `CHANGELOG.md` with the new version and date.
3. Copy the previous `.spec` file to the new version name and update the `name=` line inside it:
   ```
   copy smackupyourbackup-0.7.9c.spec smackupyourbackup-0.7.9d.spec
   ```
   Then edit the new file: change `name='smackupyourbackup-0.7.9c'` → `name='smackupyourbackup-0.7.9d'`.
4. Rebuild: `strip_nulls.py` → `build.bat`.

Do NOT use a separate numbering scheme (e.g. `0.2.x`). That was an error.

## Build

```
python strip_nulls.py
build.bat
```

Output exe goes to `dist/`. Copy to `C:\SmackUpYourBackup\` to deploy.
<!-- ===== SNAPSMACK EOF ===== -->
