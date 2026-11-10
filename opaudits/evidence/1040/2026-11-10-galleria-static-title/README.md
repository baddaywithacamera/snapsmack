<!-- SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below. -->
# SS-1040 — GALLERIA static-title acceptance

## Result

PASS in the current source at all supported GALLERIA widths: 1920, 1440 and
1024 pixels.

- The Blogroll heading and first link group share the same x-coordinate and
  width: `580 / 760`, `340 / 760`, and `132 / 760` respectively.
- The valid About fixture keeps its pre-repair title geometry and its heading
  remains aligned with the static-page content.
- Neither page has horizontal overflow.
- The pre-repair stylesheet reproduces the Blogroll fault at every width,
  proving that the check can fail for the reported defect.

The existing live-page runner had requested `/about` from
`hekeepsdroningon.ca`, where that route currently returns the GALLERIA
`Not found` view. Its generic `h1` measurement therefore was not About-page
evidence. This acceptance runner replaces that invalid comparison with the
actual markup shape emitted by `core/galleria-page-component.php` for About
and Blogroll responses.

## Scope boundaries

- Current source geometry: **PASS**.
- Installed Blogroll geometry recorded on 2026-11-08: **PASS**.
- Installed About page: **NOT CHECKED** because the checked GALLERIA site has
  no About response at `/about`.
- Owner acceptance: **NOT CHECKED**.
- Other skins exposing a Blogroll surface: inventoried in
  `opaudits/evidence/027/2026-10-29-galleria-blogroll-title/source-inventory.json`;
  each remains **NOT CHECKED** because this ticket's fault was GALLERIA-specific
  presentation rather than a change to the shared Blogroll renderer.

Run from the repository root with:

```powershell
$env:NODE_PATH='C:\dev\snapsmack\tools\browser-tests\node_modules'
node tools/browser-tests/galleria-static-title-acceptance.cjs
```

<!-- ===== SNAPSMACK EOF ===== -->
