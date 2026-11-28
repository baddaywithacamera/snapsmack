<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, a space, the literal
  string SNAPSMACK EOF, a space, and five equals.
-->

# SECAUDIT Number Register

Authoritative index across **both** `secaudits/` (source markdown/PDFs) and `projects/snapsmack-ca/secaudits/` (published PDFs).

Numbers are **not strictly chronological** (for example, `064` is dated `2026-11-11` while `065` is dated `2026-11-08`). Below `070`, **`068` is the only genuinely free number** (`067` is reserved by `SS-1368`).

## Numbered audits (`001`–`070`)

| Number | Status | Files / Notes |
|---|---|---|
| `001`–`003` | Used | Early codebase, contact-form, and installer audits |
| `004` | Used (reused) | `2026-04-26-004-suyb-security-audit.pdf` and `2026-08-07-004-closure-suyb-first-review.md` (same subject: audit and closure) |
| `005`–`020` | Used | Login, post-release, installer, SMACKBACK, hub, and push-it audits |
| `021`, `021A` | Used | Hub/spoke attack surface + addendum |
| `022`–`023` | Used | Canonical schema fetch hardening; Unzucker |
| `024`, `024A` | Used | SYBU/Unzucker + caption XSS addendum |
| `025`–`027` | Used | Skin JS boundary, installer constants, SMACKBACK cron verify gap |
| `028` | Used (**reused collision**) | `2026-06-20-028-package-dev-file-leak-and-smackback-blindspot.pdf` and `2026-11-10-028-sc-config-exposure-remediation-runbook.md` (different subjects, ~5 months apart) |
| `029`–`048` | Used | Auto-deletion, skin RCE, SMACKVERSE, SUYB, GYSS, FLKR, Pixelix, and release audits |
| `049` | Used (4-part) | Four `2026-08-17` files: `049-cms-compliance-and-image-handling.md`, `049-cms-compliance-standard.md`, `049-post-model-consumer-inventory.md`, `049-steps-forward-DRAFT.md` |
| `050` | Used | `projects/snapsmack-ca/secaudits/2026-08-21-050-cms-architecture-assessment.pdf` |
| `051` | Used | `projects/snapsmack-ca/secaudits/2026-08-25-051-multisite-federation-and-snap-slapper.pdf` |
| `052`–`060` | Used | `llms.txt`, desktop duty-of-care, release dev-dir leak (`055`), Snap Slapper, codebase sweep, skin boundary (`059`, `060`) |
| `061` | Used | `projects/snapsmack-ca/secaudits/2026-10-19-061-fleet-enrollment-step-up-provenance.pdf` (branch `gemini/secaudit-061-hub-audit` tracks follow-up verification on `061` B+C, not a new number) |
| `062` | Used | `2026-10-21-062-upstream-php-emergency-infrastructure-assurance.md` |
| `063` | Used | `projects/snapsmack-ca/secaudits/2026-11-03-063-code-placement-boundary-review.pdf` |
| `064` | Used | `projects/snapsmack-ca/secaudits/2026-11-11-064-breach-response-api-write-gate.pdf` |
| `065` | Used | `projects/snapsmack-ca/secaudits/2026-11-08-065-injection-prepared-statement-sweep.pdf` |
| `066` | Used | `2026-11-17-066-api-key-generation-requires-step-up.md` |
| `067` | **Reserved** | Reserved by **SS-1368** (do not reuse) |
| `068` | **Open** | **Only free number below `070`** |
| `069` | Used | `2026-11-17-069-step-up-attempt-budget-and-hq-device-expiry.md` |
| `070` | Used | `2026-11-28-070-1009d-to-1011d-updater-and-public-write-fixes.md` |
| `071`+ | Open | Next available sequential SECAUDIT number |

## Unnumbered audits (outside the numbered sequence)

Four historical audits in `secaudits/` carry no sequence number; why is not recorded:
- `2026-06-05-0.7.209-review.pdf`
- `2026-06-15-mesh-roster-key-broadcast.pdf`
- `2026-06-25-son-of-a-batch.pdf`
- `2026-06-27-csrf-closure-sweep.pdf`

<!-- ===== SNAPSMACK EOF ===== -->
