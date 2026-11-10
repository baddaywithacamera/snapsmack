<!-- SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker. -->
# SS-1255 — NEW HORIZON typography colour acceptance

## Result

PASS in source-equivalent acceptance at 1920px and 1024px for both dark and
light variants. Installed-build verification and owner acceptance were not
checked.

The test executes the actual Skin Admin persistence block from
`smack-skin.php` against an isolated SQLite settings table. It then performs a
fresh settings query, applies NEW HORIZON's scoped settings overlay, compiles
the declared presentation CSS, and renders the result in Chromium.

No live database, owner setting, package, build, or deployed site was changed.

## Complete path checked

- PASS — valid colour values enter the real Skin Admin save loop.
- PASS — values are stored under `new-horizon__*` scoped keys.
- PASS — a fresh settings query retains all ten tested values.
- PASS — declared presentation CSS is compiled from the reloaded values.
- PASS — header, tagline, heading, body, description, and footer visibly use
  their changed colours.
- PASS — saving the advertised defaults restores the existing dark/light
  shades.
- PASS — no horizontal overflow at 1920px or 1024px.
- NOT CHECKED — installed-build verification.
- NOT CHECKED — owner acceptance.

## Files

- `save-reload.json` — actual persisted and freshly reloaded values.
- `measurements.json` — computed browser colours and overflow measurements.
- `dark-*.png`, `light-*.png` — default, changed, and reset captures at both
  supported widths.

## Reproduction

```powershell
$fixture = 'C:\dev\snapsmack-staging\ss-1255-fixtures'
$evidence = 'opaudits\evidence\1255\2026-11-10-new-horizon-font-colours'
C:\php\php.exe tools\browser-tests\new-horizon-font-colour-fixtures.php $fixture
$env:NODE_PATH = 'C:\dev\snapsmack\tools\browser-tests\node_modules'
node tools\browser-tests\new-horizon-font-colour-acceptance.cjs $fixture $evidence
```

<!-- ===== SNAPSMACK EOF ===== -->
