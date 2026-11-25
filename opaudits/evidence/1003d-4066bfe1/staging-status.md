# Staging acceptance for 1003D

Candidate `4066bfe17e5cd7ff82798eaada214f03b4890c96`. Brain Trust 7656 approved the disposable SnapSmack installation as the staging site.

| Acceptance | Status | Capture or measurement |
| --- | --- | --- |
| Fresh installer creates tables and serves its first page | PASS | [Installer](1003d-fresh-install.json) |
| Portfolio masonry and single-column layouts preserve image proportions | PASS | [Masonry](1003d-fixed-masonry.png), [one column](1003d-fixed-one-column.png) |
| Stored mosaic weight reflows the intended photo and keeps order | PASS | [Desktop result](coldsnap-desktop-after.json) |
| NEW HORIZON offers a calendar without masonry and saves its calendar setting | PASS | [Settings and archive](1003d-new-horizon-staging.json), [open calendar](1003d-new-horizon-calendar.png) |
| TRUE GRIT retains source proportions at the supported desktop widths | PASS | [Archive measurements](1003d-true-grit-staging.json) |
| CHAPLIN and GALLERIA centre different image heights in their row | PASS | [CHAPLIN](1003d-chaplin-staging.json), [GALLERIA](1003d-galleria-staging.json) |
| ALFRED portfolio skin and authored gallery render at the three desktop widths | PASS | [ALFRED](1003d-alfred-staging.json) |
| The emoji picker and write endpoint are retired while hearts still save | PASS | [All 30 installed skin responses in both legacy toggle states](1003d-reaction-matrix.json), [GLIDE save/reload, HTTP 410 and preserved row](1003d-community-settings.json) |
| Submitting a category through the shared archive filter panel narrows the result | FAIL in 1002D and 1003D | [1002D](stage-filter-d2846b66.json), [1003D](stage-filter-4066bfe1.json). SS-1580. |

The 1003D tag is pending a PM decision on SS-1580. The baseline and installed staging measurements above do not represent a package publication or a fleet-after run.

<!-- ===== SNAPSMACK EOF ===== -->
