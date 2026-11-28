<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, a space, the literal
  string SNAPSMACK EOF, a space, and five equals.
-->

# SECAUDIT Number Register

Index of used, reserved, and open numbers in `secaudits/`.

| Range / Number | Status | Notes |
|---|---|---|
| `001`–`029` | Used | Early codebase, installer, SMACKBACK, desktop, and recovery audits (`021A`, `024A` addenda; `028` runbook) |
| `030`–`049` | Used | Skin manifest, SMACKVERSE, SUYB, GYSS, FLKR, Pixelix, and CMS compliance audits |
| `050`–`051` | Open | Unallocated gap in sequence |
| `052`–`060` | Used | `llms.txt`, desktop duty-of-care, release dev-dir leak (`055`), Snap Slapper, codebase sweep, skin boundary (`059`, `060`) |
| `061` | Open / Unfiled | Hub-audit ticket (`SECAUDIT 061`) tracked in branch `gemini/secaudit-061-hub-audit` |
| `062` | Used | `2026-10-21-062-upstream-php-emergency-infrastructure-assurance.md` |
| `063`–`065` | Open | Unallocated gap in sequence |
| `066` | Used | `2026-11-17-066-api-key-generation-requires-step-up.md` |
| `067` | **Reserved** | Reserved by **SS-1368** (do not reuse) |
| `068` | Open | Unallocated gap in sequence |
| `069` | Used | `2026-11-17-069-step-up-attempt-budget-and-hq-device-expiry.md` |
| `070` | Used | `2026-11-28-070-1009d-to-1011d-updater-and-public-write-fixes.md` |
| `071`+ | Open | Next available sequential SECAUDIT number |

<!-- ===== SNAPSMACK EOF ===== -->
