<!-- SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below. -->
# SS-1051 — CHAPLIN archive row geometry

This is source-candidate evidence, not installed-build or owner-acceptance
evidence. The browser loaded the deployed CHAPLIN archive markup from
`https://acolourlesslife.ca/archive-view` while serving the stylesheet from
this worktree. No live files were changed.

`tools/browser-tests/chaplin-archive-row-geometry.cjs` measured the first four
CSS Grid rows at 1920, 1440 and 1024 pixels. It records every tile rectangle,
the computed column count, alignment and declared row gap.

## Before

- Layout engine: CSS Grid.
- Columns: 4 at 1920 and 1440; 3 at 1024.
- Computed alignment: `align-items: center`.
- Computed row gap: 8px.
- Row top spread at 1920 and 1440: 40.22px, 0px, 8.75px, 28.53px.
- Row top spread at 1024: 40.22px, 0px, 0.02px, 8.75px.
- The outside visual gap from one row's lowest tile to the next row's highest
  tile was 8px in every measured case.

## Rejected candidate

For comparison, a temporary candidate changed only `.fsog-archive-grid` from
`align-items: center` to `align-items: start`.

- Row top spread: 0px for all four rows at all three widths.
- Outside visual row gap: 8px for all row transitions at all three widths.
- Column count, natural thumbnail dimensions and archive markup are unchanged.

The `before/` and `after/` directories contain full-page captures and raw
measurements. The candidate was reverted before commit because it contradicts
the saved owner reference.

## Reference conflict

The ticket acceptance explicitly requires shared row-top coordinates. Sean's
July owner reference deliberately shows staggered natural-aspect tiles, and
the current repository instructions record CHAPLIN as fixed and shipping.
The measured row gaps are already constant at 8px; the unequal top coordinates
come from centering different-height natural-aspect tiles within each grid
track. No CHAPLIN source change is included. Nothing here is a live deployment.

<!-- ===== SNAPSMACK EOF ===== -->
