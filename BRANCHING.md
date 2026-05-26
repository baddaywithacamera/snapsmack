# SnapSmack Branching Strategy

Full spec: `_spec/branching-strategy-spec-v0_1.docx`

## Five-sentence summary

`master` is stable — tagged releases only, what live sites pull, never broken.
`dev` is where the work happens — D-suffixed versions (e.g. `0.7.184D`), broken intermediate states allowed, all new code lands here first.
Feature branches (`feature/<name>`) cut from `dev` and merge back to `dev`; hotfix branches (`hotfix/<name>`) cut from `master` and merge to **both** `master` and `dev`.
Every spoke has an `update_track` setting — `stable` (display: **Boring**) or `dev` (display: **Bitchin'**) — set by the spoke admin on the spoke, never changed from the hub.
`dev → master` merges require passing verification gates and joint sign-off from Sean and Claude; Cowork never commits directly to `master`.

## Git branch names

| Display name | Git branch | Version suffix |
|---|---|---|
| Boring | `master` | none — clean semver (`0.7.184`) |
| Bitchin' | `dev` | `D` — always (`0.7.184D`) |

**`master` is never renamed to `stable`.** The display name lives in docs and UI chrome only.

## Rules for Cowork

- Work on `dev` unless explicitly told otherwise.
- Never commit to `master`.
- Never change a spoke's track from the hub manager — track changes happen on the spoke.
- Never build a track-switch button on the hub roster — the omission is deliberate.
- Never commit a D-suffixed version string to `master`.
- `eval()` is always a violation regardless of `skin_allow_custom_js`.
- If you find yourself about to do any of the above, stop and re-read `_spec/branching-strategy-spec-v0_1.docx`.

<!-- ===== SNAPSMACK EOF ===== -->
