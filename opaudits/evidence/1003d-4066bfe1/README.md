# 1003D evidence for 4066bfe1

The tested CMS commit is `4066bfe17e5cd7ff82798eaada214f03b4890c96`. Its immediate parent is `79193b80169693b54792f705354914c55d054ddd`. The published comparison version is `v0.7.1002D`. This branch carries evidence only. The release gate has not been signed.

| Check | Result | Evidence |
| --- | --- | --- |
| Required PHP scripts | 492 scripts ended without a nonzero exit. Two environment-dependent probes explicitly skipped. | [Script results](php-results.txt) |
| Public fleet before capture | 102 surfaces across 17 sites, covering home, archive, and solo at 1920 and 1024. The sites ran their existing live versions. | [Manifest](fleet-before-manifest.json) |
| Rendering versus the correction's parent | 344 fixture screenshots unchanged, no pixel differences or errors. The 114 warnings have the same missing fixture image in both captures. | [Report](portfolio-correction-pixels.json) |
| Rendering versus 1002D | 318 fixture screenshots unchanged, 26 changed and none errored. The report records unchanged pairs as well as changes. | [Report](1002d-to-1003d-release-pixels.json), [sample before/after pairs](1002d-to-1003d-skin-pairs.png) |
| Installed portfolio gallery | On a disposable HTTP SnapSmack installation, the strict page rendered three native-aspect columns with 10px gaps and a separate one-column gallery at 800px. | [Masonry](1003d-fixed-masonry.png), [one column](1003d-fixed-one-column.png) |
| Installed weighted mosaic | On the disposable HTTP site, a stored weight made the same first image 800 × 533px at a 1440px viewport, up from 317 × 211px. The other three tiles were 264 × 176px. The photo order was identical. | [Before](coldsnap-desktop-before.json), [after](coldsnap-desktop-after.json), [after image](coldsnap-desktop-after.png) |

Twenty skin folders changed. Browser fixture differences are visible for ALFRED, CHAPLIN, GALLERIA, NEW HORIZON, ONYX and TRUE GRIT. These differences are available for review. The ALFRED landing fixture shows a placeholder page on both commits. Its fixture is evidence for the framing of that fallback page.

The fleet capture documents the public baseline. The disposable installation provides acceptance for portfolio layout and weighted rendering. Release-wide feature acceptance on a running site, including settings save and reload, still needs a completed release parity record. Brain Trust 7647 is the correction's conditional review. The package, live installation and after-deploy sweep are pending.

<!-- ===== SNAPSMACK EOF ===== -->
