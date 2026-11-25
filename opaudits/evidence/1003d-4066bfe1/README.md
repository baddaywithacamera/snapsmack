# 1003D evidence for 4066bfe1

The tested CMS commit is `4066bfe17e5cd7ff82798eaada214f03b4890c96`. Its immediate parent is `79193b80169693b54792f705354914c55d054ddd`. The published comparison version is `v0.7.1002D`. This branch carries evidence only. The release gate has not been signed.

| Check | Result | Evidence |
| --- | --- | --- |
| Required PHP scripts | 492 scripts ended without a nonzero exit. Two environment-dependent probes explicitly skipped. | [Script results](php-results.txt) |
| Required browser and telemetry suites | Passed when rerun in the clean dev checkout at the tested SHA. | [Runner output](browser-regressions.txt) |
| Public fleet before capture | 102 surfaces across 17 sites, covering home, archive, and solo at 1920 and 1024. The sites ran their existing live versions. | [Manifest](fleet-before-manifest.json) |
| Rendering versus the correction's parent | 344 fixture screenshots unchanged, no pixel differences or errors. The 114 warnings have the same missing fixture image in both captures. | [Report](portfolio-correction-pixels.json) |
| Rendering versus 1002D | 318 fixture screenshots unchanged, 26 changed and none errored. The report records unchanged pairs as well as changes. | [Report](1002d-to-1003d-release-pixels.json), [sample before/after pairs](1002d-to-1003d-skin-pairs.png) |
| Installed portfolio gallery | On a disposable HTTP SnapSmack installation, the strict page rendered three native-aspect columns with 10px gaps and a separate one-column gallery at 800px. | [Masonry](1003d-fixed-masonry.png), [one column](1003d-fixed-one-column.png) |
| Installed weighted mosaic | On the disposable HTTP site, a stored weight made the same first image 800 × 533px at a 1440px viewport, up from 317 × 211px. The other three tiles were 264 × 176px. The photo order was identical. | [Before](coldsnap-desktop-before.json), [after](coldsnap-desktop-after.json), [after image](coldsnap-desktop-after.png) |

Twenty skin folders changed. Browser fixture differences are visible for ALFRED, CHAPLIN, GALLERIA, NEW HORIZON, ONYX and TRUE GRIT. These differences are available for review. The ALFRED landing fixture shows a placeholder page on both commits. Its fixture is evidence for the framing of that fallback page.

## Staging click-through

Brain Trust 7656 approved the disposable HTTP installation as the staging target. The exact 1003D installer created a new database, completed setup and served its first page with HTTP 200. [Install capture](1003d-fresh-install-complete.png), [result](1003d-fresh-install.json).

The staging database used synthetic photos. TRUE GRIT preserved 4:3, 3:2 and intermediate image proportions at 1920, 1440 and 1024 pixels. CHAPLIN and GALLERIA centred mixed-height images in their rows. ALFRED drew its three-column portfolio with natural image proportions. [TRUE GRIT](1003d-true-grit-staging.json), [CHAPLIN](1003d-chaplin-staging.json), [GALLERIA](1003d-galleria-staging.json), [ALFRED](1003d-alfred-staging.json).

NEW HORIZON was installed using the ordinary signature and integrity checks against a test-only key. The calendar saved and reloaded off and on. The public calendar toggled open and closed, and the archive offered no masonry control. [Calendar](1003d-new-horizon-calendar.png), [measurements](1003d-new-horizon-staging.json).

Community settings saved and reloaded in both states. GLIDE showed ten hearts when enabled and zero when disabled. A clicked heart persisted across a reload. The retired reaction route returned 410 and a stored historical reaction row remained unchanged. [Measurements](1003d-community-settings.json).

**Open SS-1580.** Selecting the only category in the TRUE GRIT filter panel submitted `f[]=cat:1` and left all ten photos visible. The legacy `cat=1` query showed one, and Show All restored ten. Source inspection found the same missing `f[]` handoff in 1002D. [Panel output](1003d-archive-filter-panel-result.png), [legacy query output](1003d-archive-filter-legacy-result.png). This release gate remains unsigned while the PM decides how to handle the failed panel path.

Brain Trust 7647 is the portfolio correction's conditional review. The release package, live installation and fleet-after sweep are pending.

<!-- ===== SNAPSMACK EOF ===== -->
